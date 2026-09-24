<div>

@php
    $procs = $site->processes()->get();
    $enabledCount = $procs->where('enabled', true)->count();
    $templates = \App\Models\JobTemplate::orderBy('label')->get();
@endphp
<div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="font-medium">Custom background jobs</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                @if($procs->isEmpty())
                    None yet — add Horizon, a scheduled artisan command, or any other command Supervisor should run.
                @else
                    {{ $procs->count() }} {{ \Illuminate\Support\Str::plural('job', $procs->count()) }}, {{ $enabledCount }} on
                    @if(filled($site->supervisor_extra)) · raw config set @endif
                @endif
            </p>
        </div>
        <flux:button size="sm" variant="filled" color="blue" icon="cog-6-tooth" wire:click="$set('showModal', true)">Manage</flux:button>
    </div>
    @if($procs->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            @foreach($procs as $proc)
                @php $st = $statuses[$proc->id] ?? null;@endphp
                <span class="inline-flex items-center gap-1.5" wire:key="sum-{{ $proc->id }}">
                    <span class="inline-block w-2 h-2 rounded-full {{ !$proc->enabled ? 'bg-gray-400' : ((is_array($st) && ($st['running'] ?? false)) ? 'bg-green-500' : ((is_array($st) && ($st['reachable'] ?? false)) ? 'bg-red-500' : 'bg-yellow-500')) }}"></span>
                    <span class="font-mono">{{ $proc->name }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ $proc->schedule ? 'scheduled' : 'continuous' }}</span>
                </span>
            @endforeach
        </div>
    @endif
</div>

