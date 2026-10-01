<?php

namespace App\Services;

use App\Models\Site;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Process;

class DependencyAdvisor
{
    protected const PLATFORM = '/^(php(-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer(-plugin-api|-runtime-api)?)$/';

    protected array $composerLatest = [];
    protected array $composerLocked = [];
    protected array $composerRequires = [];
    protected ?string $rootName = null;
    protected array $showCache = [];
    protected ?string $phpVersion = null;

    protected array $npmLocked = [];
    protected array $npmPeers = [];
    protected array $npmRanges = [];
    protected array $viewCache = [];
    protected ?string $nodeVersion = null;

    public function __construct(protected Site $site, protected string $root)
    {
    }

    public function useComposerOutdated(array $installed): void
    {
        foreach ($installed as $pkg) {
            if (!empty($pkg['name']) && !empty($pkg['latest'])) {
                $this->composerLatest[$pkg['name']] = ltrim($pkg['latest'], 'v');
            }
        }

        $lock = json_decode((string) @file_get_contents($this->root . '/composer.lock'), true) ?: [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $this->composerLocked[$pkg['name']] = ltrim($pkg['version'], 'v');
            $this->composerRequires[$pkg['name']] = $pkg['require'] ?? [];
        }

        $this->rootName = (json_decode((string) @file_get_contents($this->root . '/composer.json'), true) ?: [])['name'] ?? '__root__';
    }

    public function composerRow(array $row): array
    {
        $name = $row['name'];
        $analysis = $this->composerBlockers($name, $row['latest']);

        $lines = $analysis['lines'];
        $waiting = $analysis['waiting'];
        $first = $analysis['first'];

        if ($analysis['own'] !== null) {
            array_unshift($lines, 'Your composer.json requires ' . $name . ' ' . $analysis['own'] . '.');
        }

        if ($waiting) {
            return self::make('blocked', $name . ' ' . $row['current'] . ' → ' . $row['latest'] . ' is blocked.', $lines,
                'Stay on ' . $name . ' ' . $this->majorOf($row['current']) . '.x until new releases of ' . $this->listing($waiting) . ' support ' . $name . ' ' . $this->majorOf($row['latest']) . '.');
        }

        if ($first) {
            return self::make('action', $name . ' ' . $row['latest'] . ' needs other packages updated first.', $lines,
                'Update ' . $this->listing($first) . ' first, or tick them together with ' . $name . ' and use Test ticked major upgrades.');
        }

        if ($analysis['unknown']) {
            return self::make('unknown', 'Could not work out what holds ' . $name . ' back.', $lines,
                'Run Test ' . ($row['major'] ? 'major upgrade' : 'selected') . ' and read the Composer step output.');
        }

        if ($analysis['own'] !== null) {
            return self::make('action', 'Only your own version constraint holds ' . $name . ' at ' . $row['current'] . '.', $lines,
                'Use Test ticked major upgrades (Need a major upgrade list). It raises the constraint to ^' . $row['latest'] . ' in the sandbox copy and runs the checks.');
        }

        return self::make('ok', 'Nothing blocks ' . $name . ' ' . $row['latest'] . '.', $lines,
            'Tick it under Updates within your ranges and use Test ticked.');
    }

