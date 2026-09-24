<?php

use Livewire\Component;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\JobTemplate;
use App\Services\SupervisorConfigGenerator;
use Illuminate\Support\Facades\Process;

new class extends Component {
    public Site $site;

    public $showModal = false;

    public $templateLabel = '';
    public $templateMessage = null;

    public $preset = '';
    public $editingId = null;
    public $name = '';
    public $command = '';
    public $numprocs = 1;
    public $stopwaitsecs = 10;
    public $autostart = true;
    public $autorestart = true;

    public $runMode = 'continuous';
    public $time = '09:00';
    public $cron = '';
    public $cronPreview = [];
    public $cronError = null;

    public $error = null;
    public $statuses = [];
    public $runNow = null;

    public $extra = '';
    public $extraError = null;
    public $extraSaved = false;

    public function mount(Site $site)
    {
        $this->site = $site;
        $this->extra = $site->supervisor_extra ?? '';
        $this->refreshStatuses();
    }

    public function updatedPreset($key)
    {
        $this->resetForm(keepPreset: true);

        if (str_starts_with((string) $key, 'tpl:')) {
            $tpl = JobTemplate::find((int) substr($key, 4));
            if (!$tpl) {
                return;
            }

            $this->name = $tpl->name;
            $this->command = $tpl->command;
            $this->numprocs = $tpl->numprocs;
            $this->stopwaitsecs = $tpl->stopwaitsecs;
            $this->autostart = $tpl->autostart;
            $this->autorestart = $tpl->autorestart;

            $this->runMode = $tpl->schedule ? 'custom' : 'continuous';
            $this->cron = $tpl->schedule ?? '';
            $this->previewCron();
            return;
        }

        $preset = SiteProcess::PRESETS[$key] ?? null;
        if (!$preset) {
            return;
        }

        $this->name = $preset['name'];
        $this->command = $preset['command'];
        $this->stopwaitsecs = $preset['stopwaitsecs'];
        $this->runMode = $preset['schedule_preset'] ?? 'continuous';
        $this->recomputeCron();
    }

    public function saveAsTemplate()
    {
        $this->error = null;
        $this->templateMessage = null;

        $label = trim((string) $this->templateLabel);
        if ($label === '' || mb_strlen($label) > 80 || preg_match('/[\x00-\x1f\x7f]/', $label)) {
            $this->error = 'Give the template a name (max 80 characters).';
            return;
        }
        if (!($fields = $this->validatedFields(forTemplate: true))) {
            return;
        }

        JobTemplate::updateOrCreate(['label' => $label], [
            'name' => $fields['name'],
            'command' => $fields['command'],
            'schedule' => $fields['cron'],
            'numprocs' => $fields['cron'] ? 1 : (int) $this->numprocs,
            'stopwaitsecs' => (int) $this->stopwaitsecs,
            'autostart' => (bool) $this->autostart,
            'autorestart' => (bool) $this->autorestart,
        ]);

        $this->templateMessage = "Saved template \"{$label}\" — it's now in the Job list for every project.";
        $this->templateLabel = '';
    }

    public function deleteTemplate($id)
    {
        JobTemplate::whereKey($id)->delete();
        $this->preset = '';
    }

    public function updatedRunMode()
    {
        $this->recomputeCron();
    }

    public function updatedTime()
    {
        $this->recomputeCron();
    }

    public function updatedCron()
    {
        $this->runMode = 'custom';
        $this->previewCron();
    }

    protected function recomputeCron(): void
    {
        if ($this->runMode === 'continuous') {
            $this->cron = '';
        } elseif ($preset = SiteProcess::SCHEDULE_PRESETS[$this->runMode] ?? null) {
            $time = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $this->time, $m) ? $m : [null, '09', '00'];
            $this->cron = str_replace(['{h}', '{m}'], [(int) $time[1], (int) $time[2]], $preset['cron']);
        }

        $this->previewCron();
    }

    protected function previewCron(): void
    {
        $this->cronPreview = [];
        $this->cronError = null;

        if ($this->runMode === 'continuous') {
            return;
        }

        $result = SiteProcess::nextRuns(trim((string) $this->cron));
        if ($result['ok'] ?? false) {
            $this->cronPreview = $result['next'];
        } else {
            $this->cronError = $result['error'] ?? 'Invalid schedule.';
        }
    }

    protected function validatedFields(bool $forTemplate): ?array
    {
        $name = trim((string) $this->name);
        $command = trim((string) $this->command);
        $cron = $this->runMode === 'continuous' ? null : trim((string) $this->cron);

        if (!preg_match(SiteProcess::NAME_PATTERN, $name)) {
            $this->error = 'Name: lowercase letters, digits, hyphens and underscores only (max 40).';
            return null;
        }
        if (in_array($name, SiteProcess::RESERVED_NAMES, true)) {
            $this->error = "\"{$name}\" is reserved for a site's built-in program — pick another name.";
            return null;
        }

        if ($command === '' || strlen($command) > 500 || preg_match('/[\x00-\x1f\x7f]/', $command)) {
            $this->error = 'Command must be a single line (max 500 characters).';
            return null;
        }
        if (!$forTemplate && str_contains($command, 'CHANGE-ME')) {
            $this->error = 'Replace CHANGE-ME in the command with the real value first.';
            return null;
        }
        if (!is_numeric($this->numprocs) || $this->numprocs < 1 || $this->numprocs > 20
            || !is_numeric($this->stopwaitsecs) || $this->stopwaitsecs < 1 || $this->stopwaitsecs > 86400) {
            $this->error = 'Processes must be 1–20 and stop-wait 1–86400 seconds.';
            return null;
        }
        if ($cron !== null) {
            $check = SiteProcess::nextRuns($cron, 1);
            if (!($check['ok'] ?? false)) {
                $this->error = 'Schedule: ' . ($check['error'] ?? 'invalid cron expression');
                return null;
            }
        }

        return ['name' => $name, 'command' => $command, 'cron' => $cron];
    }

    public function save()
    {
        $this->error = null;

        if (!($fields = $this->validatedFields(forTemplate: false))) {
            return;
        }
        ['name' => $name, 'command' => $command, 'cron' => $cron] = $fields;

        $existing = $this->site->processes()->get();
        if ($existing->where('name', $name)->where('id', '!=', $this->editingId)->isNotEmpty()) {
            $this->error = "A job named \"{$name}\" already exists on this site.";
            return;
        }

        if ($this->editingId) {
            $model = $existing->firstWhere('id', $this->editingId);
            if (!$model) {
                $this->error = 'That job no longer exists.';
                return;
            }
        } else {
            $model = new SiteProcess(['site_id' => $this->site->id, 'enabled' => true]);
            $existing->push($model);
        }

        $model->fill([
            'name' => $name,
            'command' => $command,
            'schedule' => $cron,
            'numprocs' => $cron ? 1 : (int) $this->numprocs,
            'stopwaitsecs' => (int) $this->stopwaitsecs,
            'autostart' => (bool) $this->autostart,
            'autorestart' => (bool) $this->autorestart,
        ]);

        if ($problem = $this->probe($existing, $this->site->supervisor_extra)) {
            $this->error = $problem;
            return;
        }

        $model->save();
        $this->applyConfig();
        $this->resetForm();
    }

    public function edit($id)
    {
        $proc = $this->site->processes()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $proc->id;
        $this->name = $proc->name;
        $this->command = $proc->command;
        $this->numprocs = $proc->numprocs;
        $this->stopwaitsecs = $proc->stopwaitsecs;
        $this->autostart = $proc->autostart;
        $this->autorestart = $proc->autorestart;

        $this->runMode = $proc->schedule ? 'custom' : 'continuous';
        $this->cron = $proc->schedule ?? '';
        $this->previewCron();
    }

    public function cancelEdit()
    {
        $this->resetForm();
    }

    public function toggle($id)
    {
        $this->error = null;

        $existing = $this->site->processes()->get();
        $proc = $existing->firstWhere('id', $id);
        if (!$proc) {
            return;
        }

        $proc->enabled = !$proc->enabled;

        if ($proc->enabled && ($problem = $this->probe($existing, $this->site->supervisor_extra))) {
            $this->error = $problem;
            return;
        }

        $proc->save();
        $this->applyConfig();
    }

    public function delete($id)
    {
        $proc = $this->site->processes()->find($id);
        if (!$proc) {
            return;
        }

        $proc->delete();
        if ($this->editingId == $id) {
            $this->resetForm();
        }
        $this->applyConfig();
    }

    public function restart($id)
    {
        $proc = $this->site->processes()->findOrFail($id);
        Process::run(['supervisorctl', '-s', 'http://127.0.0.1:9002', 'restart', "{$this->site->name}-{$proc->name}:*"]);
        $this->refreshStatuses();
    }

    public function runNow($id)
    {
        $proc = $this->site->processes()->findOrFail($id);

        try {

            $result = Process::inProject($this->site->projectRoot())->timeout(120)->run([
                '/usr/bin/python3', '-c',
                'import shlex,subprocess,sys; sys.exit(subprocess.call(shlex.split(sys.argv[1]), stderr=subprocess.STDOUT))',
                $proc->command,
            ]);
            $output = trim($result->output() . $result->errorOutput());
            $exit = $result->exitCode();
        } catch (\Throwable $e) {
            $output = 'Stopped after 120s (or failed to start): ' . $e->getMessage();
            $exit = null;
        }

        $this->runNow = [
            'name' => $proc->name,
            'exit' => $exit,
            'output' => mb_substr($output, -4000) ?: '(no output)',
        ];
    }

    public function saveExtra()
    {
        $this->extraError = null;
        $this->extraSaved = false;

        $extra = trim(str_replace("\r\n", "\n", (string) $this->extra));

        if ($problem = $this->probe($this->site->processes()->get(), $extra === '' ? null : $extra)) {
            $this->extraError = $problem;
            return;
        }

        $this->site->update(['supervisor_extra' => $extra === '' ? null : $extra]);
        $this->extra = $extra;
        $this->applyConfig();
        $this->extraSaved = true;
    }

    public function refreshStatuses()
    {
        $this->statuses = [];
        foreach ($this->site->processes()->get() as $proc) {
            $this->statuses[$proc->id] = $proc->enabled ? $this->site->backgroundProcessStatus($proc->name) : null;
        }
    }

    protected function probe($processes, ?string $extra): ?string
    {
        $probe = Site::findOrFail($this->site->id);
        $probe->setRelation('processes', $processes->values());
        $probe->supervisor_extra = $extra;

        return (new SupervisorConfigGenerator)->check($probe);
    }

    protected function applyConfig(): void
    {
        $this->site->refresh()->unsetRelation('processes');

        try {
            (new SupervisorConfigGenerator)->generate($this->site);
        } catch (\RuntimeException $e) {
            $this->error = 'Saved, but the Supervisor config was rejected and not applied: ' . $e->getMessage();
        }

        $this->refreshStatuses();
    }

    protected function resetForm(bool $keepPreset = false): void
    {
        if (!$keepPreset) {
            $this->preset = '';
        }
        $this->editingId = null;
        $this->name = '';
        $this->command = '';
        $this->numprocs = 1;
        $this->stopwaitsecs = 10;
        $this->autostart = true;
        $this->autorestart = true;
        $this->runMode = 'continuous';
        $this->time = '09:00';
        $this->cron = '';
        $this->cronPreview = [];
        $this->cronError = null;
    }
};