<flux:modal wire:model="showModal" class="max-w-4xl!">
<div class="space-y-4">

    <div class="flex justify-between items-center pr-10">
        <h2 class="text-lg font-medium">Custom background jobs — {{ $site->name }}</h2>
        <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshStatuses">Refresh</flux:button>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Extra Supervisor-managed commands for this project, beyond the queue worker, Reverb and
        scheduler. Every change is checked with Supervisor's own parser before it's saved, so a
        typo can't stop the other sites' workers. Saving restarts Supervisor (all sites' workers
        restart briefly).
    </p>

    @if($procs->isNotEmpty())
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach($procs as $proc)
                @php $st = $statuses[$proc->id] ?? null;@endphp
                <div class="py-3 space-y-2" wire:key="proc-{{ $proc->id }}">
                    <div class="flex flex-wrap items-center gap-3 justify-between">
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                @if(!$proc->enabled)
                                    <span class="inline-block w-2 h-2 rounded-full bg-gray-400"></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Off</span>
                                @elseif(is_array($st) && ($st['reachable'] ?? false) && ($st['running'] ?? false))
                                    <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                                    <span class="text-xs text-green-600 dark:text-green-400">{{ $st['status'] ?? 'RUNNING' }}</span>
                                @elseif(is_array($st) && ($st['reachable'] ?? false))
                                    <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                                    <span class="text-xs text-red-600 dark:text-red-400">{{ $st['status'] ?? 'Not running' }}</span>
                                @else
                                    <span class="inline-block w-2 h-2 rounded-full bg-yellow-500"></span>
                                    <span class="text-xs text-yellow-600 dark:text-yellow-400">Status unavailable</span>
                                @endif
                                <span class="font-medium">{{ $site->name }}-{{ $proc->name }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $proc->scheduleLabel() }}@if(!$proc->schedule && $proc->numprocs > 1) · ×{{ $proc->numprocs }}@endif
                                </span>
                            </div>
                            <div class="font-mono text-xs text-gray-600 dark:text-gray-300 break-all">{{ $proc->command }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:switch :checked="$proc->enabled" wire:click="toggle({{ $proc->id }})" />
                            <flux:button size="sm" variant="filled" color="blue" icon="play" wire:click="runNow({{ $proc->id }})" wire:loading.attr="disabled" wire:target="runNow({{ $proc->id }})" wire:confirm="Run this command once now, in the foreground (up to 2 minutes)?">Run now</flux:button>
                            @if($proc->enabled)
                                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="restart({{ $proc->id }})">Restart</flux:button>
                            @endif
                            <flux:button size="sm" variant="filled" color="blue" icon="pencil" wire:click="edit({{ $proc->id }})">Edit</flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $proc->id }})" wire:confirm="Remove {{ $proc->name }} from this site's Supervisor config?">Delete</flux:button>
                        </div>
                    </div>
                    <div wire:loading wire:target="runNow({{ $proc->id }})" class="text-xs text-gray-500 dark:text-gray-400">Running…</div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-400 dark:text-gray-500">No custom jobs yet.</p>
    @endif

    @if($runNow)
        <div class="space-y-1">
            <div class="text-xs {{ $runNow['exit'] === 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                {{ $runNow['name'] }} — exit code {{ $runNow['exit'] ?? 'n/a' }}
            </div>
            <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-60 overflow-y-auto font-mono">{{ $runNow['output'] }}</pre>
        </div>
    @endif

    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3">
        <h3 class="text-sm font-medium">{{ $editingId ? 'Edit job' : 'Add a job' }}</h3>

        @unless($editingId)
            <div>
                <label class="block text-sm font-medium mb-1">Job</label>
                <select wire:model.live="preset" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">Custom command…</option>
                    <optgroup label="Built-in">
                        @foreach(\App\Models\SiteProcess::PRESETS as $key => $preset)
                            <option value="{{ $key }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </optgroup>
                    @if($templates->isNotEmpty())
                        <optgroup label="Your templates (all projects)">
                            @foreach($templates as $tpl)
                                <option value="tpl:{{ $tpl->id }}">{{ $tpl->label }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Picking one pre-fills the fields below — everything stays editable. Save your own as a template further down and it appears here for every project.</p>
            </div>
        @endunless

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1">Name</label>
                <input type="text" wire:model="name" placeholder="nightly-report" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium mb-1">Command</label>
                <input type="text" wire:model="command" placeholder="php artisan reports:generate" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Runs from the project root. Not a shell — no <span class="font-mono">&amp;&amp;</span>, pipes or redirects.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1">Run</label>
                <select wire:model.live="runMode" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    <option value="continuous">Continuously (stays running)</option>
                    @foreach(\App\Models\SiteProcess::SCHEDULE_PRESETS as $key => $sp)
                        <option value="{{ $key }}">{{ $sp['label'] }}</option>
                    @endforeach
                    <option value="custom">Custom cron…</option>
                </select>
            </div>

            @if($runMode !== 'continuous')
                @if((\App\Models\SiteProcess::SCHEDULE_PRESETS[$runMode]['timed'] ?? false) || $runMode === 'hourly')
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ $runMode === 'hourly' ? 'Minute past the hour' : 'At (server time)' }}</label>
                        <input type="time" wire:model.live="time" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    </div>
                @endif
                <div class="{{ (\App\Models\SiteProcess::SCHEDULE_PRESETS[$runMode]['timed'] ?? false) || $runMode === 'hourly' ? '' : 'md:col-span-2' }}">
                    <label class="block text-sm font-medium mb-1">Cron expression</label>
                    <input type="text" wire:model.live.debounce.500ms="cron" placeholder="0 9 1,15 * *" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">minute hour day-of-month month day-of-week — e.g. <span class="font-mono">0 9 * * mon,thu</span>, <span class="font-mono">30 2 1 * *</span>, <span class="font-mono">*/15 8-17 * * 1-5</span></p>
                </div>
            @else
                <div>
                    <label class="block text-sm font-medium mb-1">Processes</label>
                    <input type="number" min="1" max="20" wire:model="numprocs" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Stop wait (s)</label>
                    <input type="number" min="1" max="86400" wire:model="stopwaitsecs" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                </div>
            @endif
        </div>

        @if($runMode !== 'continuous')
            @if($cronError)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $cronError }}</p>
            @elseif($cronPreview)
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Next runs: <span class="font-mono">{{ implode('  ·  ', $cronPreview) }}</span>
                </p>
            @endif
        @else
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="autostart" /> Start automatically</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="autorestart" /> Restart if it exits</label>
            </div>
        @endif

        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="primary" icon="{{ $editingId ? 'check' : 'plus' }}" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                {{ $editingId ? 'Save changes' : 'Add job' }}
            </flux:button>
            @if($editingId)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelEdit">Cancel</flux:button>
            @endif
            <span wire:loading wire:target="save" class="text-xs text-gray-500 dark:text-gray-400">Checking config and restarting Supervisor…</span>
        </div>
        @if($error)
            <p class="text-xs text-red-600 dark:text-red-400 whitespace-pre-line">{{ $error }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-2 pt-1">
            <input type="text" wire:model="templateLabel" placeholder="Template name, e.g. Weekly report"
                class="rounded px-3 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm w-64" />
            <flux:button size="sm" variant="filled" color="blue" icon="bookmark" wire:click="saveAsTemplate">Save as template</flux:button>
            @if($templateMessage)
                <span class="text-xs text-green-600 dark:text-green-400">{{ $templateMessage }}</span>
            @endif
        </div>
    </div>

    @if($templates->isNotEmpty())
        <details class="border-t border-gray-200 dark:border-gray-700 pt-3">
            <summary class="cursor-pointer text-sm font-medium">Your saved templates ({{ $templates->count() }}) — shared by all projects</summary>
            <div class="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($templates as $tpl)
                    <div class="py-2 flex items-center justify-between gap-3" wire:key="tpl-{{ $tpl->id }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium">{{ $tpl->label }}</div>
                            <div class="font-mono text-xs text-gray-600 dark:text-gray-300 break-all">{{ $tpl->command }}{{ $tpl->schedule ? '  ·  cron ' . $tpl->schedule : '' }}</div>
                        </div>
                        <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteTemplate({{ $tpl->id }})" wire:confirm="Delete the template &quot;{{ $tpl->label }}&quot;? Jobs already added from it are not affected.">Delete</flux:button>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <details class="border-t border-gray-200 dark:border-gray-700 pt-3" @if($extra !== '' || $extraError) open @endif>
        <summary class="cursor-pointer text-sm font-medium">Advanced: raw Supervisor config</summary>
        <div class="mt-3 space-y-2">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                For anything the fields above can't express (<span class="font-mono">environment=</span>,
                <span class="font-mono">stopsignal=</span>, <span class="font-mono">priority=</span>, …). Only
                <span class="font-mono">[program:{{ $site->name }}-something]</span> sections are allowed, and it's
                validated exactly like the jobs above before it's saved.
            </p>
            <textarea wire:model="extra" rows="8" spellcheck="false"
                placeholder="[program:{{ $site->name }}-imports]&#10;command=php artisan imports:watch&#10;directory={{ $site->projectRoot() }}&#10;autorestart=true"
                class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-xs"></textarea>
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="primary" icon="check" wire:click="saveExtra" wire:loading.attr="disabled" wire:target="saveExtra">Validate &amp; save</flux:button>
                @if($extraSaved)
                    <span class="text-xs text-green-600 dark:text-green-400">Saved and applied.</span>
                @endif
            </div>
            @if($extraError)
                <p class="text-xs text-red-600 dark:text-red-400 whitespace-pre-line">{{ $extraError }}</p>
            @endif
        </div>
    </details>
</div>
</flux:modal>
</div>
