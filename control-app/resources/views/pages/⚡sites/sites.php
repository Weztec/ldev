<?php

use Livewire\Component;
use App\Models\Site;
use App\Models\RepositoryToken;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;
use App\Services\SiteCloner;
use App\Services\NewProjectDetector;
use App\Services\SiteConfigBackup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

new class extends Component {
    public $sites;

    public $cloningSiteId = null;
    public $cloneName = '';
    public $cloneCopyData = true;
    public $cloneError = null;

    public $cloneCreateRepo = false;
    public $cloneRepoTokenId = null;
    public $cloneRepoVisibility = 'private';
    public $repositoryTokens;

    public $cloneRepoWarning = null;

    public $pushNote = null;
    public $pushError = null;

    public $foundCandidates = [];
    public $selectedCandidates = [];
    public $scanned = false;

    public $candidateConfigs = [];

    public function mount()
    {
        $this->sites = Site::all();
        $this->repositoryTokens = RepositoryToken::all();
    }

    public function startClone($siteId)
    {
        $this->cloningSiteId = $siteId;
        $this->cloneName = '';
        $this->cloneCopyData = true;
        $this->cloneCreateRepo = false;
        $this->cloneRepoTokenId = null;
        $this->cloneRepoVisibility = 'private';
        $this->cloneError = null;
    }

    public function cancelClone()
    {
        $this->cloningSiteId = null;
    }

    public function confirmClone()
    {
        $this->cloneError = null;
        $this->cloneRepoWarning = null;
        $source = Site::find($this->cloningSiteId);

        if (!$source) {
            $this->cloningSiteId = null;
            return;
        }

        try {
            $token = $this->cloneCreateRepo ? RepositoryToken::find($this->cloneRepoTokenId) : null;

            [, $repoWarning] = (new SiteCloner)->clone(
                $source,
                trim($this->cloneName),
                $this->cloneCopyData,
                $this->cloneCreateRepo && $token !== null,
                $token,
                $this->cloneRepoVisibility
            );

            $this->cloneRepoWarning = $repoWarning;
            $this->cloningSiteId = null;
            $this->sites = Site::all();
        } catch (\Throwable $e) {
            $this->cloneError = $e->getMessage();
        }
    }

    public function scanForUntrackedProjects()
    {
        $detector = new NewProjectDetector;
        $this->foundCandidates = $detector->findCandidates();
        $this->selectedCandidates = $this->foundCandidates;

        $this->candidateConfigs = [];
        foreach ($this->foundCandidates as $name) {
            $this->candidateConfigs[$name] = $detector->resolveConfig($name);
        }

        $this->scanned = true;
    }

    public function cancelScan()
    {
        $this->foundCandidates = [];
        $this->selectedCandidates = [];
        $this->candidateConfigs = [];
        $this->scanned = false;
    }

    public function addSelectedCandidates()
    {
        $toLink = array_intersect($this->selectedCandidates, $this->foundCandidates);
        $detector = new NewProjectDetector;

        foreach ($toLink as $name) {

            $detector->link($name, autoApplyFeatures: false, overrides: $this->candidateConfigs[$name] ?? []);
        }

        $this->foundCandidates = [];
        $this->selectedCandidates = [];
        $this->candidateConfigs = [];
        $this->scanned = false;
        $this->sites = Site::all();
    }

    public function pushSite($siteId)
    {
        $this->pushNote = null;
        $this->pushError = null;

        $site = Site::find($siteId);
        if (!$site) {
            return;
        }

        $result = Process::inProject($site->projectRoot())->run('git push');

        if ($result->failed()) {
            $this->pushError = "Push failed for {$site->name}: " . (trim($result->errorOutput()) ?: 'unknown error');
            return;
        }

        $this->pushNote = "Pushed {$site->name} to origin.";
    }

    public $pendingDeleteSiteId = null;
    public $pendingDeleteMode = null;
    public $pendingDeleteGitStatus = null;
    public $pushBeforeDeleteError = null;

    public function startDelete($siteId, string $mode)
    {
        $site = Site::find($siteId);
        if (!$site || !in_array($mode, ['list', 'disk'], true)) {
            return;
        }

        $this->pendingDeleteSiteId = $siteId;
        $this->pendingDeleteMode = $mode;
        $this->pendingDeleteGitStatus = $site->gitStatus();
        $this->pushBeforeDeleteError = null;
    }

    public function cancelDeleteReview()
    {
        $this->pendingDeleteSiteId = null;
        $this->pendingDeleteMode = null;
        $this->pendingDeleteGitStatus = null;
        $this->pushBeforeDeleteError = null;
    }

    public function pushBeforeDelete()
    {
        $site = Site::find($this->pendingDeleteSiteId);
        if (!$site) {
            return;
        }

        $this->pushBeforeDeleteError = null;
        $result = Process::inProject($site->projectRoot())->run('git push');

        if ($result->failed()) {
            $this->pushBeforeDeleteError = trim($result->errorOutput()) ?: 'git push failed.';
            return;
        }

        $this->pendingDeleteGitStatus = $site->gitStatus();
    }

    public function confirmDelete()
    {
        $site = Site::find($this->pendingDeleteSiteId);
        $mode = $this->pendingDeleteMode;
        $this->cancelDeleteReview();

        if (!$site) {
            return;
        }

        if ($mode === 'disk') {
            $this->deleteFromDisk($site);
        } else {
            $this->delete($site);
        }
    }

    public function delete(Site $site)
    {

        (new SiteConfigBackup)->backup($site);

        (new NginxConfigGenerator)->remove($site);
        (new SupervisorConfigGenerator)->remove($site);
        $site->delete();
        $this->sites = Site::all();
    }

    public function deleteFromDisk(Site $site)
    {
        $projectRoot = $site->projectRoot();
        $name = $site->name;

        (new SiteConfigBackup)->forget($name);

        (new NginxConfigGenerator)->remove($site);
        (new SupervisorConfigGenerator)->remove($site);
        $site->delete();

        if (File::isDirectory($projectRoot)) {
            File::deleteDirectory($projectRoot);
        }

        File::delete([
            config('ldev.home') . "/.config/ldev/certs/{$name}.pem",
            config('ldev.home') . "/.config/ldev/certs/{$name}-key.pem",
        ]);

        $backupDir = config('ldev.backups_dir') . "/{$name}";
        if (File::isDirectory($backupDir)) {
            File::deleteDirectory($backupDir);
        }

        $logsDir = config('ldev.home') . '/.config/ldev/logs';
        File::delete(array_merge(
            File::glob("{$logsDir}/{$name}.test-*.log"),
            File::glob("{$logsDir}/{$name}-*.log"),
        ));

        $this->sites = Site::all();
    }
};