    public function composerAdvisory(string $package, array $affected): array
    {
        $installed = $this->composerLocked[$package] ?? null;
        $versions = $this->composerShow($package)['versions'] ?? [];
        $fixed = null;
        $stable = array_values(array_filter($versions, fn ($v) => !str_contains($v, 'dev') && VersionParser::parseStability($v) === 'stable'));

        foreach (Semver::sort($stable) as $version) {
            if ($installed && !Semver::satisfies($version, '>' . $installed)) {
                continue;
            }
            if (!collect($affected)->filter()->contains(fn ($range) => $this->satisfies($version, $range) !== false)) {
                $fixed = ltrim($version, 'v');
                break;
            }
        }

        if (!$versions) {
            return self::make('unknown', 'Could not read the release list for ' . $package . '.', [], 'Open the advisory link to see which release fixes it.');
        }

        if ($fixed === null) {
            $latest = ltrim((string) (Semver::rsort($stable)[0] ?? ''), 'v');

            return self::make('blocked', 'No release of ' . $package . ' fixes this yet.', array_filter([
                $latest ? $package . ' ' . $latest . ' is the newest release and is still affected.' : null,
            ]), 'Nothing to install yet. Check the advisory for a workaround, and check again later.');
        }

        $analysis = $this->composerBlockers($package, $fixed);
        $lines = array_merge(
            [$package . ' ' . $fixed . ' is the first release that is not affected.'],
            $analysis['own'] !== null ? ['Your composer.json requires ' . $package . ' ' . $analysis['own'] . '.'] : [],
            $analysis['lines']
        );

        if ($analysis['waiting']) {
            return self::make('blocked', $package . ' ' . $fixed . ' has the fix but cannot be installed yet.', $lines,
                'Wait for new releases of ' . $this->listing($analysis['waiting']) . ' that allow ' . $package . ' ' . $fixed . ', or replace them.');
        }

        if ($analysis['first']) {
            return self::make('action', $package . ' ' . $fixed . ' has the fix once other packages are updated.', $lines,
                'Update ' . $this->listing($analysis['first']) . ' first, then test ' . $package . ' again.');
        }

        if ($analysis['own'] !== null) {
            return self::make('action', 'Only your own version constraint keeps ' . $package . ' below ' . $fixed . '.', $lines,
                'Raise the constraint to ^' . $fixed . ' (Test ticked major upgrades on the Composer outdated list does this in the sandbox).');
        }

        return self::make('ok', 'The fix fits your current constraints.', $lines, 'Use Test selected or Test all vulnerable.');
    }

    protected function composerBlockers(string $name, string $version): array
    {
        $result = ['own' => null, 'lines' => [], 'waiting' => [], 'first' => [], 'unknown' => false];

        $entries = $this->whyNot($name, $version);
        if ($entries === null) {
            $result['unknown'] = true;
            $result['lines'][] = 'composer why-not gave no usable output.';

            return $result;
        }

        foreach ($entries as $entry) {
            if ($entry['by'] === $this->rootName && $entry['target'] === $name) {
                $result['own'] = $entry['constraint'];
            } elseif ($entry['by'] === $name) {
                $this->composerDependency($name, $version, $entry, $result);
            } elseif ($entry['target'] === $name) {
                $this->composerBlocker($entry, $name, '==' . $version, $result);
            }
        }

        $blocked = array_column(array_filter($entries, fn ($entry) => $entry['target'] === $name), 'by');
        foreach (array_keys($this->composerShow($name, $version)['replaces'] ?? []) as $replaced) {
            foreach ($this->composerRequires as $package => $requires) {
                if ($package === $name || in_array($package, $blocked, true) || !isset($requires[$replaced])) {
                    continue;
                }
                if ($this->satisfies($version, $requires[$replaced]) === false) {
                    $blocked[] = $package;
                    $this->composerBlocker([
                        'by' => $package, 'version' => $this->composerLocked[$package], 'verb' => 'requires',
                        'target' => $replaced, 'constraint' => $requires[$replaced],
                    ], $replaced, '==' . $version, $result);
                }
            }
        }

        return $result;
    }

    protected function composerDependency(string $name, string $version, array $entry, array &$result): void
    {
        $dep = $entry['target'];
        $constraint = $entry['constraint'];

        if ($entry['verb'] === 'conflicts') {
            return;
        }

        if (preg_match(self::PLATFORM, $dep)) {
            if ($dep === 'php' && ($php = $this->phpVersion()) && $this->satisfies($php, $constraint) === false) {
                $result['lines'][] = $name . ' ' . $version . ' needs PHP ' . $constraint . '. This site runs PHP ' . $php . '.';
                $result['first'][] = 'the site\'s PHP version';
            }
            return;
        }

        $installed = $this->composerLocked[$dep] ?? null;
        if ($installed === null || $this->satisfies($installed, $constraint) !== false) {
            return;
        }

        $holders = array_filter($this->whyNot($dep, $constraint) ?? [], fn ($e) => $e['target'] === $dep && $e['by'] !== $name);
        if (!$holders) {
            $result['lines'][] = $name . ' ' . $version . ' needs ' . $dep . ' ' . $constraint . ' (' . $installed . ' installed). Composer can move it in the same update.';
            return;
        }

        foreach ($holders as $holder) {
            if ($holder['by'] === $this->rootName) {
                $result['lines'][] = $name . ' ' . $version . ' needs ' . $dep . ' ' . $constraint . ', but your composer.json requires ' . $dep . ' ' . $holder['constraint'] . '.';
                $result['first'][] = 'your ' . $dep . ' constraint';
                continue;
            }
            $this->composerBlocker($holder, $dep, $constraint, $result, $name . ' ' . $version . ' needs ' . $dep . ' ' . $constraint . ', which ');
        }
    }

