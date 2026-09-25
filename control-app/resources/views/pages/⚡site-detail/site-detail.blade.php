
<div class="space-y-6" wire:poll.{{ $pollIntervalSeconds }}s.visible="livePoll">
    @php
        $dbType = $site->databaseType();
        $dbLabel = match ($dbType) {
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            default => 'None',
        };

        $dbName = str_replace('-', '_', $site->name);

        $sqlitePath = $site->projectRoot() . '/database/database.sqlite';
        $adminerUrl = match ($dbType) {
            'mysql' => "/adminer.php?server=127.0.0.1&username=root&db={$dbName}",
            'pgsql' => "/adminer.php?pgsql=127.0.0.1&username=postgres&db={$dbName}",
            'sqlite' => '/adminer.php?sqlite=' . urlencode($sqlitePath) . '&db=' . urlencode($sqlitePath),
            default => null,
        };

        $usesS3 = $site->usesS3();
@endphp

    <div class="flex justify-between items-center">
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-blue-500">&larr; Sites</a>
            <h1 class="text-2xl font-semibold">{{ $site->name }}</h1>
            <a href="https://{{ $site->domain }}" target="_blank" class="text-sm text-blue-500">{{ $site->domain }}</a>
        </div>
        <div class="flex items-center gap-2">

            <div
                class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500"
                title="{{ $isPaused ? 'This page has stopped auto-refreshing — nothing on the site itself is paused' : "This page refreshes itself automatically every {$pollIntervalSeconds}s" }}"
            >
                <span
                    class="inline-block w-2 h-2 rounded-full {{ $isPaused ? 'bg-gray-400' : 'bg-green-500 animate-pulse' }}"
                    wire:loading.class="ring-2 ring-blue-400"
                    wire:target="livePoll"
                ></span>
                {{ $isPaused ? 'Page updates paused' : 'Page updates live' }}
            </div>

            <select wire:model.live="pollIntervalSeconds" title="How often this page refreshes itself while live"
                class="text-xs rounded px-2 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                @foreach([5, 10, 15, 30, 60] as $seconds)
                    <option value="{{ $seconds }}">Every {{ $seconds }}s</option>
                @endforeach
            </select>
            <flux:button size="sm" variant="filled" color="blue" icon="{{ $isPaused ? 'play' : 'pause' }}" wire:click="togglePause"
                title="{{ $isPaused ? 'Resume auto-refreshing this page' : 'Freeze this page so nothing on it changes while you look at it' }}">
                {{ $isPaused ? 'Resume page updates' : 'Pause page updates' }}
            </flux:button>
            <a href="https://{{ $site->domain }}" target="_blank"><flux:button size="sm" icon="arrow-top-right-on-square">Open site</flux:button></a>
            <flux:button size="sm" variant="danger" icon="minus-circle" wire:click="startDelete('list')">Delete from list</flux:button>
            <flux:button size="sm" variant="danger" icon="trash" wire:click="startDelete('disk')">Delete from disk</flux:button>
        </div>
    </div>

    <x-site-tabs :site="$site" active="detail" />

    @if(! $hasManifest)
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
            <div class="text-sm text-blue-900 dark:text-blue-200">
                <p class="font-medium">This project has no <span class="font-mono">ldev.json</span> yet.</p>
                <p class="text-blue-800 dark:text-blue-300">Create one from this site's current settings and commit it, so everyone who clones the project gets the same PHP and Node versions, database, workers and jobs automatically. It never contains passwords or <span class="font-mono">.env</span> values.</p>
                @if($manifestError)
                    <p class="text-red-600 dark:text-red-400 mt-1">{{ $manifestError }}</p>
                @endif
            </div>
            <flux:button size="sm" variant="primary" icon="document-plus" wire:click="createManifest" wire:loading.attr="disabled" wire:target="createManifest">
                Create ldev.json
            </flux:button>
        </div>
    @elseif($manifestCreated)
        <div class="p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 text-sm text-green-800 dark:text-green-300">
            Created <span class="font-mono">ldev.json</span> in the project root. Commit it to share the setup. You can review or update it under
            <a href="{{ route('site-settings', $site) }}" wire:navigate class="underline">Project settings</a>.
        </div>
    @endif

    @if($pendingDeleteMode)
        <div class="max-w-lg p-4 rounded border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 space-y-3">
            @if($pendingDeleteMode === 'disk')
                <h2 class="font-medium">Permanently delete "{{ $site->name }}"?</h2>
                <p class="text-sm">This deletes the project directory itself ({{ $site->projectRoot() }}) — all its files, git history, and database — and cannot be undone.</p>
            @else
                <h2 class="font-medium">Remove "{{ $site->name }}" from the dashboard?</h2>
                <p class="text-sm">This removes its nginx/Supervisor config and database record — the project files stay on disk, and "Scan for untracked projects" on the Sites page will pick it back up later if you want it back.</p>
            @endif

            @if($gitStatus['hasRepo'] ?? false)
                @if($gitStatus['dirty'] || $gitStatus['ahead'] > 0)
                    <div class="text-sm bg-white dark:bg-gray-800 rounded p-3 space-y-1.5">
                        <p class="font-medium text-red-700 dark:text-red-400">⚠ This repository is not fully saved to its remote:</p>
                        @if($gitStatus['dirty'])
                            <p>Uncommitted local changes{{ $pendingDeleteMode === 'disk' ? ' — these will be permanently lost, pushing can\'t save them' : ' (untouched by this action either way)' }}.</p>
                        @endif
                        @if($gitStatus['ahead'] > 0)
                            <p>{{ $gitStatus['ahead'] }} commit(s) not yet pushed to origin.</p>
                            <flux:button size="sm" variant="filled" color="blue" icon="cloud-arrow-up" wire:click="pushBeforeDelete"
                                wire:loading.attr="disabled" wire:target="pushBeforeDelete">
                                Push now
                            </flux:button>
                        @elseif(!$gitStatus['hasUpstream'])
                            <p class="text-xs text-gray-500 dark:text-gray-400">No remote configured — there's nowhere to push this to; local-only history will be lost if you delete from disk.</p>
                        @endif
                        @if($pushBeforeDeleteError)
                            <p class="text-xs text-red-600 dark:text-red-400">{{ $pushBeforeDeleteError }}</p>
                        @endif
                    </div>
                @else
                    <p class="text-xs text-green-600 dark:text-green-400">Git is up to date with origin — nothing unsaved.</p>
                @endif
            @endif

            <div class="flex gap-2 pt-1">
                <flux:button variant="danger" icon="{{ $pendingDeleteMode === 'disk' ? 'trash' : 'minus-circle' }}" wire:click="confirmDelete">
                    {{ $pendingDeleteMode === 'disk' ? 'Delete from disk' : 'Delete from list' }}
                </flux:button>
                <flux:button variant="ghost" icon="x-mark" wire:click="cancelDeleteReview">
                    Cancel
                </flux:button>
            </div>
        </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <h2 class="font-medium">Quick launch</h2>
        <div class="flex gap-2 flex-wrap">
            <a href="http://127.0.0.1:8025" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="envelope">Mailpit</flux:button></a>
            @if($usesS3)
                <a href="{{ config('ldev.s3.console') }}" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="cube">S3 console</flux:button></a>
            @endif
            @if($site->usesMeilisearch())
                <a href="{{ config('ldev.meilisearch_url') }}" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="magnifying-glass">Meilisearch</flux:button></a>
            @endif
            @if($adminerUrl)
                <a href="{{ $adminerUrl }}" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="circle-stack">Adminer ({{ $dbLabel }})</flux:button></a>
            @endif
            <a href="{{ route('logs') }}" wire:navigate><flux:button size="sm" variant="filled" color="blue" icon="document-text">Logs</flux:button></a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-2">
            <h2 class="font-medium mb-2">Details</h2>
            <p class="text-sm break-all"><span class="text-gray-500 dark:text-gray-400">Path:</span> {{ $site->projectRoot() }}</p>
            @php $framework = $site->frameworkInfo();@endphp
            @if($framework)
                <p class="text-sm"><span class="text-gray-500 dark:text-gray-400">Framework:</span> {{ $framework['name'] }} {{ $framework['version'] }}</p>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-2">
            <div class="flex justify-between items-center mb-2">
                <h2 class="font-medium">Git</h2>
            </div>
            @if(!$gitStatus['hasRepo'])
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Not a git repository.</p>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="initGitRepo">Initialize repository</flux:button>
            @else
                <p class="text-sm"><span class="text-gray-500 dark:text-gray-400">Branch:</span> {{ $gitStatus['branch'] ?? '(detached)' }}</p>

                @if($gitStatus['dirty'] || ($gitStatus['hasUpstream'] && $gitStatus['outOfSync']))
                    <div class="text-sm text-yellow-800 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-900/30 rounded px-2 py-1.5 space-y-0.5">
                        <p class="font-medium">⚠ Out of step</p>
                        @if($gitStatus['dirty'])<p>Uncommitted local changes.</p>@endif
                        @if($gitStatus['ahead'] > 0)<p>{{ $gitStatus['ahead'] }} commit(s) ahead of origin.</p>@endif
                        @if($gitStatus['behind'] > 0)<p>{{ $gitStatus['behind'] }} commit(s) behind origin.</p>@endif
                    </div>
                    @if($gitStatus['dirty'])
                        <flux:button size="sm" variant="filled" color="blue" icon="document-magnifying-glass" wire:click="openGitDiff">View diff</flux:button>
                    @endif
                @elseif($gitStatus['hasUpstream'])
                    <p class="text-sm text-green-600 dark:text-green-400">Up to date with origin.</p>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">No remote tracking branch configured — this project's git history only exists on this machine.</p>

                    @if(!$showPushForm)
                        <flux:button size="sm" variant="primary" icon="cloud-arrow-up" wire:click="$set('showPushForm', true)">Push to new remote</flux:button>
                    @else
                        <div class="space-y-2 max-w-sm">
                            <div>
                                <label class="block text-sm font-medium mb-1">Using token</label>
                                <select wire:model="pushTokenId" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                    <option value="">Select a token…</option>
                                    @foreach($repositoryTokens as $repoToken)
                                        <option value="{{ $repoToken->id }}">{{ $repoToken->username }} ({{ $repoToken->provider }})</option>
                                    @endforeach
                                </select>
                                @if($repositoryTokens->isEmpty())
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No tokens saved yet — add one on the <a href="{{ route('repositories') }}" wire:navigate class="text-blue-500">Repositories</a> page first.</p>
                                @endif
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Visibility</label>
                                <select wire:model="pushVisibility" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                    <option value="private">Private</option>
                                    <option value="public">Public</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="primary" icon="cloud-arrow-up" wire:click="pushToNewRepo" wire:loading.attr="disabled" wire:target="pushToNewRepo">Create and push</flux:button>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('showPushForm', false)">Cancel</flux:button>
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="pushToNewRepo">Creating repository and pushing — this can take a few seconds…</p>
                            @if($pushError)
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $pushError }}</p>
                            @endif
                        </div>
                    @endif
                @endif
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-2">
            <div class="flex justify-between items-center mb-2">
                <h2 class="font-medium">Resources</h2>
            </div>
            <p class="text-sm"><span class="text-gray-500 dark:text-gray-400">Project disk usage:</span> {{ $diskUsage ?? 'unknown' }}</p>
            @if($fpmStats && ($fpmStats['running'] ?? false))
                <p class="text-sm">
                    <span class="text-gray-500 dark:text-gray-400">PHP {{ $site->php_version }} pool:</span>
                    {{ $fpmStats['workers'] }} worker(s) running, {{ $fpmStats['memoryMb'] }} MB
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    PHP-FPM runs one pool per PHP version, shared by every site on {{ $site->php_version }} — not exclusive to this one.
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">PHP {{ $site->php_version }} pool isn't running.</p>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
            <h2 class="font-medium">Public demo link</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Shares this site with a real public HTTPS URL via a Cloudflare quick tunnel — no account needed. Meant to be temporary: start it right before showing someone, stop it after.
            </p>

            @if($tunnelUrl)
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="{{ $tunnelUrl }}" target="_blank" class="text-sm text-blue-500 break-all">{{ $tunnelUrl }}</a>
                    <flux:button size="sm" variant="ghost" icon="clipboard-document" onclick="navigator.clipboard.writeText('{{ $tunnelUrl }}')">Copy</flux:button>
                </div>
            @endif

            <flux:button
                size="sm"
                variant="{{ $tunnelUrl ? 'danger' : 'primary' }}"
                icon="{{ $tunnelUrl ? 'stop' : 'share' }}"
                wire:click="toggleTunnel"
                wire:loading.attr="disabled"
                wire:target="toggleTunnel"
            >
                {{ $tunnelUrl ? 'Stop sharing' : 'Share this site' }}
            </flux:button>
            <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="toggleTunnel">
                {{ $tunnelUrl ? 'Stopping…' : 'Starting tunnel — this can take a few seconds…' }}
            </span>

            @if($tunnelError)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $tunnelError }}</p>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <button type="button" class="flex items-center gap-2 font-medium text-left" wire:click="toggleSection('dependencies')" aria-expanded="{{ $openSections['dependencies'] ? 'true' : 'false' }}"><span class="inline-flex transition-transform {{ $openSections['dependencies'] ? 'rotate-90' : '' }}"><flux:icon name="chevron-right" class="size-4" /></span>Dependencies</button>
            <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="checkDependencies" wire:loading.attr="disabled" wire:target="checkDependencies">Check now</flux:button>
        </div>
        @if(!$openSections['dependencies'])
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            @if(!$dependencies)
                <span class="text-gray-500 dark:text-gray-400">Not checked yet</span>
            @elseif(!$dependencies['hasComposer'] && !$dependencies['hasNpm'])
                <span class="text-gray-500 dark:text-gray-400">Nothing to check</span>
            @else
                @php $advisoryCount = count(collect($dependencies['advisories'] ?? [])->pluck('package')->unique()); @endphp
                @if($dependencies['hasComposer'])
                    <span class="{{ $advisoryCount ? 'text-red-600 dark:text-red-400 font-medium' : 'text-green-600 dark:text-green-400' }}">{{ $advisoryCount ? $advisoryCount . ' vulnerable package(s)' : 'No advisories' }}</span>
                    <span class="text-gray-600 dark:text-gray-300">{{ count($dependencies['composerOutdated'] ?? []) }} Composer outdated</span>
                @endif
                @if($dependencies['hasNpm'])
                    <span class="text-gray-600 dark:text-gray-300">{{ count($dependencies['npmOutdated'] ?? []) }} npm outdated</span>
                @endif
                @if($sandbox)
                    <span class="{{ $sandbox['state'] === 'running' ? 'text-blue-600 dark:text-blue-400' : ($sandbox['state'] === 'done' && $sandbox['verdict'] === 'pass' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') }}">
                        Sandbox: {{ $sandbox['state'] === 'running' ? 'testing…' : ($sandbox['state'] === 'done' ? ($sandbox['verdict'] === 'pass' ? 'ready to apply' : 'problems found') : 'interrupted') }}
                    </span>
                @endif
                <span class="text-xs text-gray-400 dark:text-gray-500">Checked {{ \Illuminate\Support\Carbon::parse($dependencies['checkedAt'])->diffForHumans() }}</span>
            @endif
        </div>
        @endif
        @if($openSections['dependencies'])
        <div class="space-y-3">
        <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="checkDependencies">Running composer audit/outdated and npm outdated&hellip;</p>
        @if(!$dependencies)
            <p class="text-sm text-gray-500 dark:text-gray-400">Not checked yet. Checked automatically once a day, or click "Check now".</p>
        @elseif(!$dependencies['hasComposer'] && !$dependencies['hasNpm'])
            <p class="text-sm text-gray-500 dark:text-gray-400">No composer.lock or installed npm packages to check.</p>
        @else
            @php
                $advisories = $dependencies['advisories'] ?? [];
                $composerOutdated = $dependencies['composerOutdated'] ?? [];
                $npmOutdated = $dependencies['npmOutdated'] ?? [];
            @endphp
            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                @if($dependencies['hasComposer'])
                    <span class="{{ count($advisories) ? 'text-red-600 dark:text-red-400 font-medium' : 'text-green-600 dark:text-green-400' }}">
                        {{ count($advisories) ? count($advisories) . ' security advisory(ies)' : 'No known security advisories' }}
                    </span>
                    <span class="text-gray-600 dark:text-gray-300">{{ count($composerOutdated) }} outdated Composer package(s)</span>
                @endif
                @if($dependencies['hasNpm'])
                    <span class="text-gray-600 dark:text-gray-300">{{ count($npmOutdated) }} outdated npm package(s)</span>
                @endif
                <span class="text-xs text-gray-400 dark:text-gray-500 self-center">Checked {{ \Illuminate\Support\Carbon::parse($dependencies['checkedAt'])->diffForHumans() }}</span>
            </div>

            @foreach(['security' => ['Composer', 'composer'], 'npm-security' => ['npm', 'npm']] as $securitySection => [$securityLabel, $securityManager])
                @php $securityRows = collect($advisories)->filter(fn ($adv) => ($adv['manager'] ?? 'composer') === $securityManager); @endphp
                @if($securityRows->count())
                    <details class="text-sm" wire:ignore.self wire:key="security-{{ $securitySection }}">
                        <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Security advisories, {{ $securityLabel }} ({{ $securityRows->pluck('package')->unique()->count() }} package(s), {{ $securityRows->count() }} advisories)</summary>
                        <div class="mt-2 flex flex-wrap justify-end gap-2">
                            <flux:button size="sm" icon="beaker" wire:click="testDependencyUpdate('{{ $securitySection }}')" :disabled="($sandbox['state'] ?? null) === 'running'">Test selected</flux:button>
                            <flux:button size="sm" variant="primary" icon="shield-check" wire:click="testDependencyUpdate('{{ $securitySection }}', true)" :disabled="($sandbox['state'] ?? null) === 'running'">{{ $securityManager === 'npm' ? 'Test npm audit fix' : 'Test all vulnerable' }}</flux:button>
                        </div>
                        <div class="mt-2 rounded border border-red-200 dark:border-red-900 divide-y divide-red-100 dark:divide-red-900 text-sm">
                            @foreach($securityRows->groupBy('package') as $package => $items)
                                @php
                                    $severityOrder = ['critical', 'high', 'moderate', 'medium', 'low'];
                                    $severities = collect($items)->countBy(fn ($adv) => strtolower((string) $adv['severity']) ?: 'unknown')
                                        ->sortBy(fn ($n, $level) => array_search($level, $severityOrder) === false ? 99 : array_search($level, $severityOrder));
                                @endphp
                                <details class="px-3 py-2" wire:ignore.self wire:key="adv-{{ $securitySection }}-{{ md5($package) }}">
                                    <summary class="cursor-pointer">
                                        <span class="inline-flex flex-wrap items-center gap-2">
                                            <input type="checkbox" value="{{ $package }}" wire:model="selectedPackages.{{ $securitySection }}" onclick="event.stopPropagation()" title="Include this package in Test selected">
                                            <span class="font-mono">{{ $package }}</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($items) }} {{ \Illuminate\Support\Str::plural('advisory', count($items)) }}:
                                                {{ $severities->map(fn ($n, $level) => $n . ' ' . $level)->implode(', ') }}</span>
                                        </span>
                                    </summary>
                                    <div class="mt-1 space-y-1">
                                    @foreach($items as $adv)
                                        <div class="pl-6">
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if($adv['severity'])<span class="text-xs uppercase text-red-600 dark:text-red-400">{{ $adv['severity'] }}</span>@endif
                                                @if($adv['cve'])<span class="text-xs text-gray-500 dark:text-gray-400">{{ $adv['cve'] }}</span>@endif
                                            </div>
                                            <p class="text-gray-600 dark:text-gray-300">
                                                @if($adv['link'])<a href="{{ $adv['link'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $adv['title'] }}</a>@else{{ $adv['title'] }}@endif
                                            </p>
                                            @if($adv['affected'])<p class="text-xs text-gray-400 dark:text-gray-500">Affected: {{ $adv['affected'] }}</p>@endif
                                        </div>
                                    @endforeach
                                    </div>
                                </details>
                            @endforeach
                        </div>
                        @if($securityManager === 'npm')
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Selected packages are tested with npm update; <em>Test npm audit fix</em> tries npm's own fix for everything it can, within your version ranges.</p>
                        @endif
                    </details>
                @endif
            @endforeach

            @foreach(['composer' => ['Composer', $composerOutdated], 'npm' => ['npm', $npmOutdated]] as $section => [$label, $rows])
                @if(count($rows))
                    <details class="text-sm" wire:ignore.self wire:key="outdated-{{ $section }}">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Outdated {{ $label }} packages ({{ count($rows) }})</summary>
                        <div class="mt-2 flex flex-wrap justify-end gap-2">
                            <flux:button size="sm" icon="beaker" wire:click="testDependencyUpdate('{{ $section }}')" :disabled="($sandbox['state'] ?? null) === 'running'">Test selected</flux:button>
                            <flux:button size="sm" variant="primary" icon="arrow-up-circle" wire:click="testDependencyUpdate('{{ $section }}', true)" :disabled="($sandbox['state'] ?? null) === 'running'">Test all {{ $label }}</flux:button>
                            @if(collect($rows)->contains('major', true))
                                <flux:button size="sm" variant="filled" color="blue" icon="rocket-launch" wire:click="testDependencyUpdate('{{ $section }}', false, true)" :disabled="($sandbox['state'] ?? null) === 'running'" title="Raise the version constraint of the ticked packages to their latest major version, in the sandbox only">Test major upgrade</flux:button>
                                <flux:button size="sm" variant="filled" color="blue" icon="rocket-launch" wire:click="testDependencyUpdate('{{ $section }}', true, true)" :disabled="($sandbox['state'] ?? null) === 'running'" title="Try every yellow major-version jump at once, in the sandbox only">Test all majors</flux:button>
                            @endif
                        </div>
                        <div class="mt-2 rounded bg-gray-50 dark:bg-gray-900 p-2 font-mono text-xs space-y-1">
                            @foreach($rows as $row)
                                <label class="flex justify-between gap-4">
                                    <span class="flex items-center gap-2">
                                        <input type="checkbox" value="{{ $row['name'] }}" wire:model="selectedPackages.{{ $section }}">
                                        {{ $row['name'] }}
                                    </span>
                                    <span class="{{ $row['major'] ? 'text-yellow-700 dark:text-yellow-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $row['current'] ?? 'missing' }} &rarr; {{ $row['latest'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Tests run in a sandbox copy of the project first; nothing changes until you apply. <em>Test selected</em> and <em>Test all</em> stay within the version constraints in {{ $section === 'npm' ? 'package.json' : 'composer.json' }}. For the yellow major-version jumps use <em>Test major upgrade</em>, which raises the constraint in the sandbox copy (and in the project only if you apply it).</p>
                    </details>
                @endif
            @endforeach

            @if($sandbox)
                @php
                    $sandboxState = $sandbox['state'];
                    $noteClasses = [
                        'danger' => 'text-red-600 dark:text-red-400',
                        'warning' => 'text-yellow-700 dark:text-yellow-400',
                        'info' => 'text-gray-600 dark:text-gray-300',
                    ];
                @endphp
                <div class="rounded border border-gray-200 dark:border-gray-700 p-3 space-y-3 text-sm">
                    <div class="flex flex-wrap justify-between items-center gap-2">
                        <div>
                            <span class="font-medium">Sandbox test</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                &mdash; {{ $sandbox['section'] }}:
                                {{ $sandbox['all'] ? 'all packages' : implode(', ', $sandbox['packages']) }}{{ !empty($sandbox['major']) ? ' (major upgrade)' : '' }}
                            </span>
                        </div>
                        @if($sandboxState === 'running')
                            <span class="text-blue-600 dark:text-blue-400">Running&hellip;</span>
                        @elseif($sandboxState === 'interrupted')
                            <span class="text-red-600 dark:text-red-400">Interrupted</span>
                        @elseif($sandbox['verdict'] === 'pass')
                            <span class="text-green-600 dark:text-green-400 font-medium">No problems found</span>
                        @else
                            <span class="text-red-600 dark:text-red-400 font-medium">Problems found</span>
                        @endif
                    </div>

                    <ul class="space-y-1">
                        @foreach($sandbox['steps'] as $step)
                            <li>
                                <details wire:ignore.self wire:key="sandbox-step-{{ $loop->index }}">
                                    <summary class="cursor-pointer">
                                        <span class="{{ ['ok' => 'text-green-600 dark:text-green-400', 'failed' => 'text-red-600 dark:text-red-400', 'running' => 'text-blue-600 dark:text-blue-400'][$step['status']] ?? '' }}">{{ ['ok' => '✓', 'failed' => '✗', 'running' => '…'][$step['status']] ?? '' }}</span>
                                        {{ $step['name'] }}
                                    </summary>
                                    @if($step['output'] !== '')
                                        <pre class="mt-1 max-h-64 overflow-auto rounded bg-gray-50 dark:bg-gray-900 p-2 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $step['output'] }}</pre>
                                    @endif
                                </details>
                            </li>
                        @endforeach
                    </ul>

                    @if(count($sandbox['notes']))
                        <div class="space-y-1">
                            <p class="font-medium">Notes</p>
                            @foreach($sandbox['notes'] as $note)
                                <p class="{{ $noteClasses[$note['level']] ?? '' }}">{{ $note['text'] }}</p>
                            @endforeach
                        </div>
                    @endif

                    @if(count($sandbox['changes']))
                        <details wire:ignore.self wire:key="sandbox-changes">
                            <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Version changes ({{ count($sandbox['changes']) }})</summary>
                            <div class="mt-2 rounded bg-gray-50 dark:bg-gray-900 p-2 font-mono text-xs space-y-1">
                                @foreach($sandbox['changes'] as $change)
                                    <div class="flex justify-between gap-4">
                                        <span>{{ $change['name'] }}</span>
                                        <span class="{{ $change['major'] ? 'text-yellow-700 dark:text-yellow-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $change['from'] }} &rarr; {{ $change['to'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if($sandboxState !== 'running')
                        <div class="flex flex-wrap gap-2">
                            @if($sandboxState === 'done' && count($sandbox['changes']))
                                <flux:button size="sm" variant="{{ $sandbox['verdict'] === 'pass' ? 'primary' : 'danger' }}" icon="arrow-down-tray" wire:click="applyDependencyUpdate"
                                    wire:confirm="{{ $sandbox['verdict'] === 'pass' ? 'Apply the tested versions to the project?' : 'The sandbox found problems. Apply the tested versions to the project anyway?' }}"
                                    wire:loading.attr="disabled" wire:target="applyDependencyUpdate">
                                    {{ $sandbox['verdict'] === 'pass' ? 'Apply to project' : 'Apply anyway' }}
                                </flux:button>
                            @endif
                            <flux:button size="sm" icon="trash" wire:click="discardDependencySandbox">Discard sandbox</flux:button>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="applyDependencyUpdate">Installing the tested versions into the project&hellip;</p>
                    @endif
                </div>
            @endif

            @if($dependencyUpdateResult)
                <div class="text-sm space-y-1">
                    <p class="{{ $dependencyUpdateResult['ok'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $dependencyUpdateResult['ok'] ? 'Applied to the project' : 'Not applied' }}
                    </p>
                    <pre class="max-h-64 overflow-auto rounded bg-gray-50 dark:bg-gray-900 p-2 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $dependencyUpdateResult['output'] }}</pre>
                </div>
            @endif

            @foreach($dependencies['errors'] ?? [] as $error)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $error }}</p>
            @endforeach
        @endif
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        @php
            $testRunning = ($testRun['state'] ?? null) === 'running';
            $testResults = collect($testRun['results'] ?? []);
            $resultsByFile = $testResults->groupBy('file');

            $statusClass = [
                'passed' => 'text-green-600 dark:text-green-400',
                'failed' => 'text-red-600 dark:text-red-400',
                'error' => 'text-red-600 dark:text-red-400',
                'skipped' => 'text-yellow-700 dark:text-yellow-400',
            ];
            $testConfirm = $testSuite['unsafe'] ?? null ? 'Warning: ' . $testSuite['unsafe'] . ' Run anyway?' : null;
        @endphp
        <div class="flex flex-wrap justify-between items-center gap-2">
            <button type="button" class="flex items-center gap-2 font-medium text-left" wire:click="toggleSection('tests')" aria-expanded="{{ $openSections['tests'] ? 'true' : 'false' }}"><span class="inline-flex transition-transform {{ $openSections['tests'] ? 'rotate-90' : '' }}"><flux:icon name="chevron-right" class="size-4" /></span>Tests</button>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="loadTestSuite">Reload list</flux:button>
                @if(count($testSuite['files'] ?? []))
                    <flux:button size="sm" icon="play" wire:click="runTests" :wire:confirm="$testConfirm" :disabled="$testRunning">Run selected</flux:button>
                    <flux:button size="sm" variant="primary" icon="play-circle" wire:click="runTests(true)" :wire:confirm="$testConfirm" :disabled="$testRunning">Run all</flux:button>
                    @if($testResults->whereIn('status', ['failed', 'error'])->count())
                        <flux:button size="sm" variant="filled" color="blue" icon="arrow-uturn-right" wire:click="runFailedTests" :wire:confirm="$testConfirm" :disabled="$testRunning">Run failed</flux:button>
                    @endif
                @endif
            </div>
        </div>
        @if(!$openSections['tests'])
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
            <span class="text-gray-500 dark:text-gray-400">{{ array_sum(array_map('count', $testSuite['files'] ?? [])) }} test(s) in {{ count($testSuite['files'] ?? []) }} file(s)</span>
            @if($testRun)
                @php
                    $summaryCounts = $testResults->countBy('status');
                    $summaryFailed = ($summaryCounts['failed'] ?? 0) + ($summaryCounts['error'] ?? 0);
                @endphp
                @if($testRunning)
                    <span class="text-blue-600 dark:text-blue-400">Running&hellip;</span>
                @elseif($testRun['state'] === 'interrupted')
                    <span class="text-red-600 dark:text-red-400">Last run interrupted</span>
                @endif
                <span class="text-green-600 dark:text-green-400">{{ $summaryCounts['passed'] ?? 0 }} passed</span>
                <span class="{{ $summaryFailed ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-gray-400' }}">{{ $summaryFailed }} failed</span>
                @if($testRun['finishedAt'])
                    <span class="text-xs text-gray-400 dark:text-gray-500">Last run {{ \Illuminate\Support\Carbon::parse($testRun['finishedAt'])->diffForHumans() }}</span>
                @endif
            @else
                <span class="text-xs text-gray-400 dark:text-gray-500">Not run yet</span>
            @endif
        </div>
        @endif
        @if($openSections['tests'])
        <div class="space-y-3">

        @if(!count($testSuite['files'] ?? []))
            <p class="text-sm text-gray-500 dark:text-gray-400">No test files found (looked in the phpunit.xml test suites, or tests/).</p>
        @else
            <p class="text-xs text-gray-400 dark:text-gray-500">
                {{ count($testSuite['files']) }} file(s), {{ array_sum(array_map('count', $testSuite['files'])) }} test(s) found, run with {{ implode(' and ', array_filter([$testSuite['runner'] ?? null, $testSuite['jsRunner'] ?? null])) ?: 'no runner installed' }}. Each file runs on its own, so one broken file does not stop the rest.
            </p>
            @php $suiteCounts = collect($testSuite['suites'] ?? [])->countBy(); @endphp
            <div class="flex flex-wrap items-center gap-2 text-sm">
                @if($suiteCounts->count() > 1)
                    <span class="text-gray-500 dark:text-gray-400">Run a suite:</span>
                    @foreach($suiteCounts as $suiteName => $suiteFiles)
                        <flux:button size="xs" icon="play" wire:click="runTestSuite('{{ $suiteName }}')" wire:key="suite-{{ md5($suiteName) }}" :wire:confirm="$testConfirm" :disabled="$testRunning">{{ $suiteName }} ({{ $suiteFiles }})</flux:button>
                    @endforeach
                @endif
                <label class="flex items-center gap-2 text-gray-600 dark:text-gray-300" title="Measures which lines of your code the tests run, using Xdebug. Slower than a normal run. PHP tests only.">
                    <input type="checkbox" wire:model="testCoverage"> Measure code coverage
                </label>
            </div>
            @if($testSuite['unsafe'])
                <p class="text-sm text-yellow-700 dark:text-yellow-400">Warning: {{ $testSuite['unsafe'] }}</p>
            @endif
            @if($testError)
                <p class="text-sm text-red-600 dark:text-red-400">{{ $testError }}</p>
            @endif

            @if($testRun)
                @php $counts = $testResults->countBy('status'); @endphp
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                    @if($testRunning)
                        <span class="text-blue-600 dark:text-blue-400">Running{{ $testRun['current'] ? ' ' . $testRun['current'] : '' }}&hellip;</span>
                    @elseif($testRun['state'] === 'interrupted')
                        <span class="text-red-600 dark:text-red-400">Interrupted</span>
                    @endif
                    <span class="text-green-600 dark:text-green-400">{{ $counts['passed'] ?? 0 }} passed</span>
                    <span class="{{ ($counts['failed'] ?? 0) + ($counts['error'] ?? 0) ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-gray-400' }}">{{ ($counts['failed'] ?? 0) + ($counts['error'] ?? 0) }} failed</span>
                    <span class="text-yellow-700 dark:text-yellow-400" title="Tests that chose not to run (markTestSkipped / ->skip()), usually because something they need is missing. Not a failure.">{{ $counts['skipped'] ?? 0 }} skipped</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ number_format($testResults->sum('time'), 2) }}s</span>
                    @if($testRun['finishedAt'])
                        <span class="text-xs text-gray-400 dark:text-gray-500">Finished {{ \Illuminate\Support\Carbon::parse($testRun['finishedAt'])->diffForHumans() }}</span>
                    @endif
                    <label class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                        Show
                        <select wire:model.live="testFilter" class="rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                            <option value="all">All tests</option>
                            <option value="failed">Failures only</option>
                            <option value="skipped">Skipped only</option>
                        </select>
                    </label>
                    @if(!$testRunning)
                        <flux:button size="xs" variant="danger" icon="trash" wire:click="clearTestResults" wire:confirm="Clear the stored test results?">Clear results</flux:button>
                    @endif
                </div>
                @foreach($testRun['hints'] ?? [] as $hint)
                    <p class="text-sm text-yellow-700 dark:text-yellow-400">{{ $hint }}</p>
                @endforeach
                @if(!empty($testRun['coverage']))
                    @php $coverage = $testRun['coverage']; @endphp
                    <details class="text-sm" wire:ignore.self wire:key="test-coverage">
                        <summary class="cursor-pointer text-gray-600 dark:text-gray-300">
                            Code coverage <span class="font-medium {{ $coverage['percent'] >= 80 ? 'text-green-600 dark:text-green-400' : ($coverage['percent'] >= 50 ? 'text-yellow-700 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">{{ $coverage['percent'] }}%</span>
                            ({{ number_format($coverage['covered']) }} of {{ number_format($coverage['lines']) }} lines in {{ $coverage['fileCount'] }} files)
                        </summary>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Lines of the code listed in phpunit.xml's &lt;source&gt; that at least one of the tests in this run executed. The files with the lowest coverage:</p>
                        <div class="mt-2 rounded bg-gray-50 dark:bg-gray-900 p-2 font-mono text-xs space-y-1">
                            @foreach($coverage['lowest'] as $row)
                                <div class="flex justify-between gap-4">
                                    <span class="break-all">{{ $row['file'] }}</span>
                                    <span class="shrink-0 {{ $row['percent'] >= 80 ? 'text-green-600 dark:text-green-400' : ($row['percent'] >= 50 ? 'text-yellow-700 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">{{ $row['percent'] }}% ({{ $row['covered'] }}/{{ $row['lines'] }})</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endif

            @if(count($testHistory))
                @php $flaky = \App\Services\TestRunner::flaky($testHistory); @endphp
                <details class="text-sm" wire:ignore.self wire:key="test-history">
                    <summary class="cursor-pointer text-gray-600 dark:text-gray-300">
                        Recent runs ({{ count($testHistory) }})
                        @if(count($flaky))<span class="text-yellow-700 dark:text-yellow-400">· {{ count($flaky) }} possibly flaky test(s)</span>@endif
                    </summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-3 font-normal">Finished</th>
                                    <th class="py-1 pr-3 font-normal">Files</th>
                                    <th class="py-1 pr-3 font-normal">Passed</th>
                                    <th class="py-1 pr-3 font-normal">Failed</th>
                                    <th class="py-1 pr-3 font-normal">Skipped</th>
                                    <th class="py-1 pr-3 font-normal">Time</th>
                                    <th class="py-1 pr-3 font-normal">Coverage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(array_reverse($testHistory) as $run)
                                    <tr>
                                        <td class="py-1 pr-3">{{ \Illuminate\Support\Carbon::parse($run['finishedAt'])->diffForHumans() }}</td>
                                        <td class="py-1 pr-3">{{ $run['files'] }}</td>
                                        <td class="py-1 pr-3 text-green-600 dark:text-green-400">{{ $run['counts']['passed'] ?? 0 }}</td>
                                        <td class="py-1 pr-3 {{ ($run['counts']['failed'] ?? 0) + ($run['counts']['error'] ?? 0) ? 'text-red-600 dark:text-red-400' : '' }}">{{ ($run['counts']['failed'] ?? 0) + ($run['counts']['error'] ?? 0) }}</td>
                                        <td class="py-1 pr-3 text-yellow-700 dark:text-yellow-400">{{ $run['counts']['skipped'] ?? 0 }}</td>
                                        <td class="py-1 pr-3">{{ $run['time'] }}s</td>
                                        <td class="py-1 pr-3">{{ $run['coverage'] !== null ? $run['coverage'] . '%' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(count($flaky))
                        <p class="mt-2 text-xs text-yellow-700 dark:text-yellow-400">Possibly flaky: these failed in one recent run but passed in another, so the failure may depend on timing, order or shared state rather than the code.</p>
                        <ul class="mt-1 font-mono text-xs space-y-0.5">
                            @foreach(array_slice($flaky, 0, 20) as $id)
                                <li>{{ $id }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-2">
                        <flux:button size="xs" variant="danger" icon="trash" wire:click="clearTestHistory" wire:confirm="Clear the run history?">Clear history</flux:button>
                    </div>
                </details>
            @endif

            <details class="text-sm" wire:ignore.self wire:key="test-environment">
                @php $overrideCount = collect($testEnv)->where('override', true)->count(); @endphp
                <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Test environment ({{ $overrideCount }} override(s) on this machine)</summary>
                <div class="mt-2 space-y-2">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Your own values for this project's test runs, kept in ldev on this machine. The project files ({{ $testEnvFile ?? 'phpunit.xml' }}, .env) are never changed, so each developer can keep their own setup.
                        Tick <em>Override</em> to replace a value from {{ $testEnvFile ?? 'phpunit.xml' }} with your own, or add any other variable. Changes save automatically. Overrides beat both phpunit.xml and .env, except entries marked force="true". To change the project's own copy for everyone, edit it under <a href="{{ route('site-settings', $site) }}" wire:navigate class="text-blue-600 dark:text-blue-400 hover:underline">Project settings → Environment / project files</a>.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-2 font-normal">Variable</th>
                                    <th class="py-1 pr-2 font-normal">Project value</th>
                                    <th class="py-1 pr-2 font-normal">Override</th>
                                    <th class="py-1 pr-2 font-normal">Your value</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($testEnv as $i => $var)
                                    <tr wire:key="test-env-{{ $i }}-{{ $var['name'] }}">
                                        <td class="py-1 pr-2">
                                            @if($var['project'] === null)
                                                <input type="text" wire:model.live.debounce.600ms="testEnv.{{ $i }}.name" placeholder="NAME" class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1 font-mono">
                                            @else
                                                <span class="font-mono">{{ $var['name'] }}</span>
                                            @endif
                                        </td>
                                        <td class="py-1 pr-2 font-mono text-gray-500 dark:text-gray-400 break-all">
                                            {{ $var['project'] === null ? '—' : ($var['project'] === '' ? '(empty)' : $var['project']) }}
                                            @if($var['force'])<span class="text-yellow-700 dark:text-yellow-400">(forced)</span>@endif
                                        </td>
                                        <td class="py-1 pr-2">
                                            @if($var['project'] !== null)
                                                <input type="checkbox" wire:model.live="testEnv.{{ $i }}.override" @disabled($var['force'])>
                                            @endif
                                        </td>
                                        <td class="py-1 pr-2">
                                            <input type="text" wire:model.live.debounce.600ms="testEnv.{{ $i }}.value" @disabled(!$var['override'] || $var['force']) class="w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1 font-mono disabled:opacity-50">
                                        </td>
                                        <td class="py-1">
                                            @if($var['project'] === null || $var['override'])
                                                <flux:button size="xs" variant="danger" icon="trash" wire:click="removeTestEnvVar({{ $i }})" title="{{ $var['project'] === null ? 'Remove' : 'Use project value' }}"></flux:button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="plus" wire:click="addTestEnvVar">Add variable</flux:button>
                        <flux:button size="sm" variant="filled" color="blue" icon="circle-stack" wire:click="useLdevDatabaseSettings">Use ldev database settings</flux:button>
                    </div>
                    @if($testDb)
                        <p class="{{ $testDb['exists'] ? 'text-green-600 dark:text-green-400' : 'text-yellow-700 dark:text-yellow-400' }}">
                            Test database {{ $testDb['database'] }} ({{ $testDb['connection'] }}) {{ $testDb['exists'] ? 'exists' : 'does not exist yet' }}.
                            @if(!$testDb['exists'])
                                <flux:button size="xs" icon="plus" wire:click="createTestDatabase" class="ml-2">Create database</flux:button>
                            @endif
                        </p>
                    @endif
                    @if($testEnvMessage)
                        <p class="{{ $testEnvMessage['ok'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ $testEnvMessage['text'] }}</p>
                    @endif
                </div>
            </details>

            <p class="text-xs text-gray-400 dark:text-gray-500">Tick a file (all its tests) or single tests, then click <em>Run selected</em>. Click a file name to see its tests and results.</p>
            <div class="rounded border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach($testSuite['files'] as $path => $names)
                    @php
                        $fileResults = $resultsByFile->get($path, collect());
                        $fileFailed = $fileResults->whereIn('status', ['failed', 'error'])->count();
                        $fileSkipped = $fileResults->where('status', 'skipped')->count();
                        $filePassed = $fileResults->where('status', 'passed')->count();
                        $filterStatuses = ['failed' => ['failed', 'error'], 'skipped' => ['skipped']][$testFilter] ?? null;
                        $filterActive = $filterStatuses && $testRun;
                        $crash = $fileResults->firstWhere('name', '(file did not run)');
                        $byName = $fileResults->groupBy('name');
                        $rows = collect($names)->map(fn ($name) => ['name' => $name, 'results' => $byName->get($name, collect())]);
                        foreach ($byName as $resultName => $items) {
                            if (!in_array($resultName, $names, true) && $resultName !== '(file did not run)') {
                                $rows->push(['name' => $resultName, 'results' => $items]);
                            }
                        }
                    @endphp
                    @continue($filterActive && !$fileResults->whereIn('status', $filterStatuses)->count())
                    <details class="px-3 py-2" wire:ignore.self wire:key="test-file-{{ md5($path) }}">
                        <summary class="cursor-pointer">
                            <span class="inline-flex flex-wrap items-center gap-2">
                                <input type="checkbox" value="{{ $path }}" wire:model="selectedTests" onclick="event.stopPropagation()" title="Include every test in this file in Run selected">
                                <span class="font-mono text-xs">{{ $path }}</span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ count($names) }} test(s)</span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ ['pest' => 'Pest', 'phpunit' => 'PHPUnit', 'vitest' => 'Vitest', 'jest' => 'Jest'][$testSuite['styles'][$path] ?? 'phpunit'] ?? 'PHPUnit' }}@if(count(collect($testSuite['suites'] ?? [])->unique()) > 1) · {{ $testSuite['suites'][$path] ?? '' }}@endif</span>
                                @if($fileResults->count())
                                    @if($filePassed)<span class="text-xs text-green-600 dark:text-green-400">{{ $filePassed }} passed</span>@endif
                                    @if($fileFailed)<span class="text-xs text-red-600 dark:text-red-400">{{ $fileFailed }} failed</span>@endif
                                    @if($fileSkipped)<span class="text-xs text-yellow-700 dark:text-yellow-400">{{ $fileSkipped }} skipped</span>@endif
                                @endif
                            </span>
                        </summary>
                        @if($crash)
                            <pre class="mt-2 rounded bg-red-50 dark:bg-red-950 p-2 text-xs text-red-700 dark:text-red-300 whitespace-pre-wrap">{{ $crash['message'] }}</pre>
                        @endif
                        <ul class="mt-2 space-y-1 pl-6">
                            @foreach($rows as $row)
                                @php $result = $row['results']->first(); @endphp
                                @continue($filterActive && !in_array($result['status'] ?? null, $filterStatuses, true))
                                <li>
                                    <label class="flex items-start gap-2">
                                        <input type="checkbox" class="mt-1" value="{{ $path }}::{{ $row['name'] }}" wire:model="selectedTests" title="Include this test in Run selected">
                                        <span class="w-14 shrink-0 text-xs {{ $statusClass[$result['status'] ?? ''] ?? 'text-gray-400 dark:text-gray-500' }}">{{ $result['status'] ?? 'not run' }}</span>
                                        <span class="flex-1 text-gray-700 dark:text-gray-300">{{ $row['name'] }}</span>
                                        @if($result)<span class="text-xs text-gray-400 dark:text-gray-500">{{ $result['time'] }}s</span>@endif
                                    </label>
                                    @foreach($row['results'] as $item)
                                        @if(in_array($item['status'], ['failed', 'error'], true) && $item['message'] !== '')
                                            <pre class="mt-1 ml-10 max-h-64 overflow-auto rounded bg-gray-50 dark:bg-gray-900 p-2 text-xs text-red-700 dark:text-red-300 whitespace-pre-wrap">{{ $item['message'] }}</pre>
                                        @endif
                                        @if($item['status'] === 'skipped' && $item['message'] !== '')
                                            <p class="mt-1 ml-10 text-xs text-yellow-700 dark:text-yellow-400">Skipped by the test itself: {{ $item['message'] }}</p>
                                        @endif
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
        @endif
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex flex-wrap justify-between items-center gap-2">
            <h2 class="font-medium">Dumps</h2>
            <div class="flex gap-2">
                @if($dumps)
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="clearDumps">Clear</flux:button>
                @endif
                <flux:button size="sm" variant="filled" color="blue" icon="{{ $dumpsEnabled ? 'stop' : 'bug-ant' }}" wire:click="toggleDumps" wire:loading.attr="disabled" wire:target="toggleDumps">
                    {{ $dumpsEnabled ? 'Stop sending dumps here' : 'Send dump() and dd() here' }}
                </flux:button>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Shows this project's <span class="font-mono">dump()</span> and <span class="font-mono">dd()</span> output here instead of in the page, so JSON responses, Livewire requests, queued jobs and artisan commands can be debugged too. Switching on adds <span class="font-mono">VAR_DUMPER_FORMAT</span> and <span class="font-mono">VAR_DUMPER_SERVER</span> to the project's <span class="font-mono">.env</span>; switching off removes them. <span class="font-mono">dd()</span> still stops the request, so that page stays blank.
        </p>

        @if($dumpsEnabled && ! $dumpServerRunning)
            <p class="text-xs text-yellow-700 dark:text-yellow-400">The dump collector isn't running, so dumps appear in the page as usual. Run <span class="font-mono">sudo ./deploy-app.sh</span> once to install it.</p>
        @endif

        @if($dumps)
            <div class="space-y-2">
                @foreach($dumps as $dump)
                    <div class="rounded border border-gray-200 dark:border-gray-700" wire:key="dump-{{ $dump['time'] }}-{{ $loop->index }}">
                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <span>{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $dump['time'])->setTimezone(config('ldev.timezone'))->format('H:i:s') }}</span>
                            @if($dump['label'])
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $dump['label'] }}</span>
                            @endif
                            <span class="font-mono">{{ $dump['file'] }}{{ $dump['line'] ? ':' . $dump['line'] : '' }}</span>
                            @if($dump['request'])
                                <span class="font-mono truncate">{{ $dump['request'] }}</span>
                            @elseif($dump['command'])
                                <span class="font-mono truncate">{{ $dump['command'] }}</span>
                            @endif
                        </div>
                        <pre class="px-3 py-2 text-xs font-mono overflow-x-auto max-h-80 text-gray-800 dark:text-gray-200">{{ $dump['text'] }}</pre>
                    </div>
                @endforeach
            </div>
        @elseif($dumpsEnabled)
            <p class="text-sm text-gray-400 dark:text-gray-500">Waiting for dumps. Call <span class="font-mono">dump()</span> anywhere in the project and it appears here.</p>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="font-medium">Background processes</h2>
            <div class="flex gap-2">

                <flux:button size="sm" variant="danger" icon="trash" wire:click="clearAllBackgroundLogs" wire:confirm="Clear all three logs (queue/reverb/scheduler)? This can't be undone.">Clear all</flux:button>
            </div>
        </div>

        @php
            $procs = [
                ['key' => 'queue', 'label' => 'Queue worker', 'enabled' => $site->queue_worker_enabled, 'status' => $queueStatus, 'lines' => $queueLogLines],
                ['key' => 'reverb', 'label' => 'Reverb', 'enabled' => $site->reverb_enabled, 'status' => $reverbStatus, 'lines' => $reverbLogLines],
                ['key' => 'scheduler', 'label' => 'Scheduler', 'enabled' => $site->scheduler_enabled, 'status' => $schedulerStatus, 'lines' => $schedulerLogLines],
            ];
