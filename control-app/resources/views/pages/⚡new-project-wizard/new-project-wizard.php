<?php

use Livewire\Component;
use Illuminate\Support\Facades\Artisan;
use App\Models\Site;
use App\Models\Setting;
use App\Models\RepositoryToken;
use App\Services\NodeVersionManager;
use App\Services\DatabaseProvisioner;
use App\Services\SshConnectionChecker;

new class extends Component {
    public $step = 1;
    public $name;
    public $phpVersion;
    public $nodeVersion;
    public $dbType = 'sqlite';

    public $dbTarget = 'new';
    public $dbNewName = '';
    public $dbExisting = '';
    public $existingDatabases = [];
    public $existingDbError = null;
    public $source = 'scaffold';
    public $starter = 'none';
    public $stack = 'livewire';
    public $dark = false;
    public $teams = false;
    public $pest = false;
    public $s3 = false;
    public $reverb = false;

    public $repoProtocol = 'ssh';
    public $repoUrl = '';
    public $repositoryTokenId = null;

    public $sshDiagnosis = null;
    public $repositoryTokens;
    public $createRepo = false;
    public $createRepoTokenId = null;
    public $createRepoVisibility = 'private';
    public $createError = null;

    protected array $knownVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function mount()
    {

        $this->phpVersion = Setting::get('default_php_version', end($this->knownVersions));

        $knownNodeVersions = NodeVersionManager::KNOWN_VERSIONS;
        $this->nodeVersion = Setting::get('default_node_version', end($knownNodeVersions));

        $this->repositoryTokens = RepositoryToken::all();

        if ($cloneUrl = request()->query('clone_url')) {
            $this->source = 'clone';
            $this->repoUrl = $cloneUrl;

            $this->repoProtocol = str_starts_with($cloneUrl, 'https://') ? 'https' : 'ssh';
            if ($tokenId = request()->query('token_id')) {
                $this->repositoryTokenId = (int) $tokenId;
            }
        }

        if ($this->source === 'clone' && $this->repoProtocol === 'ssh') {
            $this->runSshDiagnosis();
        }
    }

    public function updatedRepoProtocol($value)
    {
        $this->repoUrl = '';
        $this->repositoryTokenId = null;

        if ($value === 'ssh') {
            $this->runSshDiagnosis();
        }
    }

    public function updatedDbType($value)
    {
        $this->dbTarget = 'new';
        $this->dbExisting = '';
        $this->existingDatabases = [];
        $this->existingDbError = null;

        if (in_array($value, ['mysql', 'pgsql'], true)) {
            try {
                $this->existingDatabases = (new DatabaseProvisioner)->existingDatabases($value);
            } catch (\Throwable $e) {
                $this->existingDbError = $e->getMessage();
            }
            $this->dbExisting = $this->existingDatabases[0] ?? '';
        }
    }

    public function updatedDbTarget($value)
    {
        if (!in_array($value, ['new', 'existing'], true)) {
            $this->dbTarget = 'new';
        }
    }

    public function updatedSource($value)
    {
        if ($value === 'clone' && $this->repoProtocol === 'ssh' && $this->sshDiagnosis === null) {
            $this->runSshDiagnosis();
        }
    }

    public function runSshDiagnosis()
    {
        $this->sshDiagnosis = (new SshConnectionChecker)->diagnose();
    }

    public function updatedStarter($value)
    {
        $this->stack = $value === 'jetstream' ? 'livewire' : 'blade';
    }

    public function create()
    {
        $this->createError = null;

        $rules = [
            'name' => ['required', 'string', 'regex:' . Site::NAME_PATTERN],
        ];
        if ($this->source === 'clone') {
            $rules['repoUrl'] = ['required', 'string', 'max:2048'];
        }
        $this->validate($rules);

        $options = [
            'name' => $this->name,
            '--db' => $this->dbType,
            '--php' => $this->phpVersion,
            '--node' => $this->nodeVersion,
        ];

        if (in_array($this->dbType, ['mysql', 'pgsql'], true)) {
            if ($this->dbTarget === 'existing') {
                if (!in_array($this->dbExisting, $this->existingDatabases, true)) {
                    $this->createError = 'Pick one of the existing databases from the list.';
                    return;
                }
                $options['--db-existing'] = $this->dbExisting;
            } elseif (trim((string) $this->dbNewName) !== '') {
                $options['--db-name'] = strtolower(trim($this->dbNewName));
            }
        }

        if ($this->source === 'clone') {
            $options['--repo'] = $this->repoUrl;
            if ($this->repositoryTokenId) {
                $options['--token-id'] = $this->repositoryTokenId;
            }
        } else {
            if ($this->createRepo && $this->createRepoTokenId) {
                $options['--create-repo'] = true;
                $options['--repo-token-id'] = $this->createRepoTokenId;
                $options['--repo-visibility'] = $this->createRepoVisibility;
            }

            if ($this->pest) {
                $options['--pest'] = true;
            }

            if ($this->starter === 'breeze') {
                $options['--breeze'] = true;
                $options['--stack'] = $this->stack;
                if ($this->dark) {
                    $options['--dark'] = true;
                }
            } elseif ($this->starter === 'jetstream') {
                $options['--jetstream'] = true;
                $options['--stack'] = $this->stack;
                if ($this->teams) {
                    $options['--teams'] = true;
                }
            }
        }

        if ($this->s3) {
            $options['--s3'] = true;
        }
        if ($this->reverb) {
            $options['--reverb'] = true;
        }

        $exitCode = Artisan::call('ldev:new', $options);

        if ($exitCode !== 0) {
            $this->createError = trim(Artisan::output()) ?: 'ldev:new failed with no output — check storage/logs/laravel.log.';
            return;
        }

        $this->redirect(route('dashboard'), navigate: true);
    }
};
