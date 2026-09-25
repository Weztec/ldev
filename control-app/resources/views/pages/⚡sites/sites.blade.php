<div>
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-semibold">Sites</h1>
        <div class="flex gap-2 items-center">
            <flux:button
                variant="filled"
                color="blue"
                icon="magnifying-glass"
                wire:click="scanForUntrackedProjects"
                wire:loading.attr="disabled"
                wire:target="scanForUntrackedProjects"
            >
                Scan for untracked projects
            </flux:button>
            <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="scanForUntrackedProjects">
                Scanning…
            </span>
            <a href="{{ route('new-project') }}" wire:navigate>
                <flux:button variant="primary" icon="plus">New Project</flux:button>
            </a>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
        <table class="w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                @php
                    $th = 'px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider';
@endphp
                <tr>
                    <th class="{{ $th }} w-1/6">Name</th>
                    <th class="{{ $th }} w-1/5">URL</th>
                    <th class="{{ $th }} w-1/6">Git</th>
                    <th class="{{ $th }} w-24">Status</th>
                    <th class="{{ $th }}">Actions</th>
                </tr>
            </thead>
            <tbody>
                @php $health = \App\Services\SiteHealth::forSites($sites); @endphp
                @foreach($sites as $site)
                @php $git = $site->gitStatus(); $siteHealth = $health[$site->id] ?? []; @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-600">
                    <td class="px-4 py-2 truncate">
                        <a href="{{ route('site-detail', $site) }}" wire:navigate class="font-medium hover:underline">{{ $site->name }}</a>
                        <div class="flex flex-wrap gap-1 mt-0.5 text-xs">
                            @if($siteHealth['vulnerable'] ?? 0)
                                <span class="rounded px-1.5 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300" title="Packages with known security advisories (Composer and npm)">{{ $siteHealth['vulnerable'] }} vulnerable</span>
                            @endif
                            @if($siteHealth['failing'] ?? 0)
                                <span class="rounded px-1.5 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300" title="Tests that failed in the last run">{{ $siteHealth['failing'] }} {{ \Illuminate\Support\Str::plural('test', $siteHealth['failing']) }} failing</span>
                            @elseif($siteHealth['testsPassed'] ?? false)
                                <span class="rounded px-1.5 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300" title="Every test passed in the last run">tests pass</span>
                            @elseif($siteHealth['testsRunning'] ?? false)
                                <span class="rounded px-1.5 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">tests running</span>
                            @endif
                            @if(($siteHealth['sandbox'] ?? null) === 'pass')
                                <span class="rounded px-1.5 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300" title="A tested dependency update is waiting to be applied">update ready</span>
                            @elseif(($siteHealth['sandbox'] ?? null) === 'issues')
                                <span class="rounded px-1.5 bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-300" title="The last sandbox test of a dependency update found problems">update has problems</span>
                            @endif
                            @if($siteHealth['unmetRequirements'] ?? 0)
                                <span class="rounded px-1.5 bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-300" title="Installed service versions don't match this project's ldev.json requires">requirements not met</span>
                            @endif
                            @if(($siteHealth['outdated'] ?? 0) && !($siteHealth['vulnerable'] ?? 0))
                                <span class="rounded px-1.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300" title="Outdated Composer and npm packages">{{ $siteHealth['outdated'] }} outdated</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-2 truncate"><a href="https://{{ $site->domain }}" target="_blank" class="text-blue-500">{{ $site->domain }}</a></td>
                    <td class="px-4 py-2 truncate">
                        @if(!$git['hasRepo'])
                            <a href="{{ route('site-detail', $site) }}" wire:navigate class="text-sm text-gray-400 dark:text-gray-500 hover:underline" title="Not a git repository yet — click to initialize one">No repo</a>
                        @else
                            <a href="{{ route('site-detail', $site) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm" title="{{ $git['outOfSync'] ? 'Out of step with origin' : 'Up to date' }}">
                                <span class="inline-block w-2 h-2 rounded-full shrink-0 {{ $git['outOfSync'] ? 'bg-yellow-500' : 'bg-green-500' }}"></span>
                                <span class="truncate">{{ $git['branch'] ?? '(detached)' }}</span>
                            </a>

                            <div class="flex flex-wrap gap-x-2 text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                @if($git['dirty'])
                                    <span title="Uncommitted local changes">● dirty</span>
                                @endif
                                @if($git['ahead'] > 0)
                                    <span title="{{ $git['ahead'] }} commit(s) not yet pushed to origin">↑{{ $git['ahead'] }}</span>
                                @endif
                                @if($git['behind'] > 0)
                                    <span title="{{ $git['behind'] }} commit(s) on origin not yet pulled here">↓{{ $git['behind'] }}</span>
                                @endif
                                @if(!$git['hasUpstream'])
                                    <span title="No remote tracking branch configured">no upstream</span>
                                @elseif(!$git['outOfSync'])
                                    <span class="text-green-600 dark:text-green-400">synced</span>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        @php $reachable = $site->isReachable();@endphp
                        <span class="inline-flex items-center gap-1.5 text-sm" title="{{ $reachable ? 'Responded to a real HTTPS request just now' : 'Did not respond to a real HTTPS request just now' }}">
                            <span class="inline-block w-2 h-2 rounded-full {{ $reachable ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            {{ $reachable ? 'Up' : 'Down' }}
                        </span>
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex flex-wrap gap-1">
                            <a href="{{ route('site-detail', $site) }}" wire:navigate><flux:button size="sm" icon="eye">Details</flux:button></a>
                            <a href="https://{{ $site->domain }}" target="_blank"><flux:button size="sm" icon="arrow-top-right-on-square">Open</flux:button></a>
                            @if($git['hasRepo'] && $git['hasUpstream'] && $git['ahead'] > 0)
                                <flux:button size="sm" variant="filled" color="blue" icon="cloud-arrow-up" wire:click="pushSite({{ $site->id }})"
                                    wire:loading.attr="disabled" wire:target="pushSite({{ $site->id }})">Push ({{ $git['ahead'] }})</flux:button>
                            @endif
                            <flux:button size="sm" variant="filled" color="green" icon="document-duplicate" wire:click="startClone({{ $site->id }})">Clone</flux:button>
                            <flux:button size="sm" variant="danger" icon="minus-circle" wire:click="startDelete({{ $site->id }}, 'list')">Delete from list</flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="startDelete({{ $site->id }}, 'disk')">Delete from disk</flux:button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($cloningSiteId)
        @php $cloneSource = $sites->firstWhere('id', $cloningSiteId);@endphp
        @if($cloneSource)
            <div class="mt-4 max-w-md p-4 rounded border border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 space-y-3">
                <h2 class="font-medium">Clone "{{ $cloneSource->name }}"</h2>
                <div>
                    <label class="block text-sm font-medium mb-1">New project name</label>
                    <input type="text" wire:model="cloneName" placeholder="e.g. {{ $cloneSource->name }}-copy"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="cloneCopyData">
                    Give the clone its own copy of the database
                </label>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @if($cloneCopyData)
                        A separate database is created for the clone, with the source's current data copied into it — the two stay independent from then on.
                    @else
                        The clone will share the exact same database as "{{ $cloneSource->name }}" — both read and write the same live data, not a copy.
                    @endif
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="cloneCreateRepo">
                    Also create a new repository for the clone and push it there
                </label>
                @if($cloneCreateRepo)
                    <div class="pl-6 space-y-2 border-l-2 border-yellow-200 dark:border-yellow-800">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Using token</label>
                            <select wire:model="cloneRepoTokenId" class="w-full rounded px-3 py-2 text-sm border-gray-300 dark:bg-gray-700 dark:border-gray-600">
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
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Visibility</label>
                            <select wire:model="cloneRepoVisibility" class="w-full rounded px-3 py-2 text-sm border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                <option value="private">Private</option>
                                <option value="public">Public</option>
                            </select>
                        </div>
                    </div>
                @endif

                <div class="flex gap-2">
                    <flux:button variant="danger" icon="document-duplicate" wire:click="confirmClone" wire:loading.attr="disabled" wire:target="confirmClone">
                        Clone
                    </flux:button>
                    <flux:button variant="ghost" icon="x-mark" wire:click="cancelClone" wire:loading.attr="disabled" wire:target="confirmClone">
                        Cancel
                    </flux:button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400" wire:loading wire:target="confirmClone">
                    Copying files{{ $cloneCopyData ? ' and database' : '' }}{{ $cloneCreateRepo ? ', creating repository and pushing' : '' }} — this can take a moment…
                </p>
                @if($cloneError)
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $cloneError }}</p>
                @endif
            </div>
        @endif
    @endif

    @if($cloneRepoWarning)
        <div class="mt-4 max-w-lg p-3 rounded border border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 text-sm flex items-start justify-between gap-2">
            <p class="text-yellow-800 dark:text-yellow-300">{{ $cloneRepoWarning }}</p>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('cloneRepoWarning', null)"></flux:button>
        </div>
    @endif

    @if($pushNote)
        <div class="mt-4 max-w-lg p-3 rounded border border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20 text-sm flex items-start justify-between gap-2">
            <p class="text-green-800 dark:text-green-300">{{ $pushNote }}</p>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('pushNote', null)"></flux:button>
        </div>
    @endif
    @if($pushError)
        <div class="mt-4 max-w-lg p-3 rounded border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-sm flex items-start justify-between gap-2">
            <p class="text-red-800 dark:text-red-300">{{ $pushError }}</p>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('pushError', null)"></flux:button>
        </div>
    @endif

    @if($scanned)
        <div class="mt-4 max-w-2xl p-4 rounded border border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 space-y-3">
            @if(empty($foundCandidates))
                <p class="text-sm">No untracked project directories found in ~/Sites.</p>
                <flux:button variant="ghost" icon="x-mark" wire:click="cancelScan">Close</flux:button>
            @else
                <h2 class="font-medium">
                    Found {{ count($foundCandidates) }} untracked {{ \Illuminate\Support\Str::plural('project', count($foundCandidates)) }}
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Pick which ones to add and review/change the config each one will get — none of these are linked yet, so unchecking one just leaves it as-is for now.
                </p>
                <div class="divide-y divide-blue-100 dark:divide-blue-800">
                    @foreach($foundCandidates as $name)
                        <div class="py-2">
                            <label class="flex items-center gap-2 text-sm font-medium">
                                <input type="checkbox" wire:model="selectedCandidates" value="{{ $name }}">
                                {{ $name }}
                                @if($candidateConfigs[$name]['from_backup'] ?? false)
                                    <span class="text-xs font-normal text-blue-600 dark:text-blue-400">— restoring its previous config</span>
                                @endif
                            </label>

                            @if(in_array($name, $selectedCandidates, true))
                                <div class="mt-2 ml-6 space-y-2">
                                    <div class="grid grid-cols-2 gap-2 max-w-xs">
                                        <div>
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">PHP</label>
                                            <select wire:model="candidateConfigs.{{ $name }}.php_version" class="w-full rounded px-2 py-1 text-sm border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                                @foreach(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'] as $version)
                                                    <option value="{{ $version }}">{{ $version }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">Node.js</label>
                                            <select wire:model="candidateConfigs.{{ $name }}.node_version" class="w-full rounded px-2 py-1 text-sm border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                                @foreach(\App\Services\NodeVersionManager::KNOWN_VERSIONS as $version)
                                                    <option value="{{ $version }}">{{ $version }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap">
                                            <input type="checkbox" wire:model="candidateConfigs.{{ $name }}.xdebug_enabled">
                                            Xdebug
                                        </label>
                                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap">
                                            <input type="checkbox" wire:model="candidateConfigs.{{ $name }}.queue_worker_enabled">
                                            Queue worker
                                        </label>
                                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap">
                                            <input type="checkbox" wire:model="candidateConfigs.{{ $name }}.reverb_enabled">
                                            Reverb (WebSockets)
                                        </label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-2 pt-1">
                    <flux:button variant="primary" icon="plus" wire:click="addSelectedCandidates"
                        wire:loading.attr="disabled" wire:target="addSelectedCandidates"
                        :disabled="empty($selectedCandidates)">
                        Add {{ count($selectedCandidates) }} selected
                    </flux:button>
                    <flux:button variant="ghost" icon="x-mark" wire:click="cancelScan"
                        wire:loading.attr="disabled" wire:target="addSelectedCandidates">
                        Cancel
                    </flux:button>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="addSelectedCandidates">
                    Linking and setting up the selected project(s) — this can take a moment…
                </p>
            @endif
        </div>
    @endif

    @if($pendingDeleteSiteId)
        @php $deleteSite = $sites->firstWhere('id', $pendingDeleteSiteId);@endphp
        @if($deleteSite)
            <div class="mt-4 max-w-lg p-4 rounded border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 space-y-3">
                @if($pendingDeleteMode === 'disk')
                    <h2 class="font-medium">Permanently delete "{{ $deleteSite->name }}"?</h2>
                    <p class="text-sm">This deletes the project directory itself ({{ $deleteSite->projectRoot() }}) — all its files, git history, and database — and cannot be undone.</p>
                @else
                    <h2 class="font-medium">Remove "{{ $deleteSite->name }}" from the dashboard?</h2>
                    <p class="text-sm">This removes its nginx/Supervisor config and database record — the project files stay on disk, and "Scan for untracked projects" above will pick it back up later if you want it back.</p>
                @endif

                @if($pendingDeleteGitStatus['hasRepo'] ?? false)
                    @if($pendingDeleteGitStatus['dirty'] || $pendingDeleteGitStatus['ahead'] > 0)
                        <div class="text-sm bg-white dark:bg-gray-800 rounded p-3 space-y-1.5">
                            <p class="font-medium text-red-700 dark:text-red-400">⚠ This repository is not fully saved to its remote:</p>
                            @if($pendingDeleteGitStatus['dirty'])
                                <p>Uncommitted local changes{{ $pendingDeleteMode === 'disk' ? ' — these will be permanently lost, pushing can\'t save them' : ' (untouched by this action either way)' }}.</p>
                            @endif
                            @if($pendingDeleteGitStatus['ahead'] > 0)
                                <p>{{ $pendingDeleteGitStatus['ahead'] }} commit(s) not yet pushed to origin.</p>
                                <flux:button size="sm" variant="filled" color="blue" icon="cloud-arrow-up" wire:click="pushBeforeDelete"
                                    wire:loading.attr="disabled" wire:target="pushBeforeDelete">
                                    Push now
                                </flux:button>
                            @elseif(!$pendingDeleteGitStatus['hasUpstream'])
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
    @endif
</div>