    protected function composerBlocker(array $entry, string $target, string $needed, array &$result, string $prefix = ''): void
    {
        $blocker = $entry['by'];
        $current = ltrim($entry['version'], 'v');
        $latest = $this->composerLatest[$blocker] ?? $current;
        $verb = $entry['verb'] === 'conflicts' ? 'conflicts with' : 'only accepts';
        $line = ($prefix ? $prefix . $blocker . ' blocks: ' : '') . $blocker . ' ' . $current . ' ' . $verb . ' ' . $target . ' ' . $entry['constraint'] . '.';

        if ($latest === $current) {
            $result['lines'][] = $line . ' That is its latest release.';
            $result['waiting'][] = $blocker;
            return;
        }

        $show = $this->composerShow($blocker, $latest);
        if ($show === null) {
            $result['lines'][] = $line . ' Could not read ' . $blocker . ' ' . $latest . ' to see if it allows it.';
            $result['unknown'] = true;
            return;
        }

        $key = $entry['verb'] === 'conflicts' ? 'conflicts' : 'requires';
        $newConstraint = $show[$key][$target] ?? null;
        $allows = $newConstraint === null
            || ($entry['verb'] === 'conflicts' ? !$this->intersects($needed, $newConstraint) : $this->intersects($needed, $newConstraint));

        if ($allows) {
            $result['lines'][] = $line . ' ' . $blocker . ' ' . $latest . ' ' . ($newConstraint === null ? 'no longer ' . ($key === 'conflicts' ? 'conflicts with' : 'requires') . ' it.' : 'allows it (' . $newConstraint . ').');
            $result['first'][] = $blocker . ' (to ' . $latest . ')';
        } else {
            $result['lines'][] = $line . ' Its latest release, ' . $latest . ', still ' . ($key === 'conflicts' ? 'conflicts' : 'only accepts ' . $newConstraint) . '.';
            $result['waiting'][] = $blocker;
        }
    }

