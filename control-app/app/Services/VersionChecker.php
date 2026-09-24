<?php

namespace App\Services;

use App\Services\NodeVersionManager;
use Illuminate\Support\Facades\Http;

class VersionChecker
{

    protected const INSTALLED_PHP_CYCLES = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function checkPhp(): ?array
    {
        try {
            $cycles = Http::timeout(10)->get('https://endoflife.date/api/php.json')->throw()->json();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($cycles) || empty($cycles)) {
            return null;
        }

        usort($cycles, fn ($a, $b) => version_compare($b['cycle'] . '.0', $a['cycle'] . '.0'));
        $newest = $cycles[0];

        $maxInstalled = collect(self::INSTALLED_PHP_CYCLES)->sortByDesc(fn ($c) => version_compare($c . '.0', '0'))->first();
        $newCycles = collect($cycles)
            ->pluck('cycle')
            ->reject(fn ($cycle) => in_array($cycle, self::INSTALLED_PHP_CYCLES, true))
            ->filter(fn ($cycle) => version_compare($cycle . '.0', $maxInstalled . '.0') > 0)
            ->sortByDesc(fn ($cycle) => version_compare($cycle . '.0', '0'))
            ->values()
            ->all();

        return [
            'latestCycle' => $newest['cycle'],
            'latestPatch' => $newest['latest'] ?? null,
            'newCycles' => $newCycles,
        ];
    }

    public function checkNode(): ?array
    {
        try {
            $releases = Http::timeout(10)->get('https://nodejs.org/dist/index.json')->throw()->json();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($releases) || empty($releases)) {
            return null;
        }

        $latestLts = collect($releases)->first(fn ($r) => ($r['lts'] ?? false) !== false);
        if (!$latestLts) {
            return null;
        }

        $latestLtsMajor = ltrim(explode('.', $latestLts['version'])[0], 'v');
        $known = NodeVersionManager::KNOWN_VERSIONS;

        return [
            'latestLtsVersion' => ltrim($latestLts['version'], 'v'),
            'latestLtsMajor' => $latestLtsMajor,
            'isNew' => !in_array($latestLtsMajor, $known, true),
        ];
    }

    public function checkLdev(): ?array
    {
        $repository = (string) config('ldev.github_repository');
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
            return null;
        }

        $platform = (string) config('ldev.platform');
        if (!preg_match('/^[a-z0-9]+$/', $platform)) {
            return null;
        }

        try {
            $releases = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 100])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($releases)) {
            return null;
        }

        $best = collect($releases)
            ->reject(fn ($r) => ($r['draft'] ?? true) || ($r['prerelease'] ?? true))
            ->map(function ($r) use ($platform) {
                if (!preg_match('/^' . $platform . '-v(\d+\.\d+\.\d+)$/', (string) ($r['tag_name'] ?? ''), $m)) {
                    return null;
                }
                return ['latest' => $m[1], 'url' => $r['html_url'] ?? null];
            })
            ->filter()
            ->sort(fn ($a, $b) => version_compare($b['latest'], $a['latest']))
            ->first();

        if (!$best) {
            return null;
        }

        return [
            'latest' => $best['latest'],
            'url' => $best['url'] ?? "https://github.com/{$repository}/releases",
        ];
    }

    public static function ldevUpdateAvailable(?array $check): bool
    {
        return $check !== null && version_compare($check['latest'] ?? '0', config('ldev.version'), '>');
    }

    public function checkPackagist(string $vendor, string $package, string $currentConstraint): ?array
    {
        try {
            $data = Http::timeout(10)->get("https://repo.packagist.org/p2/{$vendor}/{$package}.json")->throw()->json();
        } catch (\Throwable $e) {
            return null;
        }

        $versions = $data['packages']["{$vendor}/{$package}"] ?? null;
        if (!is_array($versions)) {
            return null;
        }

        $stable = collect($versions)
            ->pluck('version')
            ->filter(fn ($v) => preg_match('/^v?\d+\.\d+\.\d+$/', $v))
            ->sortByDesc(fn ($v) => version_compare(ltrim($v, 'v'), '0'))
            ->values();

        $latest = $stable->first();
        if (!$latest) {
            return null;
        }

        $constraintMajor = (int) ltrim($currentConstraint, '^~>=< ');
        $latestMajor = (int) explode('.', ltrim($latest, 'v'))[0];

        return [
            'latest' => ltrim($latest, 'v'),

            'satisfiesCurrent' => $latestMajor === $constraintMajor,
        ];
    }
}
