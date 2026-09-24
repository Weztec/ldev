<?php

use Livewire\Component;
use App\Models\Site;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;
use App\Services\GitInitializer;
use App\Services\CloudflareTunnelManager;
use App\Services\RemoteRepositoryProvisioner;
use App\Services\SiteConfigBackup;
use App\Services\DependencyHealthChecker;
use App\Services\DependencySandbox;
use App\Services\TestRunner;
use App\Services\TestEnvironment;
use App\Models\Setting;
use App\Models\RepositoryToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

new class extends Component {
    public Site $site;
    public $gitStatus;

    public $tunnelUrl = null;
    public $tunnelError = null;

    public $repositoryTokens;
    public $showPushForm = false;
    public $pushTokenId = null;
    public $pushVisibility = 'private';
    public $pushError = null;

    public $showGitDiffModal = false;
    public $gitDiffFiles = [];
    public $selectedDiffFile = null;
    public $gitDiffLines = [];
    public $selectedFilesForCommit = [];

    public $skipWorktreeFiles = [];
    public $gitIgnoreNote = null;

    public $commitMessage = '';
    public $commitError = null;
    public $commitNote = null;

    public $manualGitCommand = '';
    public $manualGitOutput = null;
    public $manualGitError = null;

    public $projectLogLines = [];
    public $projectLogFiles = [];
    public $projectLogTruncated = 0;
    public $selectedProjectLog = null;

    public $diskUsage = null;
    public $fpmStats = null;

    public $dependencies = null;
    public $selectedPackages = ['security' => [], 'npm-security' => [], 'composer' => [], 'npm' => []];
    public $dependencyUpdateResult = null;
    public $sandbox = null;
    public $openSections = ['dependencies' => false, 'tests' => false];

    public $testSuite = null;
    public $selectedTests = [];
    public $testRun = null;
    public $testError = null;
    public $testFilter = 'all';
    public $testCoverage = false;
    public $testHistory = [];
    public $testEnv = [];
    public $testEnvFile = null;
    public $testDb = null;
    public $testEnvMessage = null;

    public $queueStatus = null;
    public $reverbStatus = null;
    public $schedulerStatus = null;
    public $queueJobCounts = null;
    public $queueLogLines = [];
    public $reverbLogLines = [];
    public $schedulerLogLines = [];

    public $showQueueModal = false;
    public $queueJobsData = null;
    public $queueJobsError = null;
    public $viewingExceptionUuid = null;
    public $viewingExceptionText = null;

    public $showSchedulerModal = false;
    public $scheduleListRows = [];
    public $scheduleListOutput = null;
    public $scheduleListError = null;
    public $schedulerRunHistory = [];

    public $isPaused = false;
    public $pollIntervalSeconds;

    protected array $knownPollIntervals = [5, 10, 15, 30, 60];

    public function mount(Site $site)
    {
        $this->site = $site;
        $this->refreshGitStatus();
        $this->tunnelUrl = (new CloudflareTunnelManager)->publicUrl($this->site);
        $this->repositoryTokens = RepositoryToken::all();
        $this->refreshProjectLog();
        $this->refreshResourceUsage();
        $this->refreshBackgroundProcesses();
        $this->dependencies = DependencyHealthChecker::cached($this->site);
        $this->sandbox = DependencySandbox::status($this->site);
        $this->loadTestSuite();

        $this->pollIntervalSeconds = (int) Setting::get('site_detail_poll_seconds', '10');
    }

    public function updatedPollIntervalSeconds($value)
    {
        if (!in_array((int) $value, $this->knownPollIntervals, true)) {
            $this->pollIntervalSeconds = (int) Setting::get('site_detail_poll_seconds', '10');
            return;
        }

        $this->pollIntervalSeconds = (int) $value;
        Setting::set('site_detail_poll_seconds', (string) $this->pollIntervalSeconds);
    }

    public function livePoll()
    {
        if ($this->isPaused) {
            return;
        }

        $this->refreshGitStatus();
        $this->refreshResourceUsage();
        $this->refreshBackgroundProcesses();
        $this->refreshProjectLog();
        $this->sandbox = DependencySandbox::status($this->site);
        $this->testRun = TestRunner::status($this->site);
        if (($this->testRun['finishedAt'] ?? null) !== (end($this->testHistory)['finishedAt'] ?? null)) {
            $this->testHistory = TestRunner::history($this->site);
        }
    }

    public function toggleSection(string $section)
    {
        if (array_key_exists($section, $this->openSections)) {
            $this->openSections[$section] = !$this->openSections[$section];
        }
    }

    public function loadTestSuite()
    {
        $root = $this->site->projectRoot();
        $found = TestRunner::discover($root);
        $this->openSections['tests'] = $this->openSections['tests'] || $this->testSuite !== null;
        $this->testSuite = [
            'runner' => $found['runner'],
            'files' => $found['files'],
            'styles' => $found['styles'],
            'suites' => $found['suites'],
            'jsRunner' => $found['jsRunner'],
            'unsafe' => TestRunner::unsafeDatabase($root, $found['config'], TestEnvironment::overrides($this->site)),
        ];
        $this->testRun = TestRunner::status($this->site);
        $this->testHistory = TestRunner::history($this->site);
        $this->loadTestEnv();
    }

    public function loadTestEnv()
    {
        $env = new TestEnvironment;
        $this->testEnvFile = TestEnvironment::configFile($this->site->projectRoot());
        $this->testEnv = $env->rows($this->site);
        $this->testDb = $env->databaseStatus(TestEnvironment::effective($this->testEnv));
    }

    public function addTestEnvVar()
    {
        $this->testEnv[] = ['name' => '', 'project' => null, 'force' => false, 'override' => true, 'value' => ''];
    }

    public function updatedTestEnv()
    {
        $this->persistTestEnv();
    }

    protected function persistTestEnv()
    {
        try {
            $overrides = (new TestEnvironment)->saveRows($this->site, $this->cleanTestEnv());
        } catch (\Throwable $e) {
            $this->testEnvMessage = ['ok' => false, 'text' => 'Not saved: ' . $e->getMessage()];
            return;
        }

        $this->testEnvMessage = ['ok' => true, 'text' => 'Saved. ' . count($overrides) . ' override(s) apply to the next test run.'];
        $this->testDb = (new TestEnvironment)->databaseStatus(TestEnvironment::effective($this->cleanTestEnv()));
        $this->testSuite['unsafe'] = TestRunner::unsafeDatabase($this->site->projectRoot(), TestEnvironment::configFile($this->site->projectRoot()), TestEnvironment::overrides($this->site));
    }

    public function removeTestEnvVar(int $index)
    {
        if (!isset($this->testEnv[$index])) {
            return;
        }

        if (($this->testEnv[$index]['project'] ?? null) === null) {
            unset($this->testEnv[$index]);
            $this->testEnv = array_values($this->testEnv);
        } else {
            $this->testEnv[$index]['override'] = false;
            $this->testEnv[$index]['value'] = $this->testEnv[$index]['project'];
        }

        $this->persistTestEnv();
    }

    public function useLdevDatabaseSettings()
    {
        $this->testEnv = (new TestEnvironment)->ldevDatabaseDefaults($this->cleanTestEnv(), $this->site->name);
        $this->persistTestEnv();
    }

    public function createTestDatabase()
    {
        $env = new TestEnvironment;
        $status = $env->databaseStatus(TestEnvironment::effective($this->cleanTestEnv()));
        if (!$status || $status['exists']) {
            return;
        }

        try {
            $env->createDatabase($status['connection'], $status['database']);
            $this->testEnvMessage = ['ok' => true, 'text' => 'Created database ' . $status['database'] . '.'];
        } catch (\Throwable $e) {
            $this->testEnvMessage = ['ok' => false, 'text' => 'Could not create ' . $status['database'] . ': ' . $e->getMessage()];
        }
        $this->testDb = $env->databaseStatus(TestEnvironment::effective($this->cleanTestEnv()));
    }

    protected function cleanTestEnv(): array
    {
        $project = (new TestEnvironment)->projectVariables($this->site->projectRoot());

        return array_values(array_map(function ($row) use ($project) {
            $name = trim((string) ($row['name'] ?? ''));

            return [
                'name' => $name,
                'project' => isset($project[$name]) ? $project[$name]['value'] : null,
                'force' => $project[$name]['force'] ?? false,
                'override' => !isset($project[$name]) || !empty($row['override']),
                'value' => (string) ($row['value'] ?? ''),
            ];
        }, array_filter((array) $this->testEnv, 'is_array')));
    }

    public function updatedTestFilter($value)
    {
        if (!in_array($value, ['all', 'failed', 'skipped'], true)) {
            $this->testFilter = 'all';
        }
    }

    public function clearTestResults()
    {
        (new TestRunner)->clear($this->site);
        $this->testRun = TestRunner::status($this->site);
        $this->testFilter = 'all';
    }

    public function runTests(bool $all = false)
    {
        $found = TestRunner::discover($this->site->projectRoot());
        $this->launchTests(TestRunner::normaliseSelection($found['files'], (array) $this->selectedTests, $all));
    }

    public function runFailedTests()
    {
        $found = TestRunner::discover($this->site->projectRoot());
        $selection = TestRunner::failedSelection($this->testRun['results'] ?? [], $found['files']);
        if (!$selection) {
            $this->testError = 'The last run has no failed tests to run again.';
            return;
        }
        $this->launchTests($selection);
    }

    public function runTestSuite(string $suite)
    {
        $found = TestRunner::discover($this->site->projectRoot());
        $paths = array_keys(array_filter($found['suites'], fn ($name) => $name === $suite));
        $this->launchTests(array_fill_keys(array_intersect($paths, array_keys($found['files'])), []));
    }

    public function clearTestHistory()
    {
        \App\Models\Setting::set(TestRunner::historyKey($this->site), '');
        $this->testHistory = [];
    }

    protected function launchTests(array $selection)
    {
        $this->openSections['tests'] = true;
        $this->testError = null;
        if (($this->testRun['state'] ?? null) === 'running') {
            return;
        }

        if (!$selection) {
            $this->testError = 'No tests selected.';
            return;
        }

        (new TestRunner)->launch($this->site, $selection, (bool) $this->testCoverage);
        $this->testRun = TestRunner::status($this->site);
    }

    public function checkDependencies()
    {
        $this->openSections['dependencies'] = true;
        $this->dependencies = (new DependencyHealthChecker)->check($this->site);
    }

    public function testDependencyUpdate(string $section, bool $all = false, bool $major = false)
    {
        $this->openSections['dependencies'] = true;
        if (!in_array($section, DependencyHealthChecker::SECTIONS, true) || ($this->sandbox['state'] ?? null) === 'running') {
            return;
        }

        $known = DependencyHealthChecker::updatablePackages(DependencyHealthChecker::cached($this->site), $section);
        $major = $major && in_array($section, ['composer', 'npm'], true);
        $packages = $all ? [] : array_values(array_intersect($known, (array) ($this->selectedPackages[$section] ?? [])));

        if (!$all && !$packages) {
            $this->dependencyUpdateResult = ['ok' => false, 'output' => 'No packages selected.'];
            return;
        }

        $this->dependencyUpdateResult = null;
        (new DependencySandbox)->discard($this->site);
        (new DependencySandbox)->launch($this->site, $section, $packages, $all, $major);
        $this->selectedPackages = ['security' => [], 'npm-security' => [], 'composer' => [], 'npm' => []];
        $this->sandbox = DependencySandbox::status($this->site);
    }

    public function applyDependencyUpdate()
    {
        $this->dependencyUpdateResult = (new DependencySandbox)->apply($this->site);
        $this->sandbox = DependencySandbox::status($this->site);
        if ($this->dependencyUpdateResult['ok']) {
            $this->dependencies = (new DependencyHealthChecker)->check($this->site);
        }
    }

    public function discardDependencySandbox()
    {
        (new DependencySandbox)->discard($this->site);
        $this->sandbox = DependencySandbox::status($this->site);
        $this->dependencyUpdateResult = null;
    }

    public function togglePause()
    {
        $this->isPaused = !$this->isPaused;
    }

    public function refreshBackgroundProcesses()
    {
        $this->queueStatus = $this->site->queue_worker_enabled ? $this->site->backgroundProcessStatus('queue') : null;
        $this->reverbStatus = $this->site->reverb_enabled ? $this->site->backgroundProcessStatus('reverb') : null;
        $this->schedulerStatus = $this->site->scheduler_enabled ? $this->site->backgroundProcessStatus('scheduler') : null;

        $this->queueJobCounts = $this->site->queue_worker_enabled ? $this->site->queueJobCounts() : null;

        $this->queueLogLines = $this->site->queue_worker_enabled ? $this->tailBackgroundLog('queue') : [];
        $this->reverbLogLines = $this->site->reverb_enabled ? $this->tailBackgroundLog('reverb') : [];
        $this->schedulerLogLines = $this->site->scheduler_enabled ? $this->tailBackgroundLog('scheduler') : [];
    }

    protected function tailBackgroundLog(string $program, int $lines = 20): array
    {
        $path = $this->backgroundLogPath($program);
        if (!File::exists($path)) {
            return [];
        }

        $result = Process::run('tail -n ' . $lines . ' ' . escapeshellarg($path));
        return array_filter(explode("\n", $result->output()));
    }

    protected function backgroundLogPath(string $program): string
    {
        return config('ldev.home') . "/.config/ldev/logs/{$this->site->name}-{$program}.log";
    }

    public function openQueueModal()
    {
        $this->showQueueModal = true;
        $this->refreshQueueJobs();
    }

    public function refreshQueueJobs()
    {
        $this->queueJobsError = null;
        $this->queueJobsData = $this->site->queueJobs();

        $this->viewingExceptionUuid = null;
        $this->viewingExceptionText = null;

        if ($this->queueJobsData === null) {
            $this->queueJobsError = 'Not a Laravel project, or this project is not using the database queue driver.';
        }
    }

    public function viewFailedJobException($uuid)
    {
        if (!collect($this->queueJobsData['failed'] ?? [])->pluck('uuid')->contains($uuid)) {
            return;
        }

        $this->viewingExceptionUuid = $uuid;
        $this->viewingExceptionText = $this->site->failedJobException($uuid) ?? 'Could not load the full exception.';
    }

    public function closeFailedJobException()
    {
        $this->viewingExceptionUuid = null;
        $this->viewingExceptionText = null;
    }

    public function retryFailedJob($uuid)
    {
        if (!collect($this->queueJobsData['failed'] ?? [])->pluck('uuid')->contains($uuid)) {
            return;
        }

        Process::inProject($this->site->projectRoot())->run('php artisan queue:retry ' . escapeshellarg($uuid));
        $this->refreshQueueJobs();
    }

    public function deleteFailedJob($uuid)
    {
        if (!collect($this->queueJobsData['failed'] ?? [])->pluck('uuid')->contains($uuid)) {
            return;
        }

        Process::inProject($this->site->projectRoot())->run('php artisan queue:forget ' . escapeshellarg($uuid));
        $this->refreshQueueJobs();
    }

    public function retryAllFailedJobs()
    {
        Process::inProject($this->site->projectRoot())->run('php artisan queue:retry all');
        $this->refreshQueueJobs();
    }

    public function flushFailedJobs()
    {
        Process::inProject($this->site->projectRoot())->run('php artisan queue:flush');
        $this->refreshQueueJobs();
    }

    public function openSchedulerModal()
    {
        $this->showSchedulerModal = true;
        $this->refreshSchedulerModal();
    }

    public function refreshSchedulerModal()
    {
        $this->scheduleListError = null;
        $this->scheduleListRows = [];
        $this->scheduleListOutput = null;

        $result = Process::inProject($this->site->projectRoot())->run('php artisan schedule:list --json');

        if ($result->successful()) {
            $decoded = json_decode(trim($result->output()), true);

            if (is_array($decoded)) {
                $this->scheduleListRows = $decoded;
            } else {

                $this->scheduleListOutput = trim($result->output()) ?: 'No scheduled tasks have been defined.';
            }
        } else {
            $this->scheduleListError = trim($result->errorOutput()) ?: 'Could not list scheduled tasks — is this a Laravel project?';
        }

        $this->schedulerRunHistory = $this->tailBackgroundLog('scheduler', 200);
    }

    public function clearBackgroundLog($program)
    {
        if (!in_array($program, ['queue', 'reverb', 'scheduler'], true)) {
            return;
        }

        $this->clearOneBackgroundLog($program);
        $this->refreshBackgroundProcesses();
    }

    public function clearAllBackgroundLogs()
    {
        foreach (['queue', 'reverb', 'scheduler'] as $program) {
            $this->clearOneBackgroundLog($program);
        }

        $this->refreshBackgroundProcesses();
    }

    protected function clearOneBackgroundLog(string $program): void
    {
        $path = $this->backgroundLogPath($program);
        if (!File::exists($path)) {
            return;
        }

        try {
            File::put($path, '');
        } catch (\Throwable $e) {
            File::delete($path);
            $name = "{$this->site->name}-{$program}";
            Process::run('supervisorctl -s http://127.0.0.1:9002 restart ' . escapeshellarg("{$name}:*"));
        }
    }

    public function refreshResourceUsage()
    {
        $this->diskUsage = $this->site->diskUsageHuman();
        $this->fpmStats = $this->site->fpmPoolStats();
    }

    public function pushToNewRepo()
    {
        $this->pushError = null;

        $token = RepositoryToken::find($this->pushTokenId);
        if (!$token) {
            $this->pushError = 'Select a token first.';
            return;
        }

        try {
            (new RemoteRepositoryProvisioner)->createAndPush(
                $this->site->projectRoot(),
                $this->site->name,
                $token,
                $this->pushVisibility !== 'public'
            );
            $this->showPushForm = false;
            $this->refreshGitStatus();
        } catch (\Throwable $e) {
            $this->pushError = $e->getMessage();
        }
    }

    public function toggleTunnel()
    {
        $this->tunnelError = null;
        $manager = new CloudflareTunnelManager;

        try {
            if ($this->tunnelUrl) {
                $manager->stop($this->site);
                $this->tunnelUrl = null;
            } else {
                $this->tunnelUrl = $manager->start($this->site);
            }
        } catch (\Throwable $e) {
            $this->tunnelError = $e->getMessage();
        }
    }

    public function refreshGitStatus()
    {
        $this->gitStatus = $this->site->gitStatus();
    }

    public function initGitRepo()
    {
        (new GitInitializer)->initIfNeeded($this->site->projectRoot());
        $this->refreshGitStatus();
    }

    public function openGitDiff()
    {
        $this->showGitDiffModal = true;
        $this->gitIgnoreNote = null;
        $this->loadGitDiff();
        $this->refreshSkipWorktreeFiles();
    }

    public function closeGitDiff()
    {
        $this->showGitDiffModal = false;
    }

    public function loadGitDiff()
    {
        $path = $this->site->projectRoot();

        $statusResult = Process::inProject($path)->run('git -c core.quotepath=false status --porcelain');

        $this->gitDiffFiles = collect(explode("\n", rtrim($statusResult->output())))
            ->filter()
            ->map(function ($line) {
                $code = trim(substr($line, 0, 2));
                return [
                    'file' => trim(substr($line, 3)),
                    'label' => match (true) {
                        str_contains($code, '?') => 'Untracked',
                        str_contains($code, 'A') => 'Added',
                        str_contains($code, 'D') => 'Deleted',
                        str_contains($code, 'R') => 'Renamed',
                        str_contains($code, 'M') => 'Modified',
                        default => $code,
                    },
                ];
            })
            ->values()
            ->all();

        $files = collect($this->gitDiffFiles)->pluck('file');
        if (!$this->selectedDiffFile || !$files->contains($this->selectedDiffFile)) {
            $this->selectedDiffFile = $files->first();
        }

        $this->selectedFilesForCommit = $files->all();

        $this->loadDiffForSelectedFile();
    }

    public function refreshSkipWorktreeFiles()
    {
        $result = Process::inProject($this->site->projectRoot())->run('git ls-files -v');

        $this->skipWorktreeFiles = collect(explode("\n", trim($result->output())))
            ->filter(fn ($line) => str_starts_with($line, 'S '))
            ->map(fn ($line) => trim(substr($line, 2)))
            ->values()
            ->all();
    }

    public function ignoreDiffFile($file)
    {
        $this->gitIgnoreNote = null;
        $entry = collect($this->gitDiffFiles)->firstWhere('file', $file);
        if (!$entry) {
            return;
        }

        $path = $this->site->projectRoot();

        if ($entry['label'] === 'Untracked') {
            $gitignorePath = $path . '/.gitignore';
            $existing = File::exists($gitignorePath) ? File::get($gitignorePath) : '';
            $lines = array_filter(array_map('trim', explode("\n", $existing)));

            if (!in_array($file, $lines, true)) {
                File::put($gitignorePath, rtrim($existing, "\n") . ($existing !== '' ? "\n" : '') . $file . "\n");
            }

            $this->gitIgnoreNote = "Added {$file} to .gitignore — edit or remove that line any time via the .gitignore tab below.";
        } else {
            Process::inProject($path)->run('git update-index --skip-worktree -- ' . escapeshellarg($file));
            $this->gitIgnoreNote = "{$file} will no longer show as changed, even though its local content still differs from what's committed.";
        }

        $this->refreshGitStatus();
        $this->loadGitDiff();
        $this->refreshSkipWorktreeFiles();

        if (collect($this->gitDiffFiles)->pluck('file')->contains($file)) {
            $this->gitIgnoreNote = "{$file} still shows as changed — it likely has staged changes, which skip-worktree can't hide. Try unstaging it first (git reset -- {$file}, via \"Run a git command\" below) and ignore it again.";
        }
    }

    public function unignoreFile($file)
    {
        if (!in_array($file, $this->skipWorktreeFiles, true)) {
            return;
        }

        Process::inProject($this->site->projectRoot())->run('git update-index --no-skip-worktree -- ' . escapeshellarg($file));

        $this->refreshGitStatus();
        $this->loadGitDiff();
        $this->refreshSkipWorktreeFiles();
    }

    public function selectDiffFile($file)
    {
        if (!collect($this->gitDiffFiles)->pluck('file')->contains($file)) {
            return;
        }

        $this->selectedDiffFile = $file;
        $this->loadDiffForSelectedFile();
    }

    protected function loadDiffForSelectedFile(): void
    {
        if (!$this->selectedDiffFile) {
            $this->gitDiffLines = [];
            return;
        }

        $path = $this->site->projectRoot();
        $isUntracked = (collect($this->gitDiffFiles)->firstWhere('file', $this->selectedDiffFile)['label'] ?? null) === 'Untracked';

        if ($isUntracked) {

            $diffResult = Process::inProject($path)->run(
                'git diff --no-index -- /dev/null ' . escapeshellarg($this->selectedDiffFile)
            );
        } else {

            $hasHead = Process::inProject($path)->run('git rev-parse HEAD')->successful();
            $diffResult = Process::inProject($path)->run(
                ($hasHead ? 'git diff HEAD' : 'git diff') . ' -- ' . escapeshellarg($this->selectedDiffFile)
            );
        }

        $output = rtrim($diffResult->output(), "\n");
        $this->gitDiffLines = $output === '' ? [] : collect(explode("\n", $output))
            ->map(fn ($line) => [
                'text' => $line,
                'class' => match (true) {
                    str_starts_with($line, '+') && !str_starts_with($line, '+++') => 'text-green-600 dark:text-green-400',
                    str_starts_with($line, '-') && !str_starts_with($line, '---') => 'text-red-600 dark:text-red-400',
                    str_starts_with($line, '@@') => 'text-blue-500 dark:text-blue-400',
                    default => 'text-gray-600 dark:text-gray-400',
                },
            ])
            ->all();
    }

    public function commitAndPush()
    {
        $this->commitError = null;
        $this->commitNote = null;

        $message = trim($this->commitMessage);
        if ($message === '') {
            $this->commitError = 'Enter a commit message first.';
            return;
        }

        $files = array_values(array_intersect($this->selectedFilesForCommit, collect($this->gitDiffFiles)->pluck('file')->all()));
        if (empty($files)) {
            $this->commitError = 'Select at least one file to commit.';
            return;
        }

        $path = $this->site->projectRoot();
        [$name, $email] = (new GitInitializer)->identity();

        $untracked = collect($this->gitDiffFiles)->whereIn('file', $files)->where('label', 'Untracked')->pluck('file');
        if ($untracked->isNotEmpty()) {
            Process::inProject($path)->run('git add -- ' . implode(' ', $untracked->map('escapeshellarg')->all()))->throw();
        }

        $commitResult = Process::inProject($path)->run(
            'git -c user.name=' . escapeshellarg($name)
            . ' -c user.email=' . escapeshellarg($email)
            . ' commit -m ' . escapeshellarg($message)
            . ' -- ' . implode(' ', array_map('escapeshellarg', $files))
        );

        if ($commitResult->failed()) {
            $this->commitError = trim($commitResult->errorOutput()) ?: trim($commitResult->output()) ?: 'Commit failed.';
            return;
        }

        $this->commitMessage = '';
        $this->refreshGitStatus();

        if ($this->gitStatus['hasUpstream'] ?? false) {
            $pushResult = Process::inProject($path)->run('git push');
            if ($pushResult->failed()) {
                $this->commitError = 'Committed, but push failed: ' . (trim($pushResult->errorOutput()) ?: 'unknown error');
                $this->refreshGitStatus();
                $this->loadGitDiff();
                return;
            }
            $this->commitNote = 'Committed and pushed to origin.';
        } else {
            $this->commitNote = 'Committed locally — no remote configured yet to push to.';
        }

        $this->refreshGitStatus();
        $this->loadGitDiff();
    }

    public function runManualGitCommand()
    {
        $this->manualGitError = null;
        $this->manualGitOutput = null;

        $input = trim($this->manualGitCommand);
        if ($input === '') {
            return;
        }

        $args = preg_split('/\s+/', preg_replace('/^git\s+/', '', $input));
        $result = Process::inProject($this->site->projectRoot())->run(['git', ...$args]);

        $this->manualGitOutput = trim($result->output() . $result->errorOutput()) ?: null;
        if ($result->failed()) {
            $this->manualGitError = 'Exited with status ' . $result->exitCode();
        }

        $this->refreshGitStatus();
        $this->loadGitDiff();
    }

    public function refreshProjectLog()
    {
        $result = $this->site->logFiles();
        $this->projectLogFiles = $result['files'];
        $this->projectLogTruncated = $result['truncated'];

        if (!$this->selectedProjectLog || !array_key_exists($this->selectedProjectLog, $this->projectLogFiles)) {
            $this->selectedProjectLog = array_key_first($this->projectLogFiles);
        }

        $this->tailSelectedProjectLog();
    }

    public function selectProjectLog($path)
    {
        if (!array_key_exists($path, $this->projectLogFiles)) {
            return;
        }

        $this->selectedProjectLog = $path;
        $this->tailSelectedProjectLog();
    }

    public function clearProjectLog()
    {
        if ($this->selectedProjectLog && File::exists($this->selectedProjectLog)) {
            File::put($this->selectedProjectLog, '');
        }
        $this->refreshProjectLog();
    }

    protected function tailSelectedProjectLog(): void
    {
        if (!$this->selectedProjectLog || !File::exists($this->selectedProjectLog)) {
            $this->projectLogLines = [];
            return;
        }

        $result = Process::run('tail -n 100 ' . escapeshellarg($this->selectedProjectLog));
        $this->projectLogLines = array_filter(explode("\n", $result->output()));
    }

    public $pendingDeleteMode = null;
    public $pushBeforeDeleteError = null;

    public function startDelete(string $mode)
    {
        if (!in_array($mode, ['list', 'disk'], true)) {
            return;
        }

        $this->pendingDeleteMode = $mode;
        $this->pushBeforeDeleteError = null;
        $this->refreshGitStatus();
    }

    public function cancelDeleteReview()
    {
        $this->pendingDeleteMode = null;
        $this->pushBeforeDeleteError = null;
    }

    public function pushBeforeDelete()
    {
        $this->pushBeforeDeleteError = null;
        $result = Process::inProject($this->site->projectRoot())->run('git push');

        if ($result->failed()) {
            $this->pushBeforeDeleteError = trim($result->errorOutput()) ?: 'git push failed.';
            return;
        }

        $this->refreshGitStatus();
    }

    public function confirmDelete()
    {
        $mode = $this->pendingDeleteMode;
        $this->pendingDeleteMode = null;

        if ($mode === 'disk') {
            return $this->deleteFromDisk();
        }

        return $this->delete();
    }

    public function delete()
    {
        (new SiteConfigBackup)->backup($this->site);

        (new NginxConfigGenerator)->remove($this->site);
        (new SupervisorConfigGenerator)->remove($this->site);
        $this->site->delete();

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function deleteFromDisk()
    {
        $projectRoot = $this->site->projectRoot();
        $name = $this->site->name;

        (new SiteConfigBackup)->forget($name);

        (new NginxConfigGenerator)->remove($this->site);
        (new SupervisorConfigGenerator)->remove($this->site);
        $this->site->delete();

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

        return $this->redirect(route('dashboard'), navigate: true);
    }
};
