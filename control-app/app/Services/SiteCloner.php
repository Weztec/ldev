<?php

namespace App\Services;

use App\Models\Site;
use App\Models\RepositoryToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class SiteCloner
{

    public function clone(
        Site $source,
        string $newName,
        bool $copyData = true,
        bool $createRepo = false,
        ?RepositoryToken $repoToken = null,
        string $repoVisibility = 'private'
    ): array {
        if (!Site::isValidName($newName)) {
            throw new \InvalidArgumentException('Project name must be lowercase alphanumeric with hyphens only.');
        }

        if (Site::where('name', $newName)->exists()) {
            throw new \InvalidArgumentException("A site named '{$newName}' already exists.");
        }

        $sourcePath = $source->projectRoot();
        $targetPath = config('ldev.sites_path') . '/' . $newName;

        if (File::exists($targetPath)) {
            throw new \InvalidArgumentException("{$targetPath} already exists.");
        }

        File::ensureDirectoryExists($targetPath);

        Process::run('cp -a ' . escapeshellarg($sourcePath . '/.') . ' ' . escapeshellarg($targetPath . '/'))->throw();

        File::deleteDirectory($targetPath . '/.git');
        (new GitInitializer)->initIfNeeded($targetPath);

        (new EnvFileWriter)->update($targetPath, ['APP_URL' => "https://{$newName}.test"]);

        if ($source->databaseType() === null) {
            Process::inProject($targetPath)->run('php artisan key:generate --force');
        }

        Process::inProject($targetPath)->run('php artisan storage:link --force');

        $documentRoot = File::isDirectory($targetPath . '/public') ? $targetPath . '/public' : $targetPath;

        $target = Site::create([
            'name' => $newName,
            'domain' => "{$newName}.test",
            'document_root' => $documentRoot,
            'php_version' => $source->php_version,
            'node_version' => $source->node_version,
            'queue_workers' => $source->queue_workers,
            'queue_sleep' => $source->queue_sleep,
            'queue_tries' => $source->queue_tries,
            'queue_max_time' => $source->queue_max_time,
            'is_linked' => true,
        ]);

        foreach ($source->processes()->get() as $job) {
            $target->processes()->create(array_merge(
                $job->only(['name', 'command', 'schedule', 'numprocs', 'stopwaitsecs', 'autostart', 'autorestart']),
                ['enabled' => false]
            ));
        }

        $driver = $source->databaseType();
        if ($driver !== null) {
            if ($copyData) {

                (new DatabaseProvisioner)->provision($targetPath, $driver, $newName);

                $backups = new DatabaseBackupManager;
                $snapshot = $backups->backup($source);

                $targetBackupDir = config('ldev.backups_dir') . '/' . $newName;
                File::ensureDirectoryExists($targetBackupDir);
                $copiedSnapshot = $targetBackupDir . '/' . basename($snapshot);
                File::copy($snapshot, $copiedSnapshot);

                $backups->restore($target, basename($copiedSnapshot));
            } else {

                if ($driver === 'sqlite') {
                    File::delete($targetPath . '/database/database.sqlite');
                }

                (new EnvFileWriter)->update($targetPath, $this->readDbEnvValues($sourcePath));
            }
        }

        (new NginxConfigGenerator)->generate($target);
        (new SupervisorConfigGenerator)->generate($target);

        $repoWarning = null;
        if ($createRepo && $repoToken) {
            try {
                (new RemoteRepositoryProvisioner)->createAndPush(
                    $targetPath,
                    $newName,
                    $repoToken,
                    $repoVisibility !== 'public'
                );
            } catch (\Throwable $e) {
                $repoWarning = "Clone created, but pushing it to a new repository failed: " . $e->getMessage();
            }
        }

        return [$target, $repoWarning];
    }

    protected function readDbEnvValues(string $projectPath): array
    {
        $envPath = $projectPath . '/.env';
        if (!File::exists($envPath)) {
            return [];
        }

        $env = File::get($envPath);
        $values = [];

        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $m)) {
                $values[$key] = trim($m[1]);
            }
        }

        return $values;
    }
}
