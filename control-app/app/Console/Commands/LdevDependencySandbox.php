<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\DependencyHealthChecker;
use App\Services\DependencySandbox;
use Illuminate\Console\Command;

class LdevDependencySandbox extends Command
{
    protected $signature = 'ldev:dependency-sandbox {site} {section} {packages?*} {--all} {--major}';
    protected $description = 'Apply a dependency update to a sandbox copy of a project and check that it still boots, builds and passes its tests';

    public function handle(): int
    {
        $site = Site::find($this->argument('site'));
        $section = $this->argument('section');

        if (!$site || !in_array($section, DependencyHealthChecker::SECTIONS, true)) {
            $this->error('Unknown site or section.');
            return self::FAILURE;
        }

        $known = DependencyHealthChecker::updatablePackages(DependencyHealthChecker::cached($site), $section);
        $all = (bool) $this->option('all');
        $major = (bool) $this->option('major');
        $cached = DependencyHealthChecker::cached($site);
        $packages = match (true) {
            $all && $major => array_values(array_filter($known, fn ($name) => DependencyHealthChecker::outdatedRow($cached, DependencyHealthChecker::manager($section), $name)['major'] ?? false)),
            $all && $section === 'security' => $known,
            $all => [],
            default => array_values(array_intersect((array) $this->argument('packages'), $known)),
        };

        if (!$packages && (!$all || $major)) {
            $this->error('No known packages given.');
            return self::FAILURE;
        }

        (new DependencySandbox)->run($site, $section, $packages, $all && !$major, $major);

        return self::SUCCESS;
    }
}
