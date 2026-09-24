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

        if (!$this->step($site, 'Copy project into sandbox', fn () => $this->copyProject($root, $dir))) {
            $this->finish($site);
            return;
        }

        $updated = $manager === 'npm'
            ? $this->updateNpm($site, $dir, $section, $packages, $all, $major)
            : $this->updateComposer($site, $dir, $php, $packages, $major);

        if (!$updated) {
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
            foreach ($groups as $dev => $targets) {
                $label = 'composer require ' . ($dev ? '--dev ' : '') . implode(' ', $targets);
                $ok = $this->step($site, $label, fn () => Process::inProject($dir)->timeout(900)->run(
                    $composer . ' require ' . ($dev ? '--dev ' : '') . implode(' ', array_map('escapeshellarg', $targets)) . ' -W --no-interaction --no-progress --no-scripts'
                ));
                if (!$ok) {
                    return false;
                }
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
            foreach ($this->majorTargets($site, $dir, 'npm', $packages) as $dev => $targets) {
                $command = 'npm install ' . ($dev ? '--save-dev ' : '') . implode(' ', array_map('escapeshellarg', $targets));
                if (!$this->step($site, 'npm install ' . ($dev ? '--save-dev ' : '') . implode(' ', $targets), fn () => $npm($command))) {
                    return false;
                }
            }
            return true;
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
        $devKey = $manager === 'npm' ? 'devDependencies' : 'require-dev';

        $groups = [];
        foreach ($packages as $package) {
            $latest = DependencyHealthChecker::outdatedRow($cached, $manager, $package)['latest'] ?? null;
            if (!$latest || !preg_match('/^[0-9][0-9A-Za-z.+-]*$/', $latest)) {
                continue;
            }
            $dev = isset($manifest[$devKey][$package]);
            $groups[$dev ? 1 : 0][] = $manager === 'npm' ? $package . '@^' . $latest : $package . ':^' . $latest;
        }
        ksort($groups);

        return $groups;
    }

    public function apply(Site $site): array
    {
        $status = self::status($site);
        if (!$status || $status['state'] !== 'done') {
            return ['ok' => false, 'output' => 'No finished sandbox run to apply.'];
        }

        $root = $site->projectRoot();
        $dir = self::dir($site);
        $section = $status['section'];

        if ($this->hashes($root, $section) !== ($status['projectHashes'] ?? null)) {
            return ['ok' => false, 'output' => 'The project\'s ' . implode('/', self::lockFiles($section)) . ' changed after the sandbox run. Test again before applying.'];
        }

        foreach (self::lockFiles($section) as $file) {
            if (File::exists($dir . '/' . $file)) {
                File::copy($dir . '/' . $file, $root . '/' . $file);
            }
        }

        $process = DependencyHealthChecker::manager($section) === 'npm'
            ? DependencyHealthChecker::npmProcess($site, $root)->timeout(900)->run('npm install')
            : Process::inProject($root)->timeout(900)->run(escapeshellarg(TestRunner::sitePhp($site)) . ' "$(command -v composer)" install --no-interaction --no-progress');

        if ($process->successful()) {
            $this->discard($site);
        }

        return ['ok' => $process->successful(), 'output' => $this->tail($process->output() . "\n" . $process->errorOutput())];
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
                $this->note('info', 'Fixes security advisories for: ' . implode(', ', $fixed) . '.');
            }
            if ($remaining) {
                $this->note('warning', 'Security advisories still open after this update: ' . implode(', ', $remaining) . '.');
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
                $this->note('info', 'Fixes npm security advisories for: ' . implode(', ', $fixed) . '.');
            }
            if ($remaining) {
                $this->note('warning', 'npm security advisories still open after this update: ' . implode(', ', $remaining) . '.');
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
        $this->save($site);

        $results = (new TestRunner)->runFiles($dir, $php, (string) $found['runner'], $selection, null, $overrides, $site);
        $failing = $failingIds($results);
        $summary = collect($results)->countBy('status')->map(fn ($n, $k) => $n . ' ' . $k)->implode(', ');
        $this->setLastStep($failing ? 'failed' : 'ok', $summary . ($failing ? "\n\n" . implode("\n", array_slice($failing, 0, 30)) : ''));

        if (!$failing) {
            return;
        }

        $baseline = $this->baseline($site);
        $baselineResults = $baseline ? (new TestRunner)->runFiles($baseline, $php, (string) $found['runner'], $selection, null, $overrides, $site) : [];
        $baselineFailing = $failingIds($baselineResults);

        $new = array_values(array_diff($failing, $baselineFailing));
        $old = array_values(array_intersect($failing, $baselineFailing));

        if ($new) {
            $this->note('danger', count($new) . ' test(s) fail only after the update: ' . implode('; ', array_slice($new, 0, 10)) . (count($new) > 10 ? '; …' : '') . '.');
        }
        if ($old) {
            $this->note('info', count($old) . ' test(s) already fail without the update, so they are not caused by it.');
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
        $this->save($site);

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
        foreach ($this->status['changes'] ?? [] as $change) {
            if ($change['major']) {
                $this->note('warning', $change['name'] . ' jumps a major version (' . $change['from'] . ' → ' . $change['to'] . '). Read its upgrade guide.');
            }
        }

        if (!($this->status['changes'] ?? []) && !collect($this->status['notes'])->contains('level', 'danger')) {
            $this->note('info', 'Nothing changed. These packages are already at the newest version your constraints allow.');
        }

        if ($this->baselineDir) {
            File::deleteDirectory($this->baselineDir);
        }

        $this->status['state'] = 'done';
        $this->status['finishedAt'] = now()->toIso8601String();
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

    protected function note(string $level, string $text): void
    {
        $this->status['notes'][] = ['level' => $level, 'text' => $text];
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
        foreach (explode("\n", trim($text)) as $line) {
            if (preg_match('/error|exception|fail/i', $line)) {
                return mb_strimwidth(trim($line), 0, 200, '…');
            }
        }

        return mb_strimwidth(trim(strtok(trim($text), "\n") ?: 'no output'), 0, 200, '…');
    }
}
