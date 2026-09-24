<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\File;
use App\Models\Site;
use App\Models\Setting;
use App\Models\RepositoryToken;
use App\Services\ProjectSetupPipeline;
use App\Services\DatabaseProvisioner;
use App\Services\S3Provisioner;
use App\Services\ReverbProvisioner;
use App\Services\RepositoryCloneProvisioner;
use App\Services\RemoteRepositoryProvisioner;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;
use App\Services\GitInitializer;
use App\Services\ProjectFeatureDetector;
use App\Services\ComposerAuthWriter;
use App\Services\NodeVersionManager;
use App\Services\EnvFileWriter;

class LdevNew extends Command
{

    protected const KNOWN_PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    protected $signature = 'ldev:new {name}
        {--breeze : Install Laravel Breeze}
        {--jetstream : Install Laravel Jetstream}
        {--stack= : Starter kit stack (blade|livewire|react|vue)}
        {--dark : Include dark mode support (Breeze only)}
        {--teams : Include team support (Jetstream only)}
        {--pest : Use Pest instead of PHPUnit}
        {--db=sqlite : Database driver (sqlite|mysql|pgsql|none — none skips Linux Dev\'s own database provisioning entirely, for a plain static site/non-Laravel project that has no business getting an empty database created for it)}
        {--db-name= : mysql/pgsql only — name for the NEW database to create (defaults to the project name)}
        {--db-existing= : mysql/pgsql only — use this database that already exists on the server instead of creating one (e.g. a cloned project whose database survived)}
        {--php= : PHP version for this site\'s Nginx/PHP-FPM pool (defaults to the configured default)}
        {--node= : Node.js version for this project, via nvm (defaults to the configured default; ignored if the project has no package.json)}
        {--repo= : Clone this repository instead of scaffolding a new Laravel project (--breeze/--jetstream are ignored)}
        {--token-id= : RepositoryToken id to authenticate the --repo clone with, if it is private}
        {--create-repo : Create a brand-new repository on the chosen provider and push the initial scaffold to it (scaffold path only, ignored with --repo)}
        {--repo-token-id= : RepositoryToken id to create the --create-repo repository with}
        {--repo-visibility=private : Visibility for the --create-repo repository (private|public)}
        {--s3 : Provision a local MinIO bucket for file/image storage}
        {--reverb : Install Laravel Reverb (WebSocket broadcasting) and supervise it}';
    protected $description = 'Scaffold a new Laravel project (or clone a repository) and link it to a *.test domain';

    public function handle()
    {
        $name = $this->argument('name');

        if (!Site::isValidName($name)) {
            $this->error('Project name must be lowercase alphanumeric with hyphens only.');
            return 1;
        }

        $path = config('ldev.sites_path') . '/' . $name;

        if (File::exists($path)) {
            $this->error("$path already exists — remove it (or pick a different name) before retrying. This is usually a leftover from an earlier ldev:new attempt for this name that failed partway through.");
            return 1;
        }

        $repoUrl = $this->option('repo');

        if ($repoUrl) {
            $this->info("Cloning $repoUrl to $path");

            $token = null;
            if ($tokenId = $this->option('token-id')) {
                $token = RepositoryToken::find($tokenId);
            }

            (new RepositoryCloneProvisioner)->clone($repoUrl, $path, $token);

            (new EnvFileWriter)->ensureExists($path);

        } else {
            $this->info("Creating Laravel project at $path");

            $result = Process::inProject(config('ldev.sites_path'))->run('composer create-project laravel/laravel ' . escapeshellarg($name));
            if ($result->failed()) {
                $host = (new ComposerAuthWriter)->extractAuthFailureHost($result->errorOutput());
                $this->error($host
                    ? "$host requires Composer credentials Linux Dev doesn't have. Add them on the Settings page under \"Composer credentials\", then retry."
                    : 'composer create-project failed: ' . $result->errorOutput());
                return 1;
            }

            if ($this->option('breeze')) {
                $stack = $this->option('stack') ?? 'blade';
                if (!in_array($stack, ['blade', 'livewire', 'react', 'vue'], true)) {
                    $this->error("Invalid Breeze stack '$stack' — must be one of: blade, livewire, react, vue.");
                    return 1;
                }
                Process::inProject($path)->run('composer require laravel/breeze --dev');
                $flags = array_filter([
                    $this->option('dark') ? '--dark' : null,
                    $this->option('pest') ? '--pest' : null,
                ]);
                Process::inProject($path)->run(trim("php artisan breeze:install $stack " . implode(' ', $flags)));
            }
            if ($this->option('jetstream')) {
                $stack = $this->option('stack') ?? 'livewire';
                if (!in_array($stack, ['livewire', 'inertia'], true)) {
                    $this->error("Invalid Jetstream stack '$stack' — must be one of: livewire, inertia.");
                    return 1;
                }
                Process::inProject($path)->run('composer require laravel/jetstream');
                $flags = array_filter([
                    $this->option('teams') ? '--teams' : null,
                    $this->option('pest') ? '--pest' : null,
                ]);
                Process::inProject($path)->run(trim("php artisan jetstream:install $stack " . implode(' ', $flags)));
            }

            if ($this->option('pest') && !$this->option('breeze') && !$this->option('jetstream')) {
                $this->info('Setting up Pest');
                foreach ([
                    'composer remove phpunit/phpunit --dev --no-update',
                    'composer require pestphp/pest pestphp/pest-plugin-laravel --no-update --dev',
                    'composer update',
                    'php ./vendor/bin/pest --init',
                    'composer require pestphp/pest-plugin-drift --dev',
                    'php ./vendor/bin/pest --drift',
                    'composer remove pestphp/pest-plugin-drift --dev',
                ] as $command) {
                    $step = Process::inProject($path)->timeout(600)->run('PAO_DISABLE=true PEST_NO_SUPPORT=true ' . $command);
                    if ($step->failed()) {
                        $this->warn("Pest setup stopped at \"$command\": " . trim($step->errorOutput() ?: $step->output()));
                        break;
                    }
                }
            }

            (new GitInitializer)->initIfNeeded($path);

            if ($this->option('create-repo')) {
                $token = ($tokenId = $this->option('repo-token-id')) ? RepositoryToken::find($tokenId) : null;
                if (!$token) {
                    $this->warn('--create-repo given but --repo-token-id is missing or invalid — skipping repository creation.');
                } else {
                    $private = ($this->option('repo-visibility') ?? 'private') !== 'public';
                    try {
                        $cloneUrl = (new RemoteRepositoryProvisioner)->createAndPush($path, $name, $token, $private);
                        $this->info("Repository created and pushed: $cloneUrl");
                    } catch (\Throwable $e) {
                        $this->warn("Repository creation failed, continuing without it: " . $e->getMessage());
                    }
                }
            }
        }

        (new EnvFileWriter)->update($path, ['APP_URL' => "https://{$name}.test"]);

        $dbType = $this->option('db') ?? 'sqlite';
        $dbExisting = $this->option('db-existing') ?: null;
        $dbName = $this->option('db-name') ?: $name;
        if (($dbExisting || $this->option('db-name')) && !in_array($dbType, ['mysql', 'pgsql'], true)) {
            $this->error('--db-name/--db-existing only apply with --db=mysql or --db=pgsql.');
            return 1;
        }
        if ($dbType !== 'none') {
            $this->configureDatabase($path, $dbType, $dbName, $dbExisting);
        }

        if ($this->option('s3')) {
            (new S3Provisioner)->provision($path, $name);
        }

        try {
            (new ProjectSetupPipeline)->run($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->call('ldev:link', ['name' => $name, 'path' => $path]);

        $knownVersions = self::KNOWN_PHP_VERSIONS;
        $latestVersion = end($knownVersions);
        $phpVersion = $this->option('php') ?: Setting::get('default_php_version', $latestVersion);
        if (!in_array($phpVersion, self::KNOWN_PHP_VERSIONS, true)) {
            $phpVersion = $latestVersion;
        }
        $site = Site::where('name', $name)->firstOrFail();
        if ($site->php_version !== $phpVersion) {
            $site->update(['php_version' => $phpVersion]);
            (new NginxConfigGenerator)->generate($site);
        }

        if (File::exists($path . '/package.json')) {
            $knownNodeVersions = NodeVersionManager::KNOWN_VERSIONS;
            $latestNodeVersion = end($knownNodeVersions);
            $nodeVersion = $this->option('node') ?: Setting::get('default_node_version', $latestNodeVersion);
            if (!in_array($nodeVersion, NodeVersionManager::KNOWN_VERSIONS, true)) {
                $nodeVersion = $latestNodeVersion;
            }

            (new NodeVersionManager)->npmInstallAndBuild($path, $nodeVersion);
            $site->update(['node_version' => $nodeVersion]);
        }

        if ($this->option('reverb')) {
            $site->update(['reverb_enabled' => true]);
            (new ReverbProvisioner)->provision($path, $site);
            (new NginxConfigGenerator)->generate($site);
            (new SupervisorConfigGenerator)->generate($site);
        }

        if ($repoUrl) {
            $applied = (new ProjectFeatureDetector)->detectAndApply($site, $path);
            if ($applied) {
                $this->info('Auto-enabled from composer.json/.env: ' . implode(', ', $applied));
                (new NginxConfigGenerator)->generate($site);
                (new SupervisorConfigGenerator)->generate($site);
            }
        }

        $this->info("Project $name ready at https://$name.test");
        return 0;
    }

    protected function configureDatabase(string $path, string $dbType, string $name, ?string $existing = null): void
    {
        (new DatabaseProvisioner)->provision($path, $dbType, $name, $existing);
    }
}
