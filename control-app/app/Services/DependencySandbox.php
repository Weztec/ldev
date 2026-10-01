<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DependencySandbox
{
    protected array $status = [];
    protected ?string $baselineDir = null;

    public static function statusKey(Site $site): string
    {
        return 'deps_sandbox_site_' . $site->id;
    }

    public static function status(Site $site): ?array
    {
        $raw = Setting::get(self::statusKey($site));
        $status = $raw ? json_decode($raw, true) : null;

        $dead = !empty($status['pid'])
            ? !File::exists('/proc/' . (int) $status['pid'])
            : now()->diffInSeconds(\Illuminate\Support\Carbon::parse($status['startedAt'] ?? now())) > 60;
        if (is_array($status) && $status['state'] === 'running' && $dead) {
            $status['state'] = 'interrupted';
        }

        return $status;
    }

    public static function dir(Site $site): string
    {
        return config('ldev.dependency_sandbox_dir') . '/' . $site->name;
    }

    public static function undoKey(Site $site): string
    {
        return 'deps_undo_site_' . $site->id;
    }

    public static function undoDir(Site $site): string
    {
        return self::dir($site) . '-undo';
    }

    public static function undoState(Site $site): ?array
    {
        $raw = Setting::get(self::undoKey($site));

        return $raw ? json_decode($raw, true) : null;
    }

    public const PRUNE_DAYS = [0, 1, 3, 7, 14, 30];

    public static function pruneDays(): int
    {
        $days = (int) Setting::get('sandbox_prune_days', '7');

        return in_array($days, self::PRUNE_DAYS, true) ? $days : 7;
    }

    public static function inventory(bool $measure = true): array
    {
        $root = config('ldev.dependency_sandbox_dir');
        if (!File::isDirectory($root)) {
            return [];
        }

        $sites = Site::all()->keyBy('name');
        $rows = [];
        foreach (File::directories($root) as $path) {
            $name = basename($path);
            $kind = 'sandbox';
            $base = $name;
            if (!$sites->has($name) && preg_match('/^(.+)-(baseline|undo)$/', $name, $m)) {
                [$base, $kind] = [$m[1], $m[2]];
            }
            $site = $sites->get($base);
            $status = $site && $kind !== 'undo' ? self::status($site) : null;
            $undo = $site && $kind === 'undo' ? self::undoState($site) : null;

            $state = match (true) {
                !$site => 'orphan',
                $kind === 'undo' => $undo ? 'undo' : 'orphan',
                !$status => 'orphan',
                $status['state'] === 'running' => 'running',
                $kind === 'baseline' => 'leftover',
                $status['state'] === 'done' => $status['verdict'] === 'pass' ? 'ready' : 'problems',
                default => 'interrupted',
            };
            $time = $undo['appliedAt'] ?? $status['finishedAt'] ?? $status['startedAt'] ?? null;

            $rows[] = [
                'name' => $name,
                'site' => $site?->name,
                'siteId' => $site?->id,
                'kind' => $kind,
                'state' => $state,
                'packages' => $status ? ($status['all'] ? 'all ' . $status['section'] . ' packages' : implode(', ', $status['packages'] ?? [])) : null,
                'time' => $time ? \Illuminate\Support\Carbon::parse($time)->getTimestamp() : File::lastModified($path),
                'bytes' => $measure ? self::diskUse($path) : null,
            ];
        }

        return collect($rows)->sortByDesc('time')->values()->all();
    }

    public static function diskUse(string $path): ?int
    {
        $btrfs = Process::timeout(120)->run('btrfs filesystem du -s --raw ' . escapeshellarg($path));
        if ($btrfs->successful() && preg_match('/^\s*\d+\s+(\d+)\s+\d+\s+/m', $btrfs->output(), $m)) {
            return (int) $m[1];
        }

        $du = Process::timeout(120)->run('du -s --block-size=1 ' . escapeshellarg($path));

        return $du->successful() && preg_match('/^(\d+)/', $du->output(), $m) ? (int) $m[1] : null;
    }

    public function remove(string $name): array
    {
        $row = collect(self::inventory(false))->firstWhere('name', $name);
        if (!$row) {
            return ['ok' => false, 'output' => 'That sandbox no longer exists.'];
        }
        if ($row['state'] === 'running') {
            return ['ok' => false, 'output' => 'That sandbox is still running a test, so it was not deleted.'];
        }

        $site = $row['siteId'] ? Site::find($row['siteId']) : null;
        if ($site && $row['kind'] === 'sandbox') {
            $this->discard($site);
        } elseif ($site && $row['kind'] === 'undo') {
            File::deleteDirectory(self::undoDir($site));
            Setting::set(self::undoKey($site), '');
        } else {
            File::deleteDirectory(config('ldev.dependency_sandbox_dir') . '/' . $name);
        }

        return ['ok' => true, 'output' => 'Deleted ' . $name . '.'];
    }

    public function prune(?int $days = null): array
    {
        $days ??= self::pruneDays();
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = [];

        foreach (self::inventory(false) as $row) {
            $expired = $days > 0 && $row['time'] < $cutoff && in_array($row['state'], ['ready', 'problems', 'interrupted'], true);
            if (in_array($row['state'], ['orphan', 'leftover'], true) || $expired) {
                if ($this->remove($row['name'])['ok']) {
                    $removed[] = $row['name'];
                }
            }
        }

        $known = Site::pluck('name')->all();
        foreach (File::glob(config('ldev.dependency_sandbox_dir') . '/*.log') as $log) {
            if (!in_array(preg_replace('/-check$/', '', basename($log, '.log')), $known, true)) {
                File::delete($log);
            }
        }

        return $removed;
    }

    public static function lockFiles(string $section): array
    {
        return DependencyHealthChecker::manager($section) === 'npm' ? ['package.json', 'package-lock.json'] : ['composer.json', 'composer.lock'];
    }

    public function launch(Site $site, string $section, array $packages, bool $all, bool $major = false): void
    {
        $this->status = [
            'state' => 'running',
            'section' => $section,
            'packages' => $packages,
            'all' => $all,
            'major' => $major,
            'startedAt' => now()->toIso8601String(),
            'finishedAt' => null,
            'pid' => null,
            'projectHashes' => $this->hashes($site->projectRoot(), $section),
            'steps' => [],
            'changes' => [],
            'notes' => [],
            'verdict' => null,
        ];
        $this->save($site);

        File::ensureDirectoryExists(config('ldev.dependency_sandbox_dir'));
        $args = implode(' ', array_map('escapeshellarg', array_merge(
            [(string) $site->id, $section],
            $all ? ['--all'] : [],
            $major ? ['--major'] : [],
            $packages ? array_merge(['--'], $packages) : []
        )));
        $log = config('ldev.dependency_sandbox_dir') . '/' . $site->name . '.log';

        Process::path(base_path())->run('bash -c ' . escapeshellarg(
            'setsid nohup ' . escapeshellarg(\Illuminate\Support\php_binary()) . ' artisan ldev:dependency-sandbox ' . $args
            . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &'
        ));
    }

    public function run(Site $site, string $section, array $packages, bool $all, bool $major = false): void
    {
        $this->status = self::status($site) ?? [];
        $this->status['pid'] = getmypid();
        $this->status['state'] = 'running';
        $root = $site->projectRoot();
        $dir = self::dir($site);
        $php = TestRunner::sitePhp($site);
        $manager = DependencyHealthChecker::manager($section);

        $before = $this->lockVersions($root, $section);
        $this->status['projectHashes'] = $this->hashes($root, $section);
        $this->status['projectFingerprint'] = $this->fingerprint($root);
        $this->status['progress'] = ['done' => 0, 'total' => $this->plannedSteps($root, $manager, $section, $packages, $major), 'label' => 'Copy project into sandbox'];
        $this->save($site);

        if (!$this->step($site, 'Copy project into sandbox', fn () => $this->copyProject($root, $dir))) {
            $this->finish($site);
            return;
        }

        $updated = $manager === 'npm'
            ? $this->updateNpm($site, $dir, $section, $packages, $all, $major)
            : $this->updateComposer($site, $dir, $php, $packages, $major);

        if (!$updated) {
            $this->adviceNotes($site, $section, $packages, $all);
            $this->finish($site);
            return;
        }

        $this->status['changes'] = $this->diffVersions($before, $this->lockVersions($dir, $section), $section);
        $this->save($site);

        if ($manager === 'npm') {
            $this->runNpmChecks($site, $dir, $php);
        } else {
            $this->runComposerChecks($site, $dir, $php);
        }

        $this->finish($site);
    }

    protected function updateComposer(Site $site, string $dir, string $php, array $packages, bool $major): bool
    {
        $composer = escapeshellarg($php) . ' "$(command -v composer)"';

        if ($major && $packages) {
            $groups = $this->majorTargets($site, $dir, 'composer', $packages);
            if (!$groups) {
                $this->note('danger', 'No newer major version is known for the selected packages. Click Check now, then test again.');
                return false;
            }
            $names = [];
            foreach ($groups as $group => $targets) {
                $dev = $group === 'require-dev' ? '--dev ' : '';
                $specs = array_map(fn ($name, $version) => $name . ':' . $version, array_keys($targets), $targets);
                $names = array_merge($names, array_keys($targets));
                $ok = $this->step($site, 'Raise constraints: composer require ' . $dev . implode(' ', $specs) . ' --no-update', fn () => Process::inProject($dir)->timeout(300)->run(
                    $composer . ' require ' . $dev . implode(' ', array_map('escapeshellarg', $specs)) . ' --no-update --no-interaction'
                ));
                if (!$ok) {
                    return false;
                }
            }
            $ok = $this->step($site, 'composer update -W ' . implode(' ', $names), fn () => Process::inProject($dir)->timeout(900)->run(
                $composer . ' update -W ' . implode(' ', array_map('escapeshellarg', $names)) . ' --no-interaction --no-progress --no-scripts'
            ));
            if (!$ok) {
                return false;
            }
        } else {
            $args = implode(' ', array_map('escapeshellarg', $packages));
            $ok = $this->step($site, 'composer update', fn () => Process::inProject($dir)->timeout(900)->run(
                $composer . ' update' . ($args === '' ? '' : ' -W ' . $args) . ' --no-interaction --no-progress --no-scripts'
            ));
            if (!$ok) {
                return false;
            }
        }

        return $this->step($site, 'Rebuild autoloader and discover packages', fn () => Process::inProject($dir)->timeout(300)->run(
            $composer . ' dump-autoload --no-interaction'
        ));
    }

    protected function updateNpm(Site $site, string $dir, string $section, array $packages, bool $all, bool $major): bool
    {
        $npm = fn (string $command) => DependencyHealthChecker::npmProcess($site, $dir)->timeout(900)->run($command);

        if ($major && $packages) {
            $groups = $this->majorTargets($site, $dir, 'npm', $packages);
            if (!$groups) {
                $this->note('danger', 'No newer major version is known for the selected packages. Click Check now, then test again.');
                return false;
            }
            $specs = [];
            foreach ($groups as $targets) {
                foreach ($targets as $name => $range) {
                    $specs[] = $name . '@' . $range;
                }
            }
            if (!$this->step($site, 'Raise package.json ranges: ' . implode(' ', $specs), fn () => $this->raiseNpmRanges($dir . '/package.json', $groups))) {
                return false;
            }

            return $this->step($site, 'npm install', fn () => $npm('npm install'));
        }

        if ($section === 'npm-security' && $all) {
            return $this->step($site, 'npm audit fix', fn () => $npm('npm audit fix'));
        }

        $args = implode(' ', array_map('escapeshellarg', $packages));

        return $this->step($site, 'npm update', fn () => $npm(trim('npm update ' . $args)));
    }

    protected function majorTargets(Site $site, string $dir, string $manager, array $packages): array
    {
        $cached = DependencyHealthChecker::cached($site);
        $manifest = json_decode((string) @file_get_contents($dir . '/' . ($manager === 'npm' ? 'package.json' : 'composer.json')), true) ?: [];
        $keys = $manager === 'npm' ? ['dependencies', 'devDependencies', 'optionalDependencies'] : ['require', 'require-dev'];

        $groups = [];
        foreach ($packages as $package) {
            $latest = DependencyHealthChecker::outdatedRow($cached, $manager, $package)['latest'] ?? null;
            if (!$latest || !preg_match('/^[0-9][0-9A-Za-z.+-]*$/', $latest)) {
                continue;
            }
            $group = collect($keys)->first(fn ($key) => isset($manifest[$key][$package])) ?? $keys[0];
            $groups[$group][$package] = '^' . $latest;
        }

        return $groups;
    }

    protected function raiseNpmRanges(string $file, array $groups): bool
    {
        $raw = (string) file_get_contents($file);
        $manifest = json_decode($raw);
        if (!$manifest instanceof \stdClass) {
            throw new \RuntimeException('package.json is not valid JSON.');
        }

        foreach ($groups as $group => $targets) {
            if (!isset($manifest->{$group}) || !$manifest->{$group} instanceof \stdClass) {
                $manifest->{$group} = new \stdClass;
            }
            foreach ($targets as $name => $range) {
                $manifest->{$group}->{$name} = $range;
            }
        }

        $indent = preg_match('/^\{\s*\n([ \t]+)"/', $raw, $m) ? $m[1] : '  ';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = preg_replace_callback('/^(?: {4})+/m', fn ($m) => str_repeat($indent, intdiv(strlen($m[0]), 4)), $json);

        return file_put_contents($file, $json . (str_ends_with($raw, "\n") ? "\n" : '')) !== false;
    }

    public function apply(Site $site): array
    {
        $status = self::status($site);
        if (!$status || $status['state'] !== 'done') {
            return ['ok' => false, 'output' => 'No finished sandbox run to apply.'];
        }
        if (($status['verdict'] ?? null) !== 'pass') {
            return ['ok' => false, 'output' => 'The sandbox test found problems, so this update cannot be applied. Read the notes, fix the cause, and test again.'];
        }
        if (!($status['changes'] ?? [])) {
            return ['ok' => false, 'output' => 'The sandbox run changed no package versions, so there is nothing to apply.'];
        }

        $root = $site->projectRoot();
        $dir = self::dir($site);
        $section = $status['section'];
        $files = self::lockFiles($section);

        if ($this->hashes($root, $section) !== ($status['projectHashes'] ?? null)) {
            return ['ok' => false, 'output' => 'The project\'s ' . implode('/', $files) . ' changed after the sandbox run. Test again before applying.'];
        }
        if ($this->fingerprint($root) !== ($status['projectFingerprint'] ?? null)) {
            return ['ok' => false, 'output' => 'The project\'s code changed after the sandbox run (new commits or uncommitted edits), so the test result no longer covers it. Test again before applying.'];
        }
        foreach ($files as $file) {
            if (!File::exists($dir . '/' . $file)) {
                return ['ok' => false, 'output' => 'The sandbox copy is missing ' . $file . '. Test again before applying.'];
            }
        }

        $undo = self::undoDir($site);
        File::deleteDirectory($undo);
        File::ensureDirectoryExists($undo);
        $existed = [];
        foreach ($files as $file) {
            $existed[$file] = File::exists($root . '/' . $file);
            if ($existed[$file] && !File::copy($root . '/' . $file, $undo . '/' . $file)) {
                return ['ok' => false, 'output' => 'Could not back up ' . $file . ', so nothing was changed.'];
            }
        }

        foreach ($files as $file) {
            File::copy($dir . '/' . $file, $root . '/' . $file);
        }

        $install = $this->install($site, $section, $root);
        $failure = $install->successful() ? $this->verifyProject($site, $status, $root) : 'The install failed.';
        $output = $install->output() . "\n" . $install->errorOutput();

        if ($failure !== null) {
            $restored = $this->restore($site, $section, $root, $undo, $existed);
            File::deleteDirectory($undo);

            return ['ok' => false, 'output' => $failure . ' '
                . ($restored === null
                    ? 'The project was put back exactly as it was before (original ' . implode('/', $files) . ' restored and reinstalled).'
                    : 'The original ' . implode('/', $files) . ' were restored, but reinstalling them failed: ' . $restored . ' Run ' . ($this->isNpm($section) ? 'npm install' : 'composer install') . ' in the project.')
                . "\n\n" . $this->tail($output)];
        }

        Setting::set(self::undoKey($site), json_encode([
            'section' => $section,
            'appliedAt' => now()->toIso8601String(),
            'existed' => $existed,
            'hashes' => $this->hashes($root, $section),
            'changes' => $status['changes'],
        ]));
        $this->discard($site);

        return ['ok' => true, 'output' => 'Applied. The previous ' . implode('/', $files) . ' are kept, so Undo can put them back.' . "\n\n" . $this->tail($output)];
    }

    public function undo(Site $site): array
    {
        $state = self::undoState($site);
        if (!$state) {
            return ['ok' => false, 'output' => 'There is no applied update to undo.'];
        }

        $root = $site->projectRoot();
        $section = $state['section'];
        if ($this->hashes($root, $section) !== ($state['hashes'] ?? null)) {
            return ['ok' => false, 'output' => 'The project\'s ' . implode('/', self::lockFiles($section)) . ' changed after the update was applied. Undo would overwrite those changes, so it was not done. Use git to go back instead.'];
        }

        $failed = $this->restore($site, $section, $root, self::undoDir($site), $state['existed'] ?? []);
        if ($failed !== null) {
            return ['ok' => false, 'output' => 'The original files were restored, but reinstalling them failed: ' . $failed . ' Run ' . ($this->isNpm($section) ? 'npm install' : 'composer install') . ' in the project.'];
        }

        File::deleteDirectory(self::undoDir($site));
        Setting::set(self::undoKey($site), '');

        return ['ok' => true, 'output' => 'The update was undone and the previous versions reinstalled.'];
    }

    protected function restore(Site $site, string $section, string $root, string $undo, array $existed): ?string
    {
        foreach (self::lockFiles($section) as $file) {
            if (!empty($existed[$file])) {
                if (!File::exists($undo . '/' . $file) || !File::copy($undo . '/' . $file, $root . '/' . $file)) {
                    return 'the backup of ' . $file . ' is missing.';
                }
            } else {
                File::delete($root . '/' . $file);
            }
        }

        $install = $this->install($site, $section, $root);
        if (!$install->successful() && $this->isNpm($section)) {
            $install = DependencyHealthChecker::npmProcess($site, $root)->timeout(900)->run('npm install');
        }

        return $install->successful() ? null : $this->firstLine($install->errorOutput() ?: $install->output());
    }

    protected function install(Site $site, string $section, string $root)
    {
        return $this->isNpm($section)
            ? DependencyHealthChecker::npmProcess($site, $root)->timeout(900)->run('npm ci')
            : Process::inProject($root)->timeout(900)->run(escapeshellarg(TestRunner::sitePhp($site)) . ' "$(command -v composer)" install --no-interaction --no-progress');
    }

    protected function verifyProject(Site $site, array $status, string $root): ?string
    {
        $booted = collect($status['steps'] ?? [])->contains(fn ($step) => $step['name'] === 'App boots and routes load' && $step['status'] === 'ok');
        if ($this->isNpm($status['section']) || !$booted || !File::exists($root . '/artisan')) {
            return null;
        }

        $check = Process::inProject($root)->timeout(300)->run(escapeshellarg(TestRunner::sitePhp($site)) . ' artisan route:list --json');

        return $check->successful() ? null : 'After installing, the app no longer boots: ' . $this->firstLine($check->errorOutput() ?: $check->output());
    }

    protected function isNpm(string $section): bool
    {
        return DependencyHealthChecker::manager($section) === 'npm';
    }

    protected function fingerprint(string $root): ?string
    {
        if (!File::exists($root . '/.git')) {
            return null;
        }

        $git = fn (string $args) => Process::path($root)->timeout(60)->run('git --no-optional-locks ' . $args);
        $head = $git('rev-parse HEAD');
        $diff = $git('diff HEAD --binary');
        $untracked = $git('ls-files --others --exclude-standard -z');
        if (!$head->successful() || !$diff->successful() || !$untracked->successful()) {
            return 'unreadable';
        }

        $hash = hash_init('sha256');
        hash_update($hash, $head->output() . "\0" . $diff->output());
        foreach (array_filter(explode("\0", $untracked->output())) as $file) {
            hash_update($hash, "\0" . $file . "\0" . (is_file($root . '/' . $file) ? (string) md5_file($root . '/' . $file) : ''));
        }

        return hash_final($hash);
    }

    public function discard(Site $site): void
    {
        $status = self::status($site);
        if ($status && $status['state'] === 'running') {
            return;
        }

        File::deleteDirectory(self::dir($site));
        File::deleteDirectory(self::dir($site) . '-baseline');
        File::delete(config('ldev.dependency_sandbox_dir') . '/' . $site->name . '.log');
        Setting::set(self::statusKey($site), '');
    }

    protected function plannedSteps(string $root, string $manager, string $section, array $packages, bool $major): int
    {
        if ($manager === 'npm') {
            return 1 + ($major && $packages ? 2 : 1) + 1 + 1;
        }

        $groups = 1;
        if ($major && $packages) {
            $manifest = json_decode((string) @file_get_contents($root . '/composer.json'), true) ?: [];
            $dev = count(array_intersect($packages, array_keys($manifest['require-dev'] ?? [])));
            $groups = ($dev > 0 ? 1 : 0) + ($dev < count($packages) ? 1 : 0) + 1;
        }

        return 1 + $groups + 1 + (File::exists($root . '/artisan') ? 2 : 0) + 1;
    }

    protected function progress(Site $site, string $label, ?array $sub = null): void
    {
        $done = collect($this->status['steps'] ?? [])->whereIn('status', ['ok', 'failed'])->count();
        $total = max((int) ($this->status['progress']['total'] ?? 1), $done + 1);
        $this->status['progress'] = ['done' => $done, 'total' => $total, 'label' => $label, 'sub' => $sub];
        $this->save($site);
    }

    protected function unchangedNote(Site $site): void
    {
        $section = $this->status['section'] ?? '';
        if (!empty($this->status['major']) || !in_array($section, ['composer', 'npm'], true) || collect($this->status['notes'] ?? [])->contains('level', 'danger')) {
            return;
        }

        $cached = DependencyHealthChecker::cached($site);
        $changed = array_column($this->status['changes'] ?? [], 'name');
        $wanted = $this->status['all'] ? null : ($this->status['packages'] ?? []);
        $items = collect($cached[$section === 'npm' ? 'npmOutdated' : 'composerOutdated'] ?? [])
            ->filter(fn ($row) => ($wanted === null || in_array($row['name'], $wanted, true)) && !in_array($row['name'], $changed, true))
            ->map(fn ($row) => $row['name'] . ' stays at ' . ($row['current'] ?? '?') . ' (latest ' . $row['latest'] . ($row['range'] ?? null ? ', your range is ' . $row['range'] : '') . ')')
            ->values()->all();

        if ($items) {
            $this->note('warning', 'These packages did not change, because their newer release is outside the version range in ' . ($section === 'npm' ? 'package.json' : 'composer.json') . '. Applying this will not upgrade them:', $items,
                'Tick them under Need a major upgrade and use Test ticked major upgrades to raise the range.');
        }
    }

    protected function adviceNotes(Site $site, string $section, array $packages, bool $all): void
    {
        $cached = DependencyHealthChecker::cached($site);
        $rows = in_array($section, ['security', 'npm-security'], true)
            ? collect($cached['advisories'] ?? [])->where('manager', DependencyHealthChecker::manager($section))->map(fn ($row) => ['name' => $row['package']] + $row)
            : collect($cached[$section === 'npm' ? 'npmOutdated' : 'composerOutdated'] ?? []);

        foreach ($rows->unique('name') as $row) {
            $advice = $row['advice'] ?? null;
            if ($advice && ($all || in_array($row['name'], $packages, true)) && $advice['level'] !== 'ok') {
                $this->note($advice['level'] === 'blocked' ? 'warning' : 'info', $row['name'] . ': ' . $advice['summary'], $advice['lines'] ?? [], $advice['action']);
            }
        }
    }

    protected function runComposerChecks(Site $site, string $dir, string $php): void
    {
        if (!File::exists($dir . '/artisan')) {
            $this->note('warning', 'Not a Laravel project, so only the install itself was checked.');
        } else {
            $artisan = escapeshellarg($php) . ' artisan ';
            foreach ([
                'App boots and routes load' => $artisan . 'route:list --json',
                'Blade views compile' => $artisan . 'view:cache',
            ] as $name => $command) {
                $this->check($site, $name, fn ($path) => Process::inProject($path)->timeout(300)->run($command));
            }
        }

        $this->runTests($site, $dir, $php);

        $audit = Process::inProject($dir)->timeout(120)->run(escapeshellarg($php) . ' "$(command -v composer)" audit --format=json --locked --no-interaction');
        $data = json_decode($audit->output(), true);
        if (is_array($data) && array_key_exists('advisories', $data)) {
            $remaining = array_keys(array_filter((array) $data['advisories']));
            $previous = DependencyHealthChecker::updatablePackages(DependencyHealthChecker::cached($site), 'security');
            $fixed = array_diff($previous, $remaining);
            if ($fixed) {
                $this->note('info', 'Fixes security advisories for:', $fixed);
            }
            if ($remaining) {
                $this->note('warning', 'Security advisories still open after this update:', $remaining);
            }
        }
    }

    protected function runNpmChecks(Site $site, string $dir, string $php): void
    {
        $scripts = json_decode((string) @file_get_contents($dir . '/package.json'), true)['scripts'] ?? [];
        if (!isset($scripts['build'])) {
            $this->note('warning', 'package.json has no build script, so the front-end build was not checked.');
        } else {
            $this->check($site, 'Front-end build (npm run build)', fn ($path) => DependencyHealthChecker::npmProcess($site, $path)->timeout(600)->run('npm run build'));
        }

        $this->runTests($site, $dir, $php, true);

        $audit = DependencyHealthChecker::npmProcess($site, $dir)->timeout(120)->run('npm audit --json');
        $rows = DependencyHealthChecker::npmAdvisories(json_decode($audit->output() ?: '{}', true) ?: []);
        if ($rows !== null) {
            $remaining = array_values(array_unique(array_column($rows, 'package')));
            $fixed = array_diff(DependencyHealthChecker::updatablePackages(DependencyHealthChecker::cached($site), 'npm-security'), $remaining);
            if ($fixed) {
                $this->note('info', 'Fixes npm security advisories for:', $fixed);
            }
            if ($remaining) {
                $this->note('warning', 'npm security advisories still open after this update:', $remaining);
            }
        }
    }

    protected function runTests(Site $site, string $dir, string $php, bool $javascriptOnly = false): void
    {
        $found = TestRunner::discover($dir);
        if ($javascriptOnly) {
            $found['files'] = array_intersect_key($found['files'], array_filter($found['styles'], fn ($style) => TestRunner::isJavascript($style)));
            if (!$found['files']) {
                return;
            }
        } elseif (!$found['files'] || (!$found['runner'] && !$found['jsRunner'])) {
            $this->note('warning', 'No test suite found (Pest, PHPUnit, Vitest or Jest plus test files), so tests were not run.');
            return;
        }

        $overrides = TestEnvironment::overrides($site);
        $unsafe = TestRunner::unsafeDatabase($dir, $found['config'], $overrides);
        if ($unsafe) {
            $this->note('warning', 'Tests skipped: ' . $unsafe);
            return;
        }

        $selection = array_fill_keys(array_keys($found['files']), []);
        $failingIds = fn (array $results) => collect($results)->whereIn('status', ['failed', 'error'])->map(fn ($r) => $r['file'] . ' › ' . $r['name'])->values()->all();

        $runners = implode(' + ', array_filter([$javascriptOnly ? null : $found['runner'], $found['jsRunner']]));
        $this->status['steps'][] = ['name' => 'Test suite (' . $runners . ', ' . count($selection) . ' files)', 'status' => 'running', 'output' => ''];
        $this->progress($site, 'Test suite');

        $index = 0;
        $results = (new TestRunner)->runFiles($dir, $php, (string) $found['runner'], $selection, function (?string $current) use ($site, $selection, &$index) {
            $this->progress($site, 'Test suite: ' . $current, [$index++, count($selection)]);
        }, $overrides, $site);
        $failing = $failingIds($results);
        $summary = collect($results)->countBy('status')->map(fn ($n, $k) => $n . ' ' . $k)->implode(', ');
        $this->setLastStep($failing ? 'failed' : 'ok', $summary . ($failing ? "\n\n" . implode("\n", array_slice($failing, 0, 30)) : ''));

        if (!$failing) {
            return;
        }

        $this->progress($site, 'Re-running the failing tests without the update, to see which failures it caused');
        $baseline = $this->baseline($site);
        $baselineResults = $baseline ? (new TestRunner)->runFiles($baseline, $php, (string) $found['runner'], $selection, null, $overrides, $site) : [];
        $baselineFailing = $failingIds($baselineResults);

        $new = array_values(array_diff($failing, $baselineFailing));
        $old = array_values(array_intersect($failing, $baselineFailing));

        if ($new) {
            $this->note('danger', count($new) . ' test(s) fail only after the update:', array_merge(array_slice($new, 0, 20), count($new) > 20 ? ['… and ' . (count($new) - 20) . ' more (see the Test suite step)'] : []));
        }
        if ($old) {
            $this->note('info', count($old) . ' test(s) already fail without the update, so they are not caused by it:', array_merge(array_slice($old, 0, 20), count($old) > 20 ? ['… and ' . (count($old) - 20) . ' more'] : []));
            foreach (TestRunner::hints($baselineResults) as $hint) {
                $this->note('info', $hint);
            }
        }
    }

    protected function check(Site $site, string $name, callable $run): void
    {
        $ok = $this->step($site, $name, fn () => $run(self::dir($site)), false);
        if ($ok) {
            return;
        }

        $baseline = $this->baseline($site);
        if ($baseline && !$run($baseline)->successful()) {
            $this->note('info', $name . ' also fails without the update, so it is not caused by it.');
            return;
        }

        $last = end($this->status['steps']);
        $this->note('danger', $name . ' fails after the update: ' . $this->firstLine($last['output'] ?? ''));
    }

    protected function step(Site $site, string $name, callable $run, bool $noteOnFailure = true): bool
    {
        $this->status['steps'][] = ['name' => $name, 'status' => 'running', 'output' => ''];
        $this->progress($site, $name);

        try {
            $result = $run();
            $ok = $result === true || (is_object($result) && $result->successful());
            $output = is_object($result) ? $result->output() . "\n" . $result->errorOutput() : '';
        } catch (\Throwable $e) {
            $ok = false;
            $output = $e->getMessage();
        }

        $this->setLastStep($ok ? 'ok' : 'failed', $output);
        if (!$ok && $noteOnFailure) {
            $this->note('danger', $name . ' failed: ' . $this->firstLine($output));
        }
        $this->save($site);

        return $ok;
    }

    protected function finish(Site $site): void
    {
        $this->unchangedNote($site);

        $majors = collect($this->status['changes'] ?? [])->where('major', true)->map(fn ($change) => $change['name'] . ' ' . $change['from'] . ' → ' . $change['to'])->values()->all();
        if ($majors) {
            $this->note('warning', 'These packages jump a major version. Read their upgrade guides:', $majors);
        }

        if (!($this->status['changes'] ?? []) && !collect($this->status['notes'])->contains('level', 'danger')) {
            $this->note('info', 'Nothing changed. These packages are already at the newest version your constraints allow.');
        }

        if ($this->baselineDir) {
            File::deleteDirectory($this->baselineDir);
        }

        $this->status['state'] = 'done';
        $this->status['finishedAt'] = now()->toIso8601String();
        $this->status['progress'] = null;
        $this->status['verdict'] = collect($this->status['notes'])->contains('level', 'danger') ? 'issues' : 'pass';
        $this->save($site);

        (new DesktopNotifier)->send(
            'Sandbox test finished: ' . $site->name,
            $this->status['verdict'] === 'pass'
                ? 'No problems found. Open the project to review and apply the update.'
                : 'Problems found. Open the project to read the notes.',
            $this->status['verdict'] === 'pass' ? 'normal' : 'critical'
        );
    }

    protected function copyProject(string $from, string $to): bool
    {
        File::deleteDirectory($to);
        $result = Process::run('cp -a --reflink=auto ' . escapeshellarg($from) . ' ' . escapeshellarg($to));
        if (!$result->successful()) {
            throw new \RuntimeException($result->errorOutput());
        }

        foreach (File::glob($to . '/bootstrap/cache/*.php') as $file) {
            File::delete($file);
        }

        return true;
    }

    protected function baseline(Site $site): ?string
    {
        if ($this->baselineDir === null) {
            $dir = self::dir($site) . '-baseline';
            try {
                $this->copyProject($site->projectRoot(), $dir);
                $this->baselineDir = $dir;
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->baselineDir;
    }

    protected function lockVersions(string $root, string $section): array
    {
        $versions = [];

        if (DependencyHealthChecker::manager($section) === 'npm') {
            $lock = json_decode((string) @file_get_contents($root . '/package-lock.json'), true);
            foreach ($lock['packages'] ?? [] as $path => $pkg) {
                if (str_starts_with($path, 'node_modules/') && !str_contains(substr($path, 13), 'node_modules/') && isset($pkg['version'])) {
                    $versions[substr($path, 13)] = $pkg['version'];
                }
            }
            return $versions;
        }

        $lock = json_decode((string) @file_get_contents($root . '/composer.lock'), true);
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $versions[$pkg['name']] = ltrim($pkg['version'], 'v');
        }

        return $versions;
    }

    protected function diffVersions(array $before, array $after, string $section): array
    {
        $changes = [];
        foreach ($after as $name => $version) {
            $from = $before[$name] ?? null;
            if ($from === $version) {
                continue;
            }
            $changes[] = [
                'name' => $name,
                'from' => $from ?? 'new',
                'to' => $version,
                'major' => $from !== null && explode('.', $from)[0] !== explode('.', $version)[0],
            ];
        }
        foreach (array_diff_key($before, $after) as $name => $version) {
            $changes[] = ['name' => $name, 'from' => $version, 'to' => 'removed', 'major' => false];
        }

        return $changes;
    }

    protected function hashes(string $root, string $section): array
    {
        $hashes = [];
        foreach (self::lockFiles($section) as $file) {
            $hashes[$file] = File::exists($root . '/' . $file) ? md5_file($root . '/' . $file) : null;
        }

        return $hashes;
    }

    protected function note(string $level, string $text, array $items = [], ?string $action = null): void
    {
        $this->status['notes'][] = ['level' => $level, 'text' => $text, 'items' => array_values($items), 'action' => $action];
    }

    protected function setLastStep(string $status, string $output): void
    {
        $index = array_key_last($this->status['steps']);
        $this->status['steps'][$index]['status'] = $status;
        $this->status['steps'][$index]['output'] = $this->tail($output);
    }

    protected function save(Site $site): void
    {
        Setting::set(self::statusKey($site), json_encode($this->status));
    }

    protected function tail(string $text, int $lines = 40): string
    {
        $text = implode("\n", array_slice(explode("\n", trim($text)), -$lines));

        return strlen($text) > 6000 ? '…' . substr($text, -6000) : $text;
    }

    protected function firstLine(string $text): string
    {
        if (preg_match('/^.*(could not be resolved|ERESOLVE|ETARGET).*$/mi', $text, $m)) {
            return mb_strimwidth(trim($m[0]), 0, 200, '…');
        }
        foreach (explode("\n", trim($text)) as $line) {
            if (preg_match('/error|exception|fail/i', $line)) {
                return mb_strimwidth(trim($line), 0, 200, '…');
            }
        }

        return mb_strimwidth(trim(strtok(trim($text), "\n") ?: 'no output'), 0, 200, '…');
    }
}
