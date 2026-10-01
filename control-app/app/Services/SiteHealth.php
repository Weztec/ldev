<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Collection;

class SiteHealth
{
    public static function forSites(Collection $sites): array
    {
        $keys = [];
        foreach ($sites as $site) {
            $keys[] = DependencyHealthChecker::settingKey($site);
            $keys[] = TestRunner::statusKey($site);
            $keys[] = DependencySandbox::statusKey($site);
            $keys[] = DependencyHealthChecker::checkKey($site);
        }
        $values = Setting::whereIn('key', $keys)->pluck('value', 'key');

        $manifests = new ProjectManifest;
        $health = [];
        foreach ($sites as $site) {
            $deps = json_decode((string) $values->get(DependencyHealthChecker::settingKey($site)), true) ?: [];
            $tests = json_decode((string) $values->get(TestRunner::statusKey($site)), true) ?: [];
            $sandbox = json_decode((string) $values->get(DependencySandbox::statusKey($site)), true) ?: [];
            $check = json_decode((string) $values->get(DependencyHealthChecker::checkKey($site)), true) ?: [];
            $results = collect($tests['results'] ?? []);
            $done = ($tests['state'] ?? null) === 'done';
            $composer = collect($deps['composerOutdated'] ?? []);
            $npm = collect($deps['npmOutdated'] ?? []);
            $describe = fn ($row) => $row['name'] . ' ' . ($row['current'] ?? '?') . ' → ' . $row['latest'] . (($row['advice']['level'] ?? null) === 'blocked' ? ' (blocked)' : '');

            $health[$site->id] = [
                'vulnerable' => collect($deps['advisories'] ?? [])->pluck('package')->unique()->count(),
                'vulnerableTitle' => collect($deps['advisories'] ?? [])->pluck('package')->unique()->implode("\n"),
                'outdated' => $composer->count() + $npm->count(),
                'outdatedComposer' => $composer->count(),
                'outdatedNpm' => $npm->count(),
                'blocked' => $composer->merge($npm)->where('advice.level', 'blocked')->count(),
                'outdatedTitle' => $composer->merge($npm)->map($describe)->implode("\n"),
                'checking' => ($check['state'] ?? null) === 'running',
                'failing' => $done ? $results->whereIn('status', ['failed', 'error'])->count() : 0,
                'passed' => $done ? $results->where('status', 'passed')->count() : 0,
                'skipped' => $done ? $results->where('status', 'skipped')->count() : 0,
                'testsPassed' => $done && $results->count() && !$results->whereIn('status', ['failed', 'error'])->count(),
                'testsRunning' => ($tests['state'] ?? null) === 'running',
                'testsProgress' => ($tests['state'] ?? null) === 'running' && !empty($tests['progress']['total'])
                    ? min($tests['progress']['done'] + 1, $tests['progress']['total']) . '/' . $tests['progress']['total'] : null,
                'unmetRequirements' => $manifests->exists($site->projectRoot()) ? count($manifests->unmetRequirements($site)) : 0,
                'sandbox' => ($sandbox['state'] ?? null) === 'done' ? $sandbox['verdict'] : (($sandbox['state'] ?? null) === 'running' ? 'running' : null),
            ];
        }

        return $health;
    }
}
