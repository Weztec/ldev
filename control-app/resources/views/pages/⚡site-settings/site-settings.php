<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\Site;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;
use App\Services\ReverbProvisioner;
use App\Services\DatabaseProvisioner;
use App\Services\DatabaseBackupManager;
use App\Services\NodeVersionManager;
use App\Services\HostsFileManager;
use App\Services\ProjectManifest;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

new class extends Component {
    use WithFileUploads;

    public Site $site;

    public $phpVersion;
    public $nodeVersion;
    public $activeNodeVersion;
    public $dbDriver;
    public $dbSwitchError = null;
    public $pendingDbDriver = null;
    public $runMigrationsOnSwitch = true;
    public $runSeedOnSwitch = false;

    public $dbTarget = 'new';
    public $newDbName = '';
    public $existingDbName = '';
    public $existingDatabases = [];
    public $existingDbError = null;
    public $nodeSwitchError = null;
    public $hostsEntries = ['automatic' => [], 'manual' => []];
    public $newHostname = '';
    public $hostsError = null;
    public $queueWorkers;
    public $queueSleep;
    public $queueTries;
    public $queueMaxTime;
    public $queueNames;
    public $queueSettingsError = null;
    public $queueSettingsSaved = false;
    public $backups = [];
    public $backupError = null;
    public $backupNote = null;
    public $importDriver;
    public $importFile = null;
    public $phpFpmRestartNote = null;
    public $envContent = '';
    public $envSaved = false;
    public $envError = null;
    public $hasEnvBackup = false;
    public $envEditorTarget = 'env';
    public $projectComposerCredentials = [];
    public $globalComposerCredentials = [];
    public $projectComposerShowForm = false;
    public $projectComposerEditingId = null;
    public $projectComposerHost = '';
    public $projectComposerUsername = '';
    public $projectComposerSecret = '';
    public $projectComposerNote = null;
    public $projectComposerError = null;
    public $envEditorTabs = [];
    public $manifestExists = false;
    public $manifestDiffs = [];
    public $manifestWarnings = [];
    public $manifestRequirements = [];
    public $manifestVersions = [];
    public $manifestNote = null;
    public $manifestError = null;

    protected array $knownPhpVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function mount(Site $site)
    {
        $this->site = $site;
        $this->phpVersion = $site->php_version;

        $this->nodeVersion = $site->node_version ?? Setting::get('default_node_version', $this->latestKnownNodeVersion());
        $this->refreshActiveNodeVersion();

        $this->dbDriver = $site->databaseType() ?? 'none';
        $this->queueWorkers = $site->queue_workers;
        $this->queueSleep = $site->queue_sleep;
        $this->queueTries = $site->queue_tries;
        $this->queueMaxTime = $site->queue_max_time;
        $this->queueNames = $site->queue_names;
        $this->importDriver = $this->dbDriver;
        $this->refreshBackups();
        $this->refreshHosts();
        $this->loadEnvContent();
        $this->loadProjectComposer();
        $this->loadManifest();
    }

    public function loadManifest()
    {
        $service = new ProjectManifest;
        $manifest = $service->read($this->site->projectRoot());
        $this->manifestExists = $service->exists($this->site->projectRoot());
        $this->manifestWarnings = $service->warnings;
        $this->manifestDiffs = $manifest ? $service->differences($this->site, $manifest) : [];
        $this->manifestRequirements = $service->checkRequirements($manifest['requires'] ?? []);
        $this->manifestVersions = array_intersect_key($manifest ?? [], ['php' => true, 'node' => true]);
    }

    public function keepVersionForEveryone(string $key)
    {
        if (!in_array($key, ['php', 'node'], true)) {
            return;
        }

        $version = $key === 'php' ? $this->site->php_version : $this->site->node_version;
        if (!$version) {
            return;
        }

        $this->manifestNote = null;
        $this->manifestError = null;
        try {
            (new ProjectManifest)->setValues($this->site->projectRoot(), [$key => $version]);
        } catch (\Throwable $e) {
            $this->manifestError = $e->getMessage();
            return;
        }

        $this->loadManifest();
        $this->loadEnvContent();
        $this->manifestNote = ProjectManifest::FILENAME . ' now says ' . ($key === 'php' ? 'PHP' : 'Node') . " {$version}. Commit it so the team gets it.";
    }

    public function switchBackToManifestVersion(string $key)
    {
        $version = $this->manifestVersions[$key] ?? null;
        if (!in_array($key, ['php', 'node'], true) || !$version) {
            return;
        }

        if ($key === 'php') {
            $this->phpVersion = $version;
            $this->updatedPhpVersion($version);
        } else {
            $this->nodeVersion = $version;
            $this->updatedNodeVersion($version);
        }
    }

    public function applyManifest()
    {
        $this->manifestNote = null;
        $this->manifestError = null;

        $service = new ProjectManifest;
        $manifest = $service->read($this->site->projectRoot());
        if ($manifest === null) {
            $this->manifestError = 'There is no valid ' . ProjectManifest::FILENAME . ' in this project.';
            return;
        }

        [$applied, $warnings] = $service->apply($this->site, array_diff_key($manifest, ['database' => true]));
        $this->mount($this->site->fresh());
        $this->manifestNote = $applied ? 'Applied from ' . ProjectManifest::FILENAME . '.' : 'Nothing to change.';
        $this->manifestError = $warnings ? implode(' ', $warnings) : null;
    }

    public function saveManifest()
    {
        $this->manifestNote = null;
        $this->manifestError = null;

        try {
            (new ProjectManifest)->write($this->site);
        } catch (\Throwable $e) {
            $this->manifestError = 'Could not write ' . ProjectManifest::FILENAME . ': ' . $e->getMessage();
            return;
        }

        $this->loadManifest();
        $this->loadEnvContent();
        $this->manifestNote = 'Saved to ' . ProjectManifest::FILENAME . '. Commit it so everyone gets the same setup.';
    }

    protected function latestKnownNodeVersion(): string
    {
        $versions = NodeVersionManager::KNOWN_VERSIONS;
        return end($versions);
    }

    public function loadProjectComposer()
    {
        $this->projectComposerCredentials = \App\Models\ComposerCredential::forSite($this->site)->orderBy('host')->get(['id', 'host', 'username'])->toArray();
        $this->globalComposerCredentials = \App\Models\ComposerCredential::global()->orderBy('host')->get(['id', 'host', 'username'])->toArray();
    }

    public function startProjectComposerCredential(?string $host = null)
    {
        $global = $host ? \App\Models\ComposerCredential::global()->where('host', $host)->first() : null;
        $this->projectComposerEditingId = null;
        $this->projectComposerHost = $global?->host ?? '';
        $this->projectComposerUsername = $global?->username ?? '';
        $this->projectComposerSecret = '';
        $this->projectComposerError = null;
        $this->projectComposerShowForm = true;
    }

    public function editProjectComposerCredential(int $id)
    {
        $cred = \App\Models\ComposerCredential::forSite($this->site)->find($id);
        if (!$cred) {
            return;
        }
        $this->projectComposerEditingId = $cred->id;
        $this->projectComposerHost = $cred->host;
        $this->projectComposerUsername = $cred->username;
        $this->projectComposerSecret = '';
        $this->projectComposerError = null;
        $this->projectComposerShowForm = true;
    }

    public function cancelProjectComposerCredential()
    {
        $this->projectComposerShowForm = false;
    }

    public function saveProjectComposerCredential()
    {
        $this->validate([
            'projectComposerHost' => [
                'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+(:\d+)?$/',
                \Illuminate\Validation\Rule::unique('composer_credentials', 'host')->where('site_id', $this->site->id)->ignore($this->projectComposerEditingId),
            ],
            'projectComposerUsername' => ['required', 'string', 'max:255'],
            'projectComposerSecret' => $this->projectComposerEditingId ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
        ], [], ['projectComposerHost' => 'host', 'projectComposerUsername' => 'username', 'projectComposerSecret' => 'secret']);

        $writer = new \App\Services\ProjectComposerAuthWriter;
        if ($writer->trackedByGit($this->site)) {
            $this->projectComposerError = 'This project\'s auth.json is tracked by git, so credentials written to it would be committed. Remove it from git first (git rm --cached auth.json), then save again.';
            return;
        }

        $data = ['site_id' => $this->site->id, 'host' => $this->projectComposerHost, 'username' => $this->projectComposerUsername];
        if ($this->projectComposerSecret !== '') {
            $data['secret'] = $this->projectComposerSecret;
        }

        $removeHosts = [];
        if ($this->projectComposerEditingId) {
            $cred = \App\Models\ComposerCredential::forSite($this->site)->find($this->projectComposerEditingId);
            if (!$cred) {
                return;
            }
            if ($cred->host !== $this->projectComposerHost) {
                $removeHosts[] = $cred->host;
            }
            $cred->update($data);
        } else {
            \App\Models\ComposerCredential::create($data);
        }

        $this->writeProjectComposer($removeHosts);
        $this->projectComposerShowForm = false;
        $this->projectComposerSecret = '';
    }

    public function deleteProjectComposerCredential(int $id)
    {
        $cred = \App\Models\ComposerCredential::forSite($this->site)->find($id);
        if (!$cred) {
            return;
        }
        $host = $cred->host;
        $cred->delete();
        $this->writeProjectComposer([$host]);
    }

    protected function writeProjectComposer(array $removeHosts): void
    {
        $this->projectComposerError = null;
        try {
            $note = (new \App\Services\ProjectComposerAuthWriter)->sync($this->site, $removeHosts);
            $this->projectComposerNote = trim('Saved to the project\'s auth.json. ' . ($note ?? ''));
        } catch (\Throwable $e) {
            $this->projectComposerError = $e->getMessage();
        }
        $this->loadProjectComposer();
    }

    public function loadEnvContent()
    {
        $path = $this->currentEnvPath();
        $this->envContent = File::exists($path) ? File::get($path) : '';
        $this->hasEnvBackup = File::exists($this->envPath() . '.backup');
        $this->envEditorTabs = collect($this->editorFiles())
            ->filter(fn ($file) => $file['always'] || File::exists($file['path']))
            ->map(fn ($file) => $file['label'])
            ->all();
    }

    protected function editorFiles(): array
    {
        $root = $this->site->projectRoot();

        return [
            'env' => ['label' => '.env', 'path' => $root . '/.env', 'always' => true],
            'backup' => ['label' => '.env.backup', 'path' => $root . '/.env.backup', 'always' => false],
            'example' => ['label' => '.env.example', 'path' => $root . '/.env.example', 'always' => false],
            'testing' => ['label' => '.env.testing', 'path' => $root . '/.env.testing', 'always' => false],
            'phpunit' => ['label' => 'phpunit.xml', 'path' => $root . '/phpunit.xml', 'always' => false],
            'phpunit_dist' => ['label' => 'phpunit.xml.dist', 'path' => $root . '/phpunit.xml.dist', 'always' => false],
            'manifest' => ['label' => 'ldev.json', 'path' => $root . '/' . ProjectManifest::FILENAME, 'always' => false],
            'gitignore' => ['label' => '.gitignore', 'path' => $root . '/.gitignore', 'always' => true],
        ];
    }

    public function switchEnvTarget($target)
    {
        $file = $this->editorFiles()[$target] ?? null;
        if (!$file || (!$file['always'] && !File::exists($file['path']))) {
            return;
        }

        $this->envEditorTarget = $target;
        $this->loadEnvContent();
    }

    public function saveEnvContent()
    {
        $this->envSaved = false;
        $this->envError = null;
        $path = $this->currentEnvPath();

        if (in_array($this->envEditorTarget, ['phpunit', 'phpunit_dist'], true)) {
            $previous = libxml_use_internal_errors(true);
            $valid = simplexml_load_string($this->envContent) !== false;
            $xmlError = libxml_get_last_error();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$valid) {
                $this->envError = 'Not saved: the XML is invalid' . ($xmlError ? ' (line ' . $xmlError->line . ': ' . trim($xmlError->message) . ')' : '') . '. An invalid phpunit.xml stops every test run.';
                return;
            }
        }

        if ($this->envEditorTarget === 'manifest') {
            $decoded = json_decode($this->envContent, true);
            if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                $this->envError = 'Not saved: ldev.json must be a valid JSON object' . (json_last_error() ? ' (' . json_last_error_msg() . ')' : '') . '.';
                return;
            }
        }

        try {
            if ($this->envEditorTarget === 'env' && File::exists($path)) {
                File::copy($path, $this->envPath() . '.backup');
            }
            File::put($path, $this->envContent);
            $this->hasEnvBackup = File::exists($this->envPath() . '.backup');
            $this->envSaved = true;
            if ($this->envEditorTarget === 'manifest') {
                $this->loadManifest();
            }
        } catch (\Throwable $e) {
            $this->envError = $e->getMessage();
        }
    }

    public function restorePreviousEnv()
    {
        $path = $this->envPath();
        $backup = $path . '.backup';

        if (File::exists($backup)) {
            File::copy($backup, $path);
            $this->loadEnvContent();
        }
    }

    protected function currentEnvPath(): string
    {
        return $this->editorFiles()[$this->envEditorTarget]['path'] ?? $this->envPath();
    }

    protected function envPath(): string
    {
        return $this->site->projectRoot() . '/.env';
    }

    public function refreshHosts()
    {
        $this->hostsEntries = (new HostsFileManager)->forSite($this->site);
    }

    public function addHost()
    {
        $this->hostsError = null;
        $hostname = trim($this->newHostname);
        $manager = new HostsFileManager;

        if (!$manager->isValidSubdomainFor($this->site, $hostname)) {
            $example = $this->site->subdomainWildcardName() ?? 'subdomain';
            $this->hostsError = "'{$hostname}' must be a subdomain of {$this->site->name}.test (e.g. {$example}.{$this->site->name}.test).";
            return;
        }

        try {
            $manager->add($hostname);
            $this->newHostname = '';
        } catch (\Throwable $e) {
            $this->hostsError = $e->getMessage();
        }

        $this->refreshHosts();
    }

    public function removeHost(string $hostname)
    {
        $this->hostsError = null;
        $manager = new HostsFileManager;

        if (!$manager->isValidSubdomainFor($this->site, $hostname)) {
            return;
        }

        try {
            $manager->remove($hostname);
        } catch (\Throwable $e) {
            $this->hostsError = $e->getMessage();
        }

        $this->refreshHosts();
    }

    public function saveQueueSettings()
    {
        $this->queueSettingsError = null;
        $this->queueSettingsSaved = false;

        $validated = collect([
            'queue_workers' => [$this->queueWorkers, 1, 20],
            'queue_sleep' => [$this->queueSleep, 0, 60],
            'queue_tries' => [$this->queueTries, 1, 20],
            'queue_max_time' => [$this->queueMaxTime, 60, 86400],
        ])->map(function ($bounds, $field) {
            [$value, $min, $max] = $bounds;
            if (!is_numeric($value) || $value < $min || $value > $max) {
                throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}.");
            }
            return (int) $value;
        });

        $queueNames = trim($this->queueNames ?: 'default');
        if (!preg_match('/^[a-zA-Z0-9_,-]+$/', $queueNames)) {
            $this->queueSettingsError = 'Queues must be a comma-separated list of letters, digits, hyphens, and underscores (e.g. "xero,quickbooks,default").';
            return;
        }
        $validated['queue_names'] = $queueNames;

        try {
            $this->site->update($validated->all());
        } catch (\InvalidArgumentException $e) {
            $this->queueSettingsError = $e->getMessage();
            return;
        }

        if ($this->site->queue_worker_enabled) {
            (new SupervisorConfigGenerator)->generate($this->site);
        }

        $this->queueSettingsSaved = true;
    }

    public function refreshActiveNodeVersion()
    {
        $this->activeNodeVersion = (new NodeVersionManager)->activeVersion($this->site->node_version);
    }

    public function updatedPhpVersion($value)
    {
        if (!in_array($value, $this->knownPhpVersions, true)) {
            $this->phpVersion = $this->site->php_version;
            return;
        }

        $this->site->update(['php_version' => $value]);
        (new NginxConfigGenerator)->generate($this->site);
        $this->loadManifest();
    }

    public function updatedNodeVersion($value)
    {
        $this->nodeSwitchError = null;

        if (!in_array($value, (new NodeVersionManager)->availableVersions(), true)) {
            $this->nodeVersion = $this->site->node_version;
            return;
        }

        try {
            (new NodeVersionManager)->npmInstallAndBuild($this->site->projectRoot(), $value);
            $this->site->update(['node_version' => $value]);
        } catch (\Throwable $e) {
            $this->nodeVersion = $this->site->node_version;
            $this->nodeSwitchError = $e->getMessage();
        }

        $this->refreshActiveNodeVersion();
        $this->loadManifest();
    }

    public function selectDatabase(string $driver)
    {
        $this->dbSwitchError = null;

        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true) || $driver === $this->dbDriver) {
            return;
        }

        $this->beginDatabaseChange($driver);
    }

    public function reconfigureDatabase()
    {
        $this->dbSwitchError = null;

        if (in_array($this->dbDriver, ['mysql', 'pgsql'], true)) {
            $this->beginDatabaseChange($this->dbDriver);
        }
    }

    protected function beginDatabaseChange(string $driver): void
    {
        $this->pendingDbDriver = $driver;
        $this->runSeedOnSwitch = false;
        $this->dbTarget = 'new';
        $this->newDbName = str_replace('-', '_', $this->site->name);
        $this->existingDatabases = [];
        $this->existingDbName = '';
        $this->existingDbError = null;

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            try {
                $this->existingDatabases = (new DatabaseProvisioner)->existingDatabases($driver);
            } catch (\Throwable $e) {
                $this->existingDbError = $e->getMessage();
            }

            $current = $this->dbDriver === $driver ? $this->site->databaseName() : null;
            $match = in_array($this->newDbName, $this->existingDatabases, true) && $this->newDbName !== $current ? $this->newDbName : null;
            if ($match) {
                $this->dbTarget = 'existing';
                $this->existingDbName = $match;
            } else {
                $this->existingDbName = collect($this->existingDatabases)->first(fn ($d) => $d !== $current) ?? '';
            }
        }

        $this->runMigrationsOnSwitch = $this->dbTarget === 'new';
    }

    public function updatedDbTarget($value)
    {
        if (!in_array($value, ['new', 'existing'], true)) {
            $this->dbTarget = 'new';
        }

        $this->runMigrationsOnSwitch = $this->dbTarget === 'new';
        $this->runSeedOnSwitch = false;
    }

    public function cancelDatabaseChange()
    {
        $this->pendingDbDriver = null;
    }

    public function changeDatabase()
    {
        $this->dbSwitchError = null;
        $driver = $this->pendingDbDriver;

        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            return;
        }

        $provisionName = $this->site->name;
        $existing = null;
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $provisioner = new DatabaseProvisioner;

            if ($this->dbTarget === 'existing') {
                if (!in_array($this->existingDbName, $this->existingDatabases, true)) {
                    $this->dbSwitchError = 'Pick one of the existing databases from the list.';
                    return;
                }
                $existing = $this->existingDbName;

                if ($driver === $this->dbDriver && $existing === $this->site->databaseName()) {
                    $this->dbSwitchError = "This project already uses \"{$existing}\".";
                    return;
                }
            } else {
                try {
                    $provisionName = $provisioner->databaseName(strtolower(trim($this->newDbName)));
                } catch (\InvalidArgumentException $e) {
                    $this->dbSwitchError = $e->getMessage();
                    return;
                }

                if (in_array($provisionName, $this->existingDatabases, true)) {
                    $this->dbSwitchError = "A database named \"{$provisionName}\" already exists — choose \"Use an existing database\" to point at it, or pick a different name.";
                    return;
                }
            }
        }

        $migrate = $this->runMigrationsOnSwitch || $this->runSeedOnSwitch;

        try {
            (new DatabaseBackupManager)->backup($this->site);
        } catch (\Throwable $e) {
            $this->backupError = 'Pre-switch backup failed: ' . $e->getMessage();
        }
        $this->refreshBackups();

        try {
            (new DatabaseProvisioner)->provision($this->site->projectRoot(), $driver, $provisionName, $existing);

            if ($migrate) {
                Process::inProject($this->site->projectRoot())->run('php artisan migrate --force')->throw();
            }
            if ($this->runSeedOnSwitch) {
                Process::inProject($this->site->projectRoot())->run('php artisan db:seed --force')->throw();
            }

            $this->dbDriver = $driver;
        } catch (\Throwable $e) {
            $this->dbDriver = $this->site->databaseType() ?? 'none';
            $this->dbSwitchError = $e->getMessage();
        }

        $this->pendingDbDriver = null;
    }

    public function refreshBackups()
    {
        $this->backups = (new DatabaseBackupManager)->list($this->site);
    }

    public function backupNow()
    {
        $this->backupError = null;
        $this->backupNote = null;

        try {
            $path = (new DatabaseBackupManager)->backup($this->site);
            $this->backupNote = 'Backed up to ' . basename($path) . '.';
        } catch (\Throwable $e) {
            $this->backupError = $e->getMessage();
        }

        $this->refreshBackups();
    }

    public function restoreBackup(string $filename)
    {
        $this->backupError = null;
        $this->backupNote = null;

        try {
            (new DatabaseBackupManager)->restore($this->site, $filename);
            $this->backupNote = "Restored {$filename}.";
        } catch (\Throwable $e) {
            $this->backupError = $e->getMessage();
        }
    }

    public function deleteBackup(string $filename)
    {
        (new DatabaseBackupManager)->delete($this->site, $filename);
        $this->refreshBackups();
    }

    public function importBackup()
    {
        $this->backupError = null;
        $this->backupNote = null;

        if (!$this->importFile) {
            $this->backupError = 'Choose a file first.';
            return;
        }

        if (!in_array($this->importDriver, ['sqlite', 'mysql', 'pgsql'], true)) {
            return;
        }

        $currentDriver = $this->site->databaseType() ?? 'sqlite';
        if ($this->importDriver !== $currentDriver) {
            $this->backupError = "Switch the site to {$this->importDriver} first — importing into a different driver isn't supported.";
            return;
        }

        $this->validate(['importFile' => 'required|file|max:512000']);

        $extension = $this->importDriver === 'sqlite' ? 'sqlite' : 'sql';
        $target = (new DatabaseBackupManager)->importedPath($this->site, $this->importDriver, $extension);
        File::copy($this->importFile->getRealPath(), $target);
        $this->importFile = null;
        $this->refreshBackups();

        try {
            (new DatabaseBackupManager)->restore($this->site, basename($target));
            $this->backupNote = 'Imported and restored ' . basename($target) . '.';
        } catch (\Throwable $e) {
            $this->backupError = $e->getMessage();
        }
    }

    public function restartPhpFpm()
    {
        if (!in_array($this->phpVersion, $this->knownPhpVersions, true)) {
            return;
        }

        $unit = 'php' . str_replace('.', '', $this->phpVersion) . '-php-fpm';
        Process::run('systemctl restart ' . escapeshellarg($unit));
        $this->phpFpmRestartNote = "Restarted {$unit}.";
    }

    public function toggleXdebug()
    {
        $this->site->update(['xdebug_enabled' => !$this->site->xdebug_enabled]);
        (new NginxConfigGenerator)->generate($this->site);
    }

    public function toggleQueueWorker()
    {
        $this->site->update(['queue_worker_enabled' => !$this->site->queue_worker_enabled]);
        (new SupervisorConfigGenerator)->generate($this->site);
    }

    public function toggleReverb()
    {
        $enabling = !$this->site->reverb_enabled;
        $this->site->update(['reverb_enabled' => $enabling]);

        if ($enabling) {
            (new ReverbProvisioner)->provision($this->site->projectRoot(), $this->site);
        }

        (new NginxConfigGenerator)->generate($this->site);
        (new SupervisorConfigGenerator)->generate($this->site);
    }

    public function toggleScheduler()
    {
        $this->site->update(['scheduler_enabled' => !$this->site->scheduler_enabled]);
        (new SupervisorConfigGenerator)->generate($this->site);
    }

    public function toggleMeilisearch()
    {
        $provisioner = new \App\Services\MeilisearchProvisioner;
        $this->site->usesMeilisearch()
            ? $provisioner->disable($this->site->projectRoot())
            : $provisioner->enable($this->site->projectRoot());
        $this->loadEnvContent();
        $this->loadManifest();
    }

    public function toggleAutoBackup()
    {
        $this->site->update(['db_auto_backup_enabled' => !$this->site->db_auto_backup_enabled]);
    }
};