    protected function whyNot(string $package, string $constraint): ?array
    {
        $process = Process::inProject($this->root)->timeout(60)->run(
            $this->composer() . ' why-not ' . escapeshellarg($package) . ' ' . escapeshellarg($constraint) . ' --no-interaction --no-ansi'
        );
        $output = $process->output() . "\n" . $process->errorOutput();

        $entries = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+(requires|conflicts)(?:\s+\(for development\))?\s+(\S+)\s+\((.+?)\)\s*$/', trim($line), $m)) {
                $entries[] = ['by' => $m[1], 'version' => $m[2], 'verb' => $m[3], 'target' => $m[4], 'constraint' => $m[5]];
            }
        }

        if (!$entries && !str_contains($output, 'There is no installed package depending on')) {
            return null;
        }

        return $entries;
    }

    protected function composerShow(string $package, ?string $version = null): ?array
    {
        $key = $package . '@' . $version;
        if (!array_key_exists($key, $this->showCache)) {
            $process = Process::inProject($this->root)->timeout(60)->run(
                $this->composer() . ' show -a ' . escapeshellarg($package) . ($version ? ' ' . escapeshellarg($version) : '') . ' --format=json --no-interaction'
            );
            $data = json_decode($process->output(), true);
            $this->showCache[$key] = is_array($data) ? $data : null;
        }

        return $this->showCache[$key];
    }

    protected function composer(): string
    {
        return escapeshellarg(TestRunner::sitePhp($this->site)) . ' "$(command -v composer)"';
    }

    protected function phpVersion(): ?string
    {
        if ($this->phpVersion === null) {
            $this->phpVersion = trim(Process::run(escapeshellarg(TestRunner::sitePhp($this->site)) . ' -r ' . escapeshellarg('echo PHP_VERSION;'))->output());
        }

        return $this->phpVersion ?: null;
    }

    public function useNpmLock(): void
    {
        $lock = json_decode((string) @file_get_contents($this->root . '/package-lock.json'), true) ?: [];
        foreach ($lock['packages'] ?? [] as $path => $pkg) {
            if ($path === '' || !isset($pkg['version'])) {
                continue;
            }
            $name = $pkg['name'] ?? substr($path, strrpos($path, 'node_modules/') + 13);
            if (!str_contains(substr($path, 13), 'node_modules/')) {
                $this->npmLocked[$name] = $pkg['version'];
            }
            foreach ($pkg['peerDependencies'] ?? [] as $peer => $range) {
                if (empty($pkg['peerDependenciesMeta'][$peer]['optional'])) {
                    $this->npmPeers[$peer][$name . '@' . $pkg['version']] = ['by' => $name, 'version' => $pkg['version'], 'range' => $range];
                }
            }
        }

        $manifest = json_decode((string) @file_get_contents($this->root . '/package.json'), true) ?: [];
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $group) {
            foreach ($manifest[$group] ?? [] as $name => $range) {
                $this->npmRanges[$name] = $range;
            }
        }
    }

    public function npmRow(array $row): array
    {
        $name = $row['name'];
        $latest = $row['latest'];
        $range = $this->npmRanges[$name] ?? null;
        $lines = [];
        $waiting = [];
        $first = [];
        $unknown = false;

        foreach ($this->npmPeers[$name] ?? [] as $peer) {
            if ($peer['by'] === $name || $this->satisfies($latest, $peer['range']) !== false) {
                continue;
            }
            $line = $peer['by'] . ' ' . $peer['version'] . ' only accepts ' . $name . ' ' . $peer['range'] . ' (peer dependency).';
            $view = $this->npmView($peer['by']);
            if ($view === null) {
                $lines[] = $line . ' Could not read its latest release.';
                $unknown = true;
                continue;
            }
            $newRange = $view['peerDependencies'][$name] ?? null;
            if ($view['version'] === $peer['version'] || ($newRange !== null && $this->satisfies($latest, $newRange) === false)) {
                $lines[] = $line . ($view['version'] === $peer['version'] ? ' That is its latest release.' : ' Its latest release, ' . $view['version'] . ', still only accepts ' . $newRange . '.');
                $waiting[] = $peer['by'];
            } else {
                $lines[] = $line . ' ' . $peer['by'] . ' ' . $view['version'] . ' ' . ($newRange === null ? 'no longer needs it.' : 'allows it (' . $newRange . ').');
                $first[] = $peer['by'] . ' (to ' . $view['version'] . ')';
            }
        }

        $target = $this->npmView($name, $latest);
        foreach ($target['peerDependencies'] ?? [] as $peer => $peerRange) {
            $installed = $this->npmLocked[$peer] ?? null;
            if ($installed === null || !isset($this->npmRanges[$peer])) {
                continue;
            }
            if ($this->satisfies($installed, $peerRange) === false) {
                $lines[] = $name . ' ' . $latest . ' needs ' . $peer . ' ' . $peerRange . ' (' . $installed . ' installed).';
                $first[] = $peer;
            }
        }

        $engine = $target['engines']['node'] ?? null;
        $node = $this->nodeVersion();
        if ($engine && $node && $this->satisfies($node, $engine) === false) {
            $lines[] = $name . ' ' . $latest . ' needs Node ' . $engine . '. This site uses Node ' . $node . '.';
            $first[] = 'the site\'s Node version';
        }

        if ($range !== null && !$row['major'] && ($row['wanted'] ?? null) !== $latest) {
            $lines[] = 'Your package.json range for ' . $name . ' is ' . $range . ', which stops at ' . ($row['wanted'] ?? $row['current']) . '.';
        } elseif ($range !== null && $row['major']) {
            $lines[] = 'Your package.json range for ' . $name . ' is ' . $range . '.';
        }

        if ($waiting) {
            return self::make('blocked', $name . ' ' . ($row['current'] ?? 'missing') . ' → ' . $latest . ' is blocked.', $lines,
                'Stay on ' . $name . ' ' . $this->majorOf((string) $row['current']) . '.x until new releases of ' . $this->listing($waiting) . ' support ' . $name . ' ' . $this->majorOf($latest) . '.');
        }
        if ($first) {
            return self::make('action', $name . ' ' . $latest . ' needs other changes first.', $lines,
                'Update ' . $this->listing(array_unique($first)) . ' first, or tick them together with ' . $name . ' and use Test ticked major upgrades.');
        }
        if ($unknown) {
            return self::make('unknown', 'Could not check everything that depends on ' . $name . '.', $lines, 'Run a sandbox test and read the npm step output.');
        }
        if ($row['major'] || ($row['wanted'] ?? null) !== $latest) {
            return self::make('action', 'Only your package.json range holds ' . $name . ' back.', $lines,
                ($row['major'] ? '' : 'The range is pinned below ' . $latest . '. ') . 'Use Test ticked major upgrades (Need a major upgrade list). It installs ' . $name . '@^' . $latest . ' in the sandbox copy and runs the checks.');
        }

        return self::make('ok', 'Nothing blocks ' . $name . ' ' . $latest . '.', $lines, 'Tick it under Updates within your ranges and use Test ticked.');
    }

    public static function npmAdvisory(array $vulnerability, string $package): array
    {
        $fix = $vulnerability['fixAvailable'] ?? null;
        $lines = [];
        if (!empty($vulnerability['isDirect'])) {
            $lines[] = $package . ' is a direct dependency in package.json.';
        } elseif (!empty($vulnerability['nodes'])) {
            $lines[] = 'Installed at ' . implode(', ', array_slice((array) $vulnerability['nodes'], 0, 3)) . '.';
        }
        if (!empty($vulnerability['fixBlockedByReleaseAge']['version'])) {
            $lines[] = 'The fixed release ' . $vulnerability['fixBlockedByReleaseAge']['version'] . ' is newer than your npm release-age setting allows.';
        }

        if ($fix === true) {
            return self::make('ok', 'npm can fix this within your package.json ranges.', $lines, 'Use Test npm audit fix, or tick ' . $package . ' and Test selected.');
        }
        if (is_array($fix) && !empty($fix['name'])) {
            $via = $fix['name'] === $package ? '' : ' (it pulls in ' . $package . ')';
            if (!empty($fix['isSemVerMajor'])) {
                return self::make('action', 'The fix needs ' . $fix['name'] . ' ' . $fix['version'] . ', a major version change' . $via . '.', $lines,
                    'Tick ' . $fix['name'] . ' in the outdated npm list and use Test ticked major upgrades. Read its upgrade guide first.');
            }

            return self::make('action', 'The fix needs ' . $fix['name'] . ' ' . $fix['version'] . $via . '.', $lines, 'Tick ' . $fix['name'] . ' and use Test selected.');
        }
        if ($fix === false) {
            return self::make('blocked', 'No fixed release is available yet.', $lines, 'Nothing to install yet. Check the advisory for a workaround, or replace the package that pulls it in.');
        }

        return self::make('unknown', 'npm did not say whether a fix exists.', $lines, 'Open the advisory link to see which release fixes it.');
    }

    protected function npmView(string $package, string $version = 'latest'): ?array
    {
        $key = $package . '@' . $version;
        if (!array_key_exists($key, $this->viewCache)) {
            $process = DependencyHealthChecker::npmProcess($this->site, $this->root)->timeout(60)->run(
                'npm view ' . escapeshellarg($key) . ' version peerDependencies peerDependenciesMeta engines --json'
            );
            $data = json_decode($process->output(), true);
            if (is_array($data) && array_is_list($data)) {
                $data = end($data) ?: null;
            }
            $this->viewCache[$key] = is_array($data) && isset($data['version']) ? $data : null;
        }

        return $this->viewCache[$key];
    }

    protected function nodeVersion(): ?string
    {
        if ($this->nodeVersion === null) {
            $this->nodeVersion = ltrim(trim(DependencyHealthChecker::npmProcess($this->site, $this->root)->run('node --version')->output()), 'v');
        }

        return $this->nodeVersion ?: null;
    }

    protected function satisfies(string $version, string $constraint): ?bool
    {
        try {
            return Semver::satisfies($version, $this->normalizeRange($constraint));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function intersects(string $a, string $b): bool
    {
        try {
            $parser = new VersionParser;

            return $parser->parseConstraints($this->normalizeRange($a))->matches($parser->parseConstraints($this->normalizeRange($b)));
        } catch (\Throwable) {
            return true;
        }
    }

    protected function normalizeRange(string $range): string
    {
        $range = preg_replace('/(\d+\.\d+\.\d+)-0\b/', '$1', trim($range));

        return preg_replace('/(?<![\d.])~(\d+)\.(\d+)(?![\d.])/', '~$1.$2.0', $range);
    }

    protected function majorOf(string $version): string
    {
        $parts = explode('.', ltrim($version, 'v'));

        return $parts[0] === '0' && isset($parts[1]) ? '0.' . $parts[1] : $parts[0];
    }

    protected function listing(array $items): string
    {
        $items = array_values(array_unique($items));
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }

    protected static function make(string $level, string $summary, array $lines, string $action): array
    {
        return ['level' => $level, 'summary' => $summary, 'lines' => array_values(array_unique($lines)), 'action' => $action];
    }
}
