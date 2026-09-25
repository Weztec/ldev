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
        }
        $values = Setting::whereIn('key', $keys)->pluck('value', 'key');

        $manifests = new ProjectManifest;
        $health = [];
        foreach ($sites as $site) {
            $deps = json_decode((string) $values->get(DependencyHealthChecker::settingKey($site)), true) ?: [];
            $tests = json_decode((string) $values->get(TestRunner::statusKey($site)), true) ?: [];
            $sandbox = json_decode((string) $values->get(DependencySandbox::statusKey($site)), true) ?: [];
            $results = collect($tests['results'] ?? []);

            $health[$site->id] = [
                'vulnerable' => collect($deps['advisories'] ?? [])->pluck('package')->unique()->count(),
                'outdated' => count($deps['composerOutdated'] ?? []) + count($deps['npmOutdated'] ?? []),
                'failing' => ($tests['state'] ?? null) === 'done' ? $results->whereIn('status', ['failed', 'error'])->count() : 0,
                'testsPassed' => ($tests['state'] ?? null) === 'done' && $results->count() && !$results->whereIn('status', ['failed', 'error'])->count(),
                'testsRunning' => ($tests['state'] ?? null) === 'running',
                'unmetRequirements' => $manifests->exists($site->projectRoot()) ? count($manifests->unmetRequirements($site)) : 0,
                'sandbox' => ($sandbox['state'] ?? null) === 'done' ? $sandbox['verdict'] : (($sandbox['state'] ?? null) === 'running' ? 'running' : null),
            ];
        }

        return $health;
    }
}
