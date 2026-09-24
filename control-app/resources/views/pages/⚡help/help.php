<?php

use Livewire\Component;

new class extends Component {
    public string $section = 'start';

    const SECTIONS = [
        'start' => 'Getting started',
        'sites' => 'The Sites list',
        'new-project' => 'Creating a project',
        'project-types' => 'Project types',
        'overview-page' => 'Project overview',
        'dependencies-tests' => 'Dependencies & tests',
        'project-settings' => 'Project settings',
        'background-jobs' => 'Background jobs',
        'databases' => 'Databases & Adminer',
        'services' => 'Services & PHP versions',
        'repositories' => 'Repositories',
        'logs' => 'Logs',
        'settings-page' => 'Dashboard settings',
        'backups' => 'Backups & recovery',
        'troubleshooting' => 'Troubleshooting',
    ];

    public function mount()
    {

        $requested = request('s');
        if ($requested && array_key_exists($requested, self::SECTIONS)) {
            $this->section = $requested;
        }
    }

    public function setSection($key)
    {
        if (array_key_exists($key, self::SECTIONS)) {
            $this->section = $key;
        }
    }
};