@endphp

        @foreach($procs as $proc)
            <div class="border-t border-gray-100 dark:border-gray-700 pt-3 first:border-0 first:pt-0">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium flex items-center gap-1.5">
                        {{ $proc['label'] }}

                        @if($proc['enabled'] && !empty($proc['lines']))
                            <flux:button size="xs" variant="danger" icon="trash" wire:click="clearBackgroundLog('{{ $proc['key'] }}')" wire:confirm="Clear this log? This can't be undone.">Clear</flux:button>
                        @endif
                    </span>

                    @if(!$proc['enabled'])
                        <span class="text-xs text-gray-400 dark:text-gray-500">Not enabled</span>
                    @elseif(!($proc['status']['reachable'] ?? false))
                        <span class="text-xs text-yellow-700 dark:text-yellow-400" title="Needs a setup-environment.sh re-run to enable Supervisor's loopback-only status API">
                            Status unavailable — needs a setup-environment.sh re-run
                        </span>
                    @elseif($proc['status']['running'])
                        <span class="inline-flex items-center gap-1.5 text-xs">
                            <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                            {{ $proc['status']['status'] }} <span class="text-gray-400 dark:text-gray-500">{{ $proc['status']['detail'] }}</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400">
                            <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                            Enabled, but Supervisor has no such process running
                        </span>
                    @endif
                </div>

                @if($proc['enabled'])
                    @if($proc['key'] === 'queue')
                        <div class="flex items-center justify-between mt-1">
                            @if($queueJobCounts)
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Pending jobs: {{ $queueJobCounts['pending'] ?? '—' }} · Failed jobs: {{ $queueJobCounts['failed'] ?? '—' }}
                                </p>
                            @else
                                <span></span>
                            @endif
                            <flux:button size="xs" variant="filled" color="blue" icon="list-bullet" wire:click="openQueueModal">View jobs</flux:button>
                        </div>
                    @endif

                    @if($proc['key'] === 'scheduler')
                        <div class="flex items-center justify-end mt-1">
                            <flux:button size="xs" variant="filled" color="blue" icon="calendar" wire:click="openSchedulerModal">View schedule</flux:button>
                        </div>
                    @endif

                    @if(empty($proc['lines']))
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">No output yet.</p>
                    @else
                        <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-2 mt-1 overflow-x-auto max-h-40 overflow-y-auto">{{ implode("\n", $proc['lines']) }}</pre>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="font-medium">Application log</h2>
            <div class="flex gap-2">
                @if($selectedProjectLog)
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="clearProjectLog" wire:confirm="Clear this log file? This can't be undone.">Clear</flux:button>
                @endif
            </div>
        </div>

        @if(empty($projectLogFiles))
            <p class="text-sm text-gray-400 dark:text-gray-500">No log files yet under storage/logs.</p>
        @else

            @if(count($projectLogFiles) > 1 || $projectLogTruncated)
                <select wire:change="selectProjectLog($event.target.value)" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-xs">
                    @foreach($projectLogFiles as $path => $label)
                        <option value="{{ $path }}" @selected($selectedProjectLog === $path)>{{ $label }}</option>
                    @endforeach
                </select>
                @if($projectLogTruncated)
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        +{{ $projectLogTruncated }} more not shown (newest {{ count($projectLogFiles) }} of {{ count($projectLogFiles) + $projectLogTruncated }} only).
                    </p>
                @endif
            @endif

            @if(empty($projectLogLines))
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-2">This log file is empty.</p>
            @else
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 mt-2 overflow-x-auto max-h-80 overflow-y-auto">{{ implode("\n", $projectLogLines) }}</pre>
            @endif
        @endif
    </div>

    <flux:modal wire:model="showGitDiffModal" class="max-w-5xl!">
        <div class="space-y-4">

            <div class="flex items-center justify-between pr-10">
                <div>
                    <h2 class="text-lg font-medium">Uncommitted changes — {{ $site->name }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ count($gitDiffFiles) }} file(s) changed</p>
                </div>
                @if(count($gitDiffFiles) > 1)
                    <div class="flex gap-2">
                        <flux:button size="xs" variant="ghost" icon="check" wire:click="$set('selectedFilesForCommit', {{ json_encode(collect($gitDiffFiles)->pluck('file')->all()) }})">Select all</flux:button>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="$set('selectedFilesForCommit', [])">Select none</flux:button>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

                <div class="border border-gray-100 dark:border-gray-700 rounded divide-y divide-gray-100 dark:divide-gray-700 max-h-96 overflow-y-auto">
                    @foreach($gitDiffFiles as $f)
                        <div class="flex items-center gap-2 px-2 py-1.5 text-xs {{ $selectedDiffFile === $f['file'] ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <input type="checkbox" wire:model="selectedFilesForCommit" value="{{ $f['file'] }}" class="shrink-0" title="Include in the next commit">
                            <button type="button" wire:click="selectDiffFile('{{ $f['file'] }}')" class="flex-1 text-left truncate hover:underline" title="{{ $f['file'] }}">
                                <span class="text-gray-400 dark:text-gray-500">{{ $f['label'] }}:</span> {{ $f['file'] }}
                            </button>

                            <flux:button size="xs" variant="ghost" icon="eye-slash" wire:click="ignoreDiffFile('{{ $f['file'] }}')"
                                title="{{ $f['label'] === 'Untracked' ? 'Add to .gitignore' : 'Ignore local changes to this file (git skip-worktree)' }}"></flux:button>
                        </div>
                    @endforeach
                </div>

                <div class="md:col-span-2">
                    @if(!empty($gitDiffLines))
                        <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-96 overflow-y-auto font-mono">@foreach($gitDiffLines as $line)<span class="{{ $line['class'] }}">{{ $line['text'] }}</span>
