<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class TestRunner
{
    public const HISTORY_LIMIT = 10;
    public const JS_TEST_PATTERN = '/\.(test|spec)\.[cm]?[jt]sx?$/';

    protected array $status = [];
    protected array $coverageLines = [];
    protected bool $coverageSeen = false;

    public static function statusKey(Site $site): string
    {
        return 'tests_site_' . $site->id;
    }

    public static function status(Site $site): ?array
    {
        $raw = Setting::get(self::statusKey($site));
        $status = $raw ? json_decode($raw, true) : null;

        if (is_array($status) && $status['state'] === 'running') {
            $dead = !empty($status['pid'])
                ? !File::exists('/proc/' . (int) $status['pid'])
                : now()->diffInSeconds(Carbon::parse($status['startedAt'] ?? now())) > 60;
            if ($dead) {
                $status['state'] = 'interrupted';
            }
        }

        return $status;
    }

    public static function sitePhp(Site $site): string
    {
        $bin = '/usr/bin/php' . str_replace('.', '', (string) $site->php_version);

        return $site->php_version && is_executable($bin) ? $bin : 'php';
    }

    public static function discover(string $root): array
    {
        $runner = collect(['pest', 'phpunit'])->first(fn ($bin) => File::exists($root . '/vendor/bin/' . $bin));
        $config = collect(['phpunit.xml', 'phpunit.xml.dist'])->first(fn ($file) => File::exists($root . '/' . $file));

        $sources = [];
        $xml = $config ? @simplexml_load_file($root . '/' . $config) : false;
        foreach ($xml ? ($xml->xpath('//testsuite') ?: []) : [] as $suite) {
            $suiteName = (string) $suite['name'] ?: 'Tests';
            foreach ($suite->directory as $node) {
                $sources[] = ['dir' => trim((string) $node), 'suffix' => (string) ($node['suffix'] ?? '') ?: 'Test.php', 'suite' => $suiteName];
            }
            foreach ($suite->file as $node) {
                $sources[] = ['file' => trim((string) $node), 'suite' => $suiteName];
            }
        }
        if (!$sources && File::isDirectory($root . '/tests')) {
            $sources[] = ['dir' => 'tests', 'suffix' => 'Test.php', 'suite' => 'Tests'];
        }

        $paths = [];
        foreach ($sources as $source) {
            if (isset($source['file'])) {
                $paths[ltrim(preg_replace('#^\./#', '', $source['file']), '/')] ??= $source['suite'];
                continue;
            }
            $dir = $root . '/' . ltrim(preg_replace('#^\./#', '', $source['dir']), '/');
            if (!File::isDirectory($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                if (str_ends_with($file->getFilename(), $source['suffix'])) {
                    $paths[substr($file->getPathname(), strlen($root) + 1)] ??= $source['suite'];
                }
            }
        }

        $files = $styles = $suites = [];
        foreach ($paths as $path => $suite) {
            if (File::exists($root . '/' . $path)) {
                $files[$path] = self::testNames(File::get($root . '/' . $path));
                $styles[$path] = self::fileStyle($root . '/' . $path);
                $suites[$path] = $suite;
            }
        }

        $jsRunner = self::jsRunner($root);
        if ($jsRunner) {
            $finder = \Symfony\Component\Finder\Finder::create()->files()->in($root)
                ->exclude(['node_modules', 'vendor', 'storage', 'public', 'bootstrap'])
                ->name(self::JS_TEST_PATTERN);
            foreach ($finder as $file) {
                $path = $file->getRelativePathname();
                $files[$path] = self::testNames($file->getContents(), true);
                $styles[$path] = $jsRunner;
                $suites[$path] = 'JavaScript';
            }
        }
        ksort($files);

        return [
            'runner' => $runner,
            'jsRunner' => $jsRunner,
            'config' => $config,
            'files' => $files,
            'styles' => $styles,
            'suites' => $suites,
        ];
    }

    public static function jsRunner(string $root): ?string
    {
        $package = json_decode((string) @file_get_contents($root . '/package.json'), true) ?: [];
        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        foreach (['vitest', 'jest'] as $runner) {
            if (isset($deps[$runner]) && File::exists($root . '/node_modules/.bin/' . $runner)) {
                return $runner;
            }
        }

        return null;
    }

    public static function isJavascript(?string $style): bool
    {
        return in_array($style, ['vitest', 'jest'], true);
    }

    public static function failedSelection(array $results, array $discovered): array
    {
        $selection = [];
        foreach ($results as $result) {
            if (!in_array($result['status'], ['failed', 'error'], true) || !array_key_exists($result['file'], $discovered)) {
                continue;
            }
            $file = $result['file'];
            if ($result['name'] === '(needs Pest)') {
                continue;
            }
            if ($result['name'] === '(file did not run)' || !in_array($result['name'], $discovered[$file], true)) {
                $selection[$file] = [];
                continue;
            }
            if (($selection[$file] ?? null) !== []) {
                $selection[$file][] = $result['name'];
            }
        }

        return $selection;
    }

    public static function historyKey(Site $site): string
    {
        return 'tests_history_site_' . $site->id;
    }

    public static function history(Site $site): array
    {
        $raw = Setting::get(self::historyKey($site));
        $history = $raw ? json_decode($raw, true) : [];

        return is_array($history) ? $history : [];
    }

    public static function flaky(array $history): array
    {
        $flaky = [];
        foreach ($history as $i => $run) {
            foreach ($run['failed'] ?? [] as $id) {
                $hash = self::idHash($id);
                foreach ($history as $j => $other) {
                    if ($i !== $j && in_array($hash, $other['passed'] ?? [], true)) {
                        $flaky[$id] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($flaky);
    }

    public static function idHash(string $id): string
    {
        return substr(md5($id), 0, 12);
    }

    public static function fileStyle(string $file): string
    {
        $source = File::exists($file) ? File::get($file) : '';
        $hasClass = (bool) preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+\w+/m', $source);
        $hasPestCalls = (bool) preg_match('/^\s*(?:it|test|describe|beforeEach|dataset)\(/m', $source);

        return $hasPestCalls && !$hasClass ? 'pest' : 'phpunit';
    }

    public static function unsafeDatabase(string $root, ?string $config, array $overrides = []): ?string
    {
        $env = [];
        $xml = $config ? @simplexml_load_file($root . '/' . $config) : false;
        foreach ($xml ? ($xml->xpath('//php/env') ?: []) : [] as $node) {
            $forced = in_array(strtolower((string) $node['force']), ['true', '1'], true);
            $name = (string) $node['name'];
            $env[$name] = $forced || !array_key_exists($name, $overrides) ? (string) $node['value'] : $overrides[$name];
        }
        $env += array_intersect_key($overrides, array_flip(['DB_CONNECTION', 'DB_DATABASE']));

        $dotenv = [];
        foreach (File::exists($root . '/.env') ? file($root . '/.env', FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (preg_match('/^\s*(DB_[A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
                $dotenv[$m[1]] = trim($m[2], " \"'");
            }
        }

        $connection = $env['DB_CONNECTION'] ?? $dotenv['DB_CONNECTION'] ?? 'sqlite';
        $database = $env['DB_DATABASE'] ?? $dotenv['DB_DATABASE'] ?? null;

        if ($connection === 'sqlite') {
            if ($database && $database !== ':memory:' && str_starts_with($database, '/') && !str_starts_with($database, $root)) {
                return 'the tests would use the SQLite file ' . $database . ' outside the project.';
            }
            return null;
        }

        if (!isset($env['DB_DATABASE'])) {
            return ($config ?? 'phpunit.xml') . ' does not set its own DB_DATABASE, so the tests would run against the development database.';
        }

        if ($env['DB_DATABASE'] === ($dotenv['DB_DATABASE'] ?? null)) {
            return $config . ' points DB_DATABASE at the development database (' . $env['DB_DATABASE'] . ').';
        }

        return null;
    }

    public static function normaliseSelection(array $discovered, array $ids, bool $all): array
    {
        if ($all) {
            return array_fill_keys(array_keys($discovered), []);
        }

        $selection = [];
        foreach ($ids as $id) {
            [$path, $name] = array_pad(explode('::', (string) $id, 2), 2, null);
            if (!array_key_exists($path, $discovered)) {
                continue;
            }
            if ($name === null) {
                $selection[$path] = [];
            } elseif (in_array($name, $discovered[$path], true) && ($selection[$path] ?? null) !== []) {
                $selection[$path][] = $name;
            }
        }

        return $selection;
    }

    public static function hints(array $results): array
    {
        $messages = implode("\n", array_column($results, 'message'));
        $hints = [];

        if (str_contains($messages, 'SQLSTATE[HY000] [1045]')) {
            $hints[] = 'The database refused the test credentials (access denied). ldev\'s MariaDB root user has no password and PostgreSQL trusts loopback, so open Test environment in the Tests section and click Use ldev database settings (or override DB_PASSWORD yourself).';
        }
        if (str_contains($messages, 'SQLSTATE[HY000] [1049]') || preg_match('/database "[^"]+" does not exist/', $messages)) {
            $hints[] = 'The test database does not exist yet. Create it from Test environment in the Tests section.';
        }
        if (collect($results)->contains('name', '(needs Pest)')) {
            $hints[] = 'Some test files are written for Pest, but only PHPUnit is installed. Run composer require pestphp/pest pestphp/pest-plugin-laravel --dev in the project, then Reload list.';
        }
        if (collect($results)->contains('name', '(file did not run)')) {
            $hints[] = 'Some test files crashed before any test ran (for example a PHP fatal error). Their first error line is shown against the file.';
        }

        return $hints;
    }

    public function clear(Site $site): void
    {
        if ((self::status($site)['state'] ?? null) === 'running') {
            return;
        }

        Setting::set(self::statusKey($site), '');
    }

    public function launch(Site $site, array $selection, bool $coverage = false): void
    {
        $this->status = [
            'state' => 'running',
            'withCoverage' => $coverage,
            'coverage' => null,
            'startedAt' => now()->toIso8601String(),
            'finishedAt' => null,
            'pid' => null,
            'selection' => $selection,
            'current' => null,
            'results' => [],
            'hints' => [],
        ];
        Setting::set(self::statusKey($site), json_encode($this->status));

        $args = [(string) $site->id];
        foreach ($selection as $path => $names) {
            if ($names === []) {
                $args[] = $path;
            }
            foreach ($names as $name) {
                $args[] = $path . '::' . $name;
            }
        }

        $dir = config('ldev.test_runs_dir');
        File::ensureDirectoryExists($dir);
        Process::path(base_path())->run('bash -c ' . escapeshellarg(
            'setsid nohup ' . escapeshellarg(\Illuminate\Support\php_binary()) . ' artisan ldev:run-tests '
            . implode(' ', array_map('escapeshellarg', array_merge([array_shift($args)], $coverage ? ['--coverage'] : [], ['--'], $args)))
            . ' > ' . escapeshellarg($dir . '/' . $site->name . '.log') . ' 2>&1 < /dev/null &'
        ));
    }

    public function run(Site $site, array $selection, bool $coverage = false): void
    {
        $this->status = self::status($site) ?? [];
        $this->status['state'] = 'running';
        $this->status['pid'] = getmypid();
        $root = $site->projectRoot();
        $found = self::discover($root);

        $this->status['results'] = $this->runFiles($root, self::sitePhp($site), (string) $found['runner'], $selection, function (?string $current, array $results) use ($site) {
            $this->status['current'] = $current;
            $this->status['results'] = $results;
            Setting::set(self::statusKey($site), json_encode($this->status));
        }, TestEnvironment::overrides($site), $site, $coverage);

        $this->status['coverage'] = $coverage ? $this->coverageSummary($root) : null;
        $this->status['hints'] = self::hints($this->status['results']);
        if (!$found['runner'] && collect($selection)->keys()->contains(fn ($path) => !self::isJavascript($found['styles'][$path] ?? null))) {
            $this->status['hints'][] = 'No PHP test runner found in vendor/bin (pest or phpunit). Run composer install.';
        }
        if ($coverage && !$this->coverageSeen) {
            $this->status['hints'][] = 'No coverage data was produced. Coverage needs Xdebug (installed with every ldev PHP version) and a <source> section in phpunit.xml listing the code to measure, such as app/.';
        }
        $this->status['state'] = 'done';
        $this->status['current'] = null;
        $this->status['finishedAt'] = now()->toIso8601String();
        Setting::set(self::statusKey($site), json_encode($this->status));

        $this->recordHistory($site);
        $this->notify($site);
    }

    protected function recordHistory(Site $site): void
    {
        $results = collect($this->status['results']);
        $id = fn ($r) => $r['file'] . ' › ' . $r['name'];
        $history = self::history($site);
        $history[] = [
            'finishedAt' => $this->status['finishedAt'],
            'files' => count($this->status['selection'] ?? []),
            'counts' => $results->countBy('status')->all(),
            'time' => round($results->sum('time'), 2),
            'coverage' => $this->status['coverage']['percent'] ?? null,
            'failed' => $results->whereIn('status', ['failed', 'error'])->map($id)->values()->all(),
            'passed' => $results->where('status', 'passed')->map(fn ($r) => self::idHash($id($r)))->values()->all(),
        ];
        Setting::set(self::historyKey($site), json_encode(array_slice($history, -self::HISTORY_LIMIT)));
    }

    protected function notify(Site $site): void
    {
        $counts = collect($this->status['results'])->countBy('status');
        $failed = ($counts['failed'] ?? 0) + ($counts['error'] ?? 0);
        $body = ($counts['passed'] ?? 0) . ' passed, ' . $failed . ' failed, ' . ($counts['skipped'] ?? 0) . ' skipped';
        if ($this->status['coverage']) {
            $body .= ', ' . $this->status['coverage']['percent'] . '% coverage';
        }

        (new DesktopNotifier)->send('Tests finished: ' . $site->name, $body . '.', $failed ? 'critical' : 'normal');
    }

    public function runFiles(string $root, string $php, string $runner, array $selection, ?callable $progress = null, array $overrides = [], ?Site $site = null, bool $coverage = false): array
    {
        $this->coverageLines = [];
        $this->coverageSeen = false;
        $clover = config('ldev.test_runs_dir') . '/clover-' . getmypid() . '.xml';
        $results = [];
        $junit = config('ldev.test_runs_dir') . '/junit-' . getmypid() . '.xml';
        $teamcity = config('ldev.test_runs_dir') . '/teamcity-' . getmypid() . '.log';
        File::ensureDirectoryExists(dirname($junit));

        foreach ($selection as $path => $names) {
            $progress && $progress($path, $results);
            File::delete([$junit, $teamcity, $clover]);

            $style = self::isJavascript(self::jsRunner($root)) && preg_match(self::JS_TEST_PATTERN, $path) ? self::jsRunner($root) : null;
            if ($style) {
                array_push($results, ...($site ? $this->runJavascriptFile($site, $root, $style, $path, $names, $overrides, $junit) : []));
                continue;
            }

            if ($runner === '') {
                $results[] = ['file' => $path, 'name' => '(file did not run)', 'status' => 'error', 'time' => 0, 'message' => 'No PHP test runner found in vendor/bin (pest or phpunit). Run composer install.'];
                continue;
            }

            if ($runner !== 'pest' && self::fileStyle($root . '/' . $path) === 'pest') {
                $results[] = [
                    'file' => $path,
                    'name' => '(needs Pest)',
                    'status' => 'error',
                    'time' => 0,
                    'message' => 'This file is written for Pest, but the project only has PHPUnit installed, so it was not run. Install Pest in the project: composer require pestphp/pest pestphp/pest-plugin-laravel --dev',
                ];
                continue;
            }

            $env = ['PAO_DISABLE' => 'true'] + ($coverage ? ['XDEBUG_MODE' => 'coverage'] : []) + $overrides;
            $command = TestEnvironment::commandPrefix($env) . escapeshellarg($php) . ' vendor/bin/' . $runner . ' ' . escapeshellarg($path)
                . ' --colors=never --log-junit ' . escapeshellarg($junit) . ' --log-teamcity ' . escapeshellarg($teamcity)
                . ($coverage ? ' --coverage-clover ' . escapeshellarg($clover) : '');
            if ($names) {
                $command .= ' --filter ' . escapeshellarg('/(' . implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names)) . ')/');
            }

            $process = Process::inProject($root)->timeout(600)->run($command);
            if ($coverage) {
                $this->mergeClover($clover, $root);
            }
            $cases = $this->parseJunit($junit, $path);
            $reasons = $this->skipReasons($teamcity);
            foreach ($cases as $i => $case) {
                if ($case['status'] === 'skipped' && $case['message'] === '' && $reasons) {
                    $cases[$i]['message'] = array_shift($reasons);
                }
            }

            if (!$cases && !$process->successful()) {
                $cases[] = [
                    'file' => $path,
                    'name' => '(file did not run)',
                    'status' => 'error',
                    'time' => 0,
                    'message' => $this->firstErrorLine($process->output() . "\n" . $process->errorOutput()),
                ];
            }

            array_push($results, ...$cases);
        }

        File::delete([$junit, $teamcity, $clover]);
        $progress && $progress(null, $results);

        return $results;
    }

    protected function runJavascriptFile(Site $site, string $root, string $runner, string $path, array $names, array $overrides, string $report): array
    {
        $filter = $names ? ' -t ' . escapeshellarg(implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names))) : '';
        $command = $runner === 'vitest'
            ? 'node_modules/.bin/vitest run ' . escapeshellarg($path) . ' --reporter=junit --outputFile=' . escapeshellarg($report) . $filter
            : 'node_modules/.bin/jest --ci ' . escapeshellarg($path) . ' --json --outputFile=' . escapeshellarg($report) . $filter;

        File::delete($report);
        $process = DependencyHealthChecker::npmProcess($site, $root)->timeout(600)->run(TestEnvironment::commandPrefix($overrides) . $command);

        $cases = $runner === 'vitest' ? $this->parseJunit($report, $path) : $this->parseJestJson($report, $path);
        foreach ($cases as $i => $case) {
            $parts = explode(' > ', $case['name']);
            $cases[$i]['name'] = end($parts);
        }
        if (!$cases && !$process->successful()) {
            $cases[] = [
                'file' => $path,
                'name' => '(file did not run)',
                'status' => 'error',
                'time' => 0,
                'message' => $this->firstErrorLine($process->output() . "\n" . $process->errorOutput()),
            ];
        }

        return $cases;
    }

    protected function parseJestJson(string $file, string $path): array
    {
        $data = File::exists($file) ? json_decode(File::get($file), true) : null;
        $cases = [];
        foreach ($data['testResults'] ?? [] as $suite) {
            foreach ($suite['assertionResults'] ?? [] as $test) {
                $status = match ($test['status'] ?? '') {
                    'passed' => 'passed',
                    'failed' => 'failed',
                    default => 'skipped',
                };
                $cases[] = [
                    'file' => $path,
                    'name' => (string) ($test['title'] ?? $test['fullName'] ?? ''),
                    'status' => $status,
                    'time' => round(((float) ($test['duration'] ?? 0)) / 1000, 3),
                    'message' => mb_strimwidth(trim(preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $test['failureMessages'] ?? []))), 0, 3000, '…'),
                ];
            }
        }

        return $cases;
    }

    protected function mergeClover(string $file, string $root): void
    {
        $xml = File::exists($file) ? @simplexml_load_file($file) : false;
        if (!$xml) {
            return;
        }

        $this->coverageSeen = true;
        foreach ($xml->xpath('//file') ?: [] as $node) {
            $name = (string) $node['name'];
            $relative = str_starts_with($name, $root . '/') ? substr($name, strlen($root) + 1) : $name;
            foreach ($node->line as $line) {
                if ((string) $line['type'] !== 'stmt') {
                    continue;
                }
                $number = (int) $line['num'];
                $covered = (int) $line['count'] > 0;
                $this->coverageLines[$relative][$number] = ($this->coverageLines[$relative][$number] ?? false) || $covered;
            }
        }
    }

    protected function coverageSummary(string $root): ?array
    {
        if (!$this->coverageSeen) {
            return null;
        }

        $files = [];
        $total = $covered = 0;
        foreach ($this->coverageLines as $file => $lines) {
            $fileTotal = count($lines);
            $fileCovered = count(array_filter($lines));
            $total += $fileTotal;
            $covered += $fileCovered;
            if ($fileTotal) {
                $files[] = ['file' => $file, 'lines' => $fileTotal, 'covered' => $fileCovered, 'percent' => round($fileCovered / $fileTotal * 100, 1)];
            }
        }
        usort($files, fn ($a, $b) => [$a['percent'], -$a['lines']] <=> [$b['percent'], -$b['lines']]);

        return [
            'percent' => $total ? round($covered / $total * 100, 1) : 0.0,
            'lines' => $total,
            'covered' => $covered,
            'lowest' => array_slice($files, 0, 15),
            'fileCount' => count($files),
        ];
    }

    protected function skipReasons(string $file): array
    {
        if (!File::exists($file)) {
            return [];
        }

        preg_match_all("/^##teamcity\\[testIgnored .*?message='((?:\\|.|[^'|])*)'/m", File::get($file), $m);

        return array_map(fn ($text) => strtr($text, ['|n' => "\n", '|r' => "\r", "|'" => "'", '|[' => '[', '|]' => ']', '||' => '|']), $m[1]);
    }

    protected function parseJunit(string $file, string $path): array
    {
        $xml = File::exists($file) ? @simplexml_load_file($file) : false;
        if (!$xml) {
            return [];
        }

        $cases = [];
        foreach ($xml->xpath('//testcase') ?: [] as $case) {
            $status = 'passed';
            $message = '';
            foreach (['failure' => 'failed', 'error' => 'error', 'skipped' => 'skipped'] as $tag => $label) {
                if (isset($case->{$tag})) {
                    $status = $label;
                    $message = trim((string) $case->{$tag});
                    break;
                }
            }
            $name = (string) $case['name'];
            $qualified = (string) $case['class'] . '::' . $name;
            foreach ([$qualified, $name] as $prefix) {
                if ($name !== '' && str_starts_with($message, $prefix)) {
                    $message = ltrim(substr($message, strlen($prefix)));
                    break;
                }
            }
            $cases[] = [
                'file' => $path,
                'name' => $name,
                'status' => $status,
                'time' => round((float) $case['time'], 3),
                'message' => mb_strimwidth($message, 0, 3000, '…'),
            ];
        }

        return $cases;
    }

    protected static function testNames(string $source, bool $javascript = false): array
    {
        $names = [];
        $quotes = $javascript ? '[\'"`]' : '[\'"]';
        if (preg_match_all('/^\s*(?:it|test)\(\s*(' . $quotes . ')((?:\\\\.|(?!\1).)*)\1/m', $source, $m)) {
            foreach ($m[2] as $i => $name) {
                $name = stripslashes($name);
                $names[] = !$javascript && str_starts_with(ltrim($m[0][$i]), 'it') ? 'it ' . $name : $name;
            }
        }
        if ($javascript) {
            return array_values(array_unique($names));
        }
        if (preg_match_all('/(?:#\[Test\]|@test)[^{;]*?function\s+(\w+)|function\s+(test\w*)\s*\(/', $source, $m)) {
            foreach (array_keys($m[0]) as $i) {
                $names[] = $m[1][$i] ?: $m[2][$i];
            }
        }

        return array_values(array_unique($names));
    }

    protected function firstErrorLine(string $text): string
    {
        foreach (explode("\n", trim($text)) as $line) {
            if (preg_match('/fatal|error|exception/i', $line)) {
                return mb_strimwidth(trim($line), 0, 400, '…');
            }
        }

        return mb_strimwidth(trim(strtok(trim($text), "\n") ?: 'no output'), 0, 400, '…');
    }
}
