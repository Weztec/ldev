<?php

use Livewire\Component;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use App\Models\Setting;
use App\Models\ComposerCredential;
use App\Services\NodeVersionManager;
use App\Services\ComposerAuthWriter;
use App\Services\DashboardBackupManager;
use App\Services\DesktopNotifier;

new class extends Component {
    public $maskedToken;
    public $defaultPhpVersion;
    public $defaultNodeVersion;
    public $activeDefaultNodeVersion;
    public $justRegenerated = false;
    public $minioAccessKey;
    public $minioSecretKey;
    public $minioDataDir;

    public $composerCredentials;
    public $composerHost = '';
    public $composerUsername = '';
    public $composerSecret = '';
    public $composerShowForm = false;

    public $composerEditingId = null;
    public $composerUnreadable = [];
    public $composerRepairNote = null;

    public $versionChecks = [];
    public $versionCheckLastRun = null;
    public $checkingVersionsNow = false;

    public $desktopNotifications = true;
    public $notificationNote = null;
    public $notificationTimeout = 0;

    public $availableNodeVersions = [];
    public $newNodeVersion = '';
    public $newNodeVersionNote = null;
    public $newNodeVersionError = null;

    public $dashboardBackups = [];
    public $dashboardSafetyCopies = [];
    public $dashboardBackupDir;
    public $dashboardBackupError = null;
    public $dashboardBackupNote = null;

    protected array $knownVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    protected function latestKnownNodeVersion(): string
    {
        $versions = NodeVersionManager::KNOWN_VERSIONS;
        return end($versions);
    }

    public function mount()
    {
        $this->maskedToken = $this->maskToken($this->currentToken());

        $this->defaultPhpVersion = Setting::get('default_php_version', end($this->knownVersions));

        $this->defaultNodeVersion = Setting::get('default_node_version', $this->latestKnownNodeVersion());
        $this->refreshActiveDefaultNodeVersion();
        $this->availableNodeVersions = (new NodeVersionManager)->availableVersions();

        $this->minioAccessKey = config('ldev.minio.access_key');
        $this->minioSecretKey = config('ldev.minio.secret_key');
        $this->minioDataDir = config('ldev.minio_data_dir');

        $this->loadComposerCredentials(true);

        $this->loadVersionChecks();
        $this->refreshDashboardBackups();

        $this->desktopNotifications = DesktopNotifier::enabled();
        $this->notificationTimeout = DesktopNotifier::timeoutSeconds();
    }

    public function updatedNotificationTimeout($value)
    {
        if (!in_array((int) $value, DesktopNotifier::TIMEOUTS, true)) {
            $this->notificationTimeout = DesktopNotifier::timeoutSeconds();
            return;
        }

        $this->notificationTimeout = (int) $value;
        Setting::set('notification_timeout_seconds', (string) $this->notificationTimeout);
    }

    public function updatedDesktopNotifications($value)
    {
        $this->desktopNotifications = (bool) $value;
        Setting::set('desktop_notifications', $this->desktopNotifications ? '1' : '0');
        $this->notificationNote = null;
    }

    public function sendTestNotification()
    {
        $sent = (new DesktopNotifier)->send('Linux Dev', 'Desktop notifications are working.');
        $this->notificationNote = $sent ? 'Test notification sent.' : 'Could not send a notification (turned off, or notify-send failed).';
    }

    public function refreshDashboardBackups()
    {
        $manager = new DashboardBackupManager;
        $this->dashboardBackupDir = $manager->directory();
        $this->dashboardBackups = $manager->list();
        $this->dashboardSafetyCopies = array_slice($manager->safetyCopies(), 0, 5);
    }

    public function restoreDashboard(string $name)
    {
        $this->dashboardBackupError = null;
        $this->dashboardBackupNote = null;

        try {
            $result = (new DashboardBackupManager)->restore($name);
            $this->dashboardBackupNote = "Restored {$result['restored']}. What was there before is kept as " . basename($result['safetyCopy']) . ' under "Before a restore" below, so you can undo this. Reload the page to see the restored settings.';
        } catch (\Throwable $e) {
            $this->dashboardBackupError = $e->getMessage();
        }

        $this->refreshDashboardBackups();
    }

    public function backupDashboardNow()
    {
        $this->dashboardBackupError = null;
        $this->dashboardBackupNote = null;

        try {
            $manager = new DashboardBackupManager;
            $snap = $manager->backup();
            $manager->prune(14);
            $this->dashboardBackupNote = "Backed up as {$snap['name']}.";
        } catch (\Throwable $e) {
            $this->dashboardBackupError = $e->getMessage();
        }

        $this->refreshDashboardBackups();
    }

    public function loadVersionChecks()
    {
        foreach (['ldev', 'php', 'node', 'laravel', 'livewire', 'flux', 'dnf'] as $key) {
            $raw = Setting::get("version_check_{$key}");
            $this->versionChecks[$key] = $raw ? json_decode($raw, true) : null;
        }

        $this->versionCheckLastRun = Setting::get('version_check_last_run');
    }

    public function checkVersionsNow()
    {
        $this->checkingVersionsNow = true;
        \Illuminate\Support\Facades\Artisan::call('ldev:check-versions', ['--skip-sites' => true]);
        $this->loadVersionChecks();
        $this->checkingVersionsNow = false;
    }

    public function startAddingComposerCredential()
    {
        $this->reset(['composerHost', 'composerUsername', 'composerSecret', 'composerEditingId']);
        $this->composerShowForm = true;
    }

    public function editComposerCredential($id)
    {
        $cred = ComposerCredential::global()->find($id);
        if (!$cred) {
            return;
        }

        $this->composerEditingId = $cred->id;
        $this->composerHost = $cred->host;
        $this->composerUsername = $cred->username;
        $this->composerSecret = '';
        $this->composerShowForm = true;
    }

    public function cancelComposerForm()
    {
        $this->composerShowForm = false;
    }

    protected function loadComposerCredentials(bool $repair = false): void
    {
        $writer = new ComposerAuthWriter;

        if ($repair) {
            try {
                $report = $writer->repair();
                $notes = [];
                if ($report['restoredFromSnapshot']) {
                    $notes[] = 'Composer auth.json was missing or damaged and has been restored from its latest snapshot.';
                }
                if ($report['imported']) {
                    $notes[] = 'Recovered from auth.json: ' . implode(', ', $report['imported']) . '.';
                }
                $this->composerRepairNote = $notes ? implode(' ', $notes) : null;
            } catch (\Throwable $e) {
                $this->composerRepairNote = 'Could not check Composer auth.json: ' . $e->getMessage();
            }
        }

        $this->composerCredentials = ComposerCredential::global()->get();
        $this->composerUnreadable = $writer->unreadableHosts();
    }

    public function saveComposerCredential()
    {
        $rules = [
            'composerHost' => [
                'required', 'string', 'max:255',
                \Illuminate\Validation\Rule::unique('composer_credentials', 'host')->whereNull('site_id')->ignore($this->composerEditingId),
            ],
            'composerUsername' => ['required', 'string', 'max:255'],
            'composerSecret' => $this->composerEditingId ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
        ];
        $this->validate($rules);

        $data = ['host' => $this->composerHost, 'username' => $this->composerUsername];
        if ($this->composerSecret !== '') {
            $data['secret'] = $this->composerSecret;
        }

        $removeHosts = [];
        if ($this->composerEditingId) {
            $cred = ComposerCredential::global()->find($this->composerEditingId);
            if (!$cred) {
                return;
            }
            if ($cred->host !== $this->composerHost) {
                $removeHosts[] = $cred->host;
            }
            $cred->update($data);
        } else {
            ComposerCredential::create($data);
        }

        (new ComposerAuthWriter)->sync($removeHosts);

        $this->reset(['composerHost', 'composerUsername', 'composerSecret', 'composerShowForm', 'composerEditingId']);
        $this->loadComposerCredentials();
    }

    public function deleteComposerCredential($id)
    {
        $cred = ComposerCredential::global()->find($id);
        if (!$cred) {
            return;
        }

        $host = $cred->host;
        $cred->delete();
        (new ComposerAuthWriter)->sync([$host]);
        $this->loadComposerCredentials();
    }

    protected function tokenPath(): string
    {
        return config('ldev.home') . '/.config/ldev/token';
    }

    protected function currentToken(): ?string
    {
        return is_file($this->tokenPath()) ? trim(file_get_contents($this->tokenPath())) : null;
    }

    protected function maskToken(?string $token): string
    {
        if (!$token) {
            return '(none generated yet)';
        }

        return str_repeat('*', 16) . substr($token, -4);
    }

    public function regenerateToken()
    {
        $newToken = Str::random(40);

        $dir = dirname($this->tokenPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($this->tokenPath(), $newToken . PHP_EOL);
        chmod($this->tokenPath(), 0600);

        Cookie::queue(cookie('ldev_token', $newToken, 60 * 24 * 30, null, null, false, true, false, 'strict'));

        $this->maskedToken = $this->maskToken($newToken);
        $this->justRegenerated = true;
    }

    public function updatedDefaultPhpVersion($value)
    {
        if (!in_array($value, $this->knownVersions, true)) {
            $this->defaultPhpVersion = Setting::get('default_php_version', end($this->knownVersions));
            return;
        }

        Setting::set('default_php_version', $value);
    }

    public function updatedDefaultNodeVersion($value)
    {
        if ($value !== '' && !in_array($value, NodeVersionManager::KNOWN_VERSIONS, true)) {
            $this->defaultNodeVersion = Setting::get('default_node_version', $this->latestKnownNodeVersion());
            $this->refreshActiveDefaultNodeVersion();
            return;
        }

        Setting::set('default_node_version', $value);
        $this->refreshActiveDefaultNodeVersion();
    }

    public function refreshActiveDefaultNodeVersion()
    {
        $this->activeDefaultNodeVersion = (new NodeVersionManager)->activeVersion($this->defaultNodeVersion ?: null);
    }

    public function installNodeVersion()
    {
        $this->newNodeVersionNote = null;
        $this->newNodeVersionError = null;

        $version = trim($this->newNodeVersion);
        if (!preg_match('/^\d{1,3}$/', $version)) {
            $this->newNodeVersionError = 'Enter a major version number, e.g. "20".';
            return;
        }

        try {
            (new NodeVersionManager)->ensureInstalled($version);
            $this->newNodeVersion = '';
            $this->newNodeVersionNote = "Node.js {$version} installed.";
            $this->availableNodeVersions = (new NodeVersionManager)->availableVersions();
        } catch (\Throwable $e) {
            $this->newNodeVersionError = $e->getMessage();
        }
    }
};