@endforeach</pre>
                    @else
                        <p class="text-sm text-gray-400 dark:text-gray-500">No diff to show for this file.</p>
                    @endif
                </div>
            </div>

            @if($gitIgnoreNote)
                <p class="text-xs text-green-600 dark:text-green-400">{{ $gitIgnoreNote }}</p>
            @endif

            @if(!empty($skipWorktreeFiles))
                <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                    <h3 class="text-sm font-medium mb-2">Ignored files</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                        These already-tracked files have local changes git is deliberately not
                        reporting (skip-worktree) — they won't show up above, get committed, or
                        count toward this repo being "out of step" on the Git panel.
                    </p>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($skipWorktreeFiles as $ignored)
                            <div class="flex items-center justify-between py-1 text-xs">
                                <span class="font-mono truncate">{{ $ignored }}</span>
                                <flux:button size="xs" variant="ghost" icon="eye" wire:click="unignoreFile('{{ $ignored }}')">Un-ignore</flux:button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="border-t border-gray-100 dark:border-gray-700 pt-3 space-y-2">
                <label class="block text-sm font-medium">Commit message</label>
                <textarea wire:model="commitMessage" rows="2" placeholder="What changed?"
                    class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm"></textarea>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="primary" icon="check" wire:click="commitAndPush" wire:loading.attr="disabled" wire:target="commitAndPush">
                        {{ ($gitStatus['hasUpstream'] ?? false) ? 'Commit ' . count($selectedFilesForCommit) . ' file(s) and push' : 'Commit ' . count($selectedFilesForCommit) . ' file(s)' }}
                    </flux:button>
                    <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="commitAndPush">Working…</span>
                </div>
                @if($commitNote)
                    <p class="text-xs text-green-600 dark:text-green-400">{{ $commitNote }}</p>
                @endif
                @if($commitError)
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $commitError }}</p>
                @endif
            </div>

            <div class="border-t border-gray-100 dark:border-gray-700 pt-3 space-y-2">
                <label class="block text-sm font-medium">Run a git command</label>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-mono text-gray-400 dark:text-gray-500">git</span>
                    <input type="text" wire:model="manualGitCommand" wire:keydown.enter="runManualGitCommand" placeholder="log --oneline -10"
                        class="flex-1 rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm font-mono" />
                    <flux:button size="sm" variant="filled" color="blue" icon="play" wire:click="runManualGitCommand" wire:loading.attr="disabled" wire:target="runManualGitCommand">
                        Run
                    </flux:button>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    Simple space-separated arguments only — no quoting, so nothing needing an embedded space (like a commit message).
                </p>
                @if($manualGitOutput !== null)
                    <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-60 overflow-y-auto font-mono whitespace-pre-wrap">{{ $manualGitOutput }}</pre>
                @endif
                @if($manualGitError)
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $manualGitError }}</p>
                @endif
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showQueueModal" class="max-w-6xl!">
        <div class="space-y-4">

            <div class="flex items-center justify-between pr-10">
                <div>
                    <h2 class="text-lg font-medium">Queue jobs — {{ $site->name }}</h2>
                    @if($queueJobsData)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $queueJobsData['pendingCount'] ?? 0 }} pending · {{ $queueJobsData['failedCount'] ?? 0 }} failed
                            (showing up to 50 of each)
                        </p>
                    @endif
                </div>
                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshQueueJobs">Refresh</flux:button>
            </div>

            @if($queueJobsError)
                <p class="text-sm text-red-600 dark:text-red-400">{{ $queueJobsError }}</p>
            @elseif($queueJobsData)
                <div>
                    <h3 class="text-sm font-medium mb-2">Pending</h3>
                    @if(empty($queueJobsData['pending']))
                        <p class="text-sm text-gray-400 dark:text-gray-500">No pending jobs.</p>
                    @else
                        <div class="border border-gray-100 dark:border-gray-700 rounded overflow-x-auto max-h-60 overflow-y-auto">

                            <table class="w-full text-xs table-fixed">
                                <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0">
                                    <tr>
                                        <th class="px-2 py-1.5 text-left w-8">ID</th>
                                        <th class="px-2 py-1.5 text-left">Job</th>
                                        <th class="px-2 py-1.5 text-left w-16">Queue</th>
                                        <th class="px-2 py-1.5 text-left w-16">Attempts</th>
                                        <th class="px-2 py-1.5 text-left w-40">Available at</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach($queueJobsData['pending'] as $job)
                                        <tr>
                                            <td class="px-2 py-1.5">{{ $job['id'] }}</td>
                                            <td class="px-2 py-1.5 font-mono truncate" title="{{ $job['job'] }}">{{ $job['job'] ?? '—' }}</td>
                                            <td class="px-2 py-1.5 truncate">{{ $job['queue'] }}</td>
                                            <td class="px-2 py-1.5">{{ $job['attempts'] }}</td>
                                            <td class="px-2 py-1.5 whitespace-nowrap">{{ $job['available_at'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-medium">Failed</h3>
                        @if(!empty($queueJobsData['failed']))
                            <div class="flex gap-2">
                                <flux:button size="xs" variant="filled" color="blue" icon="arrow-path" wire:click="retryAllFailedJobs" wire:confirm="Retry every failed job on this site?">Retry all</flux:button>
                                <flux:button size="xs" variant="danger" icon="trash" wire:click="flushFailedJobs" wire:confirm="Delete every failed job on this site? This can't be undone.">Flush all</flux:button>
                            </div>
                        @endif
                    </div>

                    @if(empty($queueJobsData['failed']))
                        <p class="text-sm text-gray-400 dark:text-gray-500">No failed jobs.</p>
                    @else
                        <div class="border border-gray-100 dark:border-gray-700 rounded overflow-x-auto max-h-72 overflow-y-auto">

                            <table class="w-full text-xs table-fixed">
                                <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0">
                                    <tr>
                                        <th class="px-2 py-1.5 text-left w-8">ID</th>
                                        <th class="px-2 py-1.5 text-left">Job</th>
                                        <th class="px-2 py-1.5 text-left w-16">Queue</th>
                                        <th class="px-2 py-1.5 text-left w-32">Failed at</th>
                                        <th class="px-2 py-1.5 text-left">Exception</th>
                                        <th class="px-2 py-1.5 text-left w-24">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach($queueJobsData['failed'] as $job)
                                        <tr>
                                            <td class="px-2 py-1.5">{{ $job['id'] }}</td>
                                            <td class="px-2 py-1.5 font-mono truncate" title="{{ $job['job'] }}">{{ $job['job'] ?? '—' }}</td>
                                            <td class="px-2 py-1.5 truncate">{{ $job['queue'] }}</td>
                                            <td class="px-2 py-1.5 whitespace-nowrap">{{ $job['failed_at'] }}</td>
                                            <td class="px-2 py-1.5 truncate" title="{{ $job['exception'] }}">{{ $job['exception'] }}</td>
                                            <td class="px-2 py-1.5">
                                                <div class="flex gap-1">
                                                    <flux:button size="xs" variant="filled" color="blue" icon="eye" wire:click="viewFailedJobException('{{ $job['uuid'] }}')" title="View the complete error"></flux:button>
                                                    <flux:button size="xs" variant="filled" color="blue" icon="arrow-path" wire:click="retryFailedJob('{{ $job['uuid'] }}')" title="Retry"></flux:button>
                                                    <flux:button size="xs" variant="danger" icon="trash" wire:click="deleteFailedJob('{{ $job['uuid'] }}')" wire:confirm="Delete this failed job?" title="Delete"></flux:button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if($viewingExceptionUuid)
                        <div class="border-t border-gray-100 dark:border-gray-700 pt-3 mt-3">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-sm font-medium">Full error</h3>
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeFailedJobException">Close</flux:button>
                            </div>
                            <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-72 overflow-y-auto font-mono whitespace-pre-wrap">{{ $viewingExceptionText }}</pre>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </flux:modal>

    <flux:modal wire:model="showSchedulerModal" class="max-w-6xl!">
        <div class="space-y-4">

            <div class="flex items-center justify-between pr-10">
                <h2 class="text-lg font-medium">Scheduler — {{ $site->name }}</h2>
                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshSchedulerModal">Refresh</flux:button>
            </div>

            <div>
                <h3 class="text-sm font-medium mb-2">Due to run</h3>
                @if($scheduleListError)
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $scheduleListError }}</p>
                @elseif(!empty($scheduleListRows))

                    <div class="border border-gray-100 dark:border-gray-700 rounded overflow-x-auto max-h-96 overflow-y-auto">
                        <table class="w-full text-xs table-fixed">
                            <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0">
                                <tr>
                                    <th class="px-2 py-1.5 text-left w-24">Cron</th>
                                    <th class="px-2 py-1.5 text-left w-96">Command</th>
                                    <th class="px-2 py-1.5 text-left">Description</th>
                                    <th class="px-2 py-1.5 text-left w-36">Next due</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($scheduleListRows as $row)
                                    <tr>
                                        <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ $row['expression'] ?? '' }}</td>
                                        <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ $row['command'] ?? '' }}</td>
                                        <td class="px-2 py-1.5 wrap-break-word">{{ $row['description'] ?? '—' }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap" title="{{ $row['next_due_date'] ?? '' }}">{{ $row['next_due_date_human'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($scheduleListOutput)

                    <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-60 overflow-y-auto font-mono whitespace-pre-wrap">{{ $scheduleListOutput }}</pre>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500">No scheduled tasks have been defined.</p>
                @endif
            </div>

            <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                <h3 class="text-sm font-medium mb-2">Recent runs</h3>
                @if(!$site->scheduler_enabled)
                    <p class="text-sm text-gray-400 dark:text-gray-500">
                        The Scheduler flag is off, so nothing is actually calling these tasks right now —
                        turn it on (Flags panel below) to start building run history here.
                    </p>
                @elseif(empty($schedulerRunHistory))
                    <p class="text-sm text-gray-400 dark:text-gray-500">No output yet — nothing has run since the scheduler last (re)started.</p>
                @else
                    <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto max-h-72 overflow-y-auto font-mono">{{ implode("\n", $schedulerRunHistory) }}</pre>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
