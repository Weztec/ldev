<div class="space-y-6">
    @php
        $dbType = $site->databaseType();

        $usesNode = $site->usesNode();

        $subdomainExample = $site->subdomainWildcardName() ?? 'subdomain';
@endphp

    <div>
        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-blue-500">&larr; Sites</a>
        <h1 class="text-2xl font-semibold">{{ $site->name }}</h1>
        <a href="https://{{ $site->domain }}" target="_blank" class="text-sm text-blue-500">{{ $site->domain }}</a>
    </div>

    <x-site-tabs :site="$site" active="settings" />

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex flex-wrap justify-between items-center gap-2">
            <h2 class="font-medium">Project file (<span class="font-mono">ldev.json</span>)</h2>
            <div class="flex gap-2">
                @if($manifestExists)
                    <flux:button size="sm" variant="filled" color="blue" icon="eye" wire:click="switchEnvTarget('manifest')" x-on:click="document.getElementById('project-files').scrollIntoView({ behavior: 'smooth' })">
                        View file
                    </flux:button>
                @endif
                @if($manifestExists && $manifestDiffs)
                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" wire:click="applyManifest" wire:loading.attr="disabled" wire:target="applyManifest">
                        Apply project file
                    </flux:button>
                @endif
                <flux:button size="sm" variant="filled" color="blue" icon="document-arrow-up" wire:click="saveManifest" wire:loading.attr="disabled" wire:target="saveManifest">
                    Save current settings to ldev.json
                </flux:button>
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            A file in the project root that records this site's PHP and Node versions, database, flags, queue settings and custom jobs. Commit it and everyone who clones the project gets the same setup automatically. It never contains passwords or <span class="font-mono">.env</span> values.
        </p>

        @if($manifestNote)
            <p class="text-xs text-green-600 dark:text-green-400">{{ $manifestNote }}</p>
        @endif
        @if($manifestError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $manifestError }}</p>
        @endif

        @if(! $manifestExists)
            <p class="text-sm text-gray-400 dark:text-gray-500">This project has no ldev.json yet.</p>
        @elseif(! $manifestDiffs)
            <p class="text-sm text-green-600 dark:text-green-400">This site matches its ldev.json.</p>
        @else
            <div class="text-sm">
                <p class="text-yellow-700 dark:text-yellow-400 mb-1">This site differs from its ldev.json:</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($manifestDiffs as $diff)
                        <div class="flex flex-wrap justify-between gap-2 py-1">
                            <span class="font-mono text-xs">{{ $diff['key'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">file: <span class="font-mono">{{ $diff['file'] }}</span> &middot; this site: <span class="font-mono">{{ $diff['current'] }}</span></span>
                        </div>
                    @endforeach
                </div>
                @if(collect($manifestDiffs)->contains(fn ($d) => in_array($d['key'], ['database.driver', 'database.name'], true)))
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">The database is only set up from ldev.json when a project is first added. To switch it now, use the Environment section below.</p>
                @endif
            </div>
        @endif

        @if($manifestRequirements)
            <div class="text-sm divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($manifestRequirements as $req)
                    <div class="flex justify-between gap-2 py-1">
                        <span>{{ $req['service'] }} <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $req['constraint'] }}</span></span>
                        @if($req['ok'])
                            <span class="text-green-600 dark:text-green-400">Installed {{ $req['installed'] }}</span>
                        @else
                            <span class="text-red-600 dark:text-red-400">{{ $req['installed'] ? 'Installed ' . $req['installed'] . ' does not match' : 'Not installed' }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if($manifestWarnings)
            <ul class="text-xs text-yellow-700 dark:text-yellow-400 list-disc pl-5">
                @foreach($manifestWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <h2 class="font-medium">Environment</h2>
        <p class="text-xs text-gray-400 dark:text-gray-500">Changing any of these applies immediately — no need to re-scaffold the project.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">PHP version</label>
                <select wire:model.live="phpVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'] as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
                @if(isset($manifestVersions['php']) && $site->php_version && $manifestVersions['php'] !== $site->php_version)
                    <div class="mt-2 p-2 rounded border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-900/20 text-xs text-yellow-800 dark:text-yellow-300 space-y-2">
                        <p>Trying PHP {{ $site->php_version }} on this machine only. The project's <span class="font-mono">ldev.json</span> says {{ $manifestVersions['php'] }}.</p>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" variant="filled" color="blue" icon="check" wire:click="keepVersionForEveryone('php')" wire:loading.attr="disabled" wire:target="keepVersionForEveryone">
                                Keep {{ $site->php_version }} for everyone
                            </flux:button>
                            <flux:button size="sm" icon="arrow-uturn-left" wire:click="switchBackToManifestVersion('php')" wire:loading.attr="disabled" wire:target="switchBackToManifestVersion">
                                Switch back to {{ $manifestVersions['php'] }}
                            </flux:button>
                        </div>
                    </div>
                @endif
                <div class="flex items-center gap-2 mt-1">
                    <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="restartPhpFpm" wire:loading.attr="disabled" wire:target="restartPhpFpm">
                        Restart PHP-FPM
                    </flux:button>
                    @if($phpFpmRestartNote)
                        <span class="text-xs text-green-600 dark:text-green-400">{{ $phpFpmRestartNote }}</span>
                    @endif
                </div>
            </div>

            @if($usesNode)
                <div>
                    <label class="block text-sm font-medium mb-1">Node.js version</label>
                    <select wire:model.live="nodeVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">

                        @foreach((new \App\Services\NodeVersionManager)->availableVersions() as $version)
                            <option value="{{ $version }}">{{ $version }}</option>
                        @endforeach
                    </select>

                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Currently: {{ $activeNodeVersion ?? 'not found' }}
                    </p>
                    @if(isset($manifestVersions['node']) && $site->node_version && $manifestVersions['node'] !== $site->node_version)
                        <div class="mt-2 p-2 rounded border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-900/20 text-xs text-yellow-800 dark:text-yellow-300 space-y-2">
                            <p>Trying Node {{ $site->node_version }} on this machine only. The project's <span class="font-mono">ldev.json</span> says {{ $manifestVersions['node'] }}.</p>
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" variant="filled" color="blue" icon="check" wire:click="keepVersionForEveryone('node')" wire:loading.attr="disabled" wire:target="keepVersionForEveryone">
                                    Keep {{ $site->node_version }} for everyone
                                </flux:button>
                                <flux:button size="sm" icon="arrow-uturn-left" wire:click="switchBackToManifestVersion('node')" wire:loading.attr="disabled" wire:target="switchBackToManifestVersion">
                                    Switch back to {{ $manifestVersions['node'] }}
                                </flux:button>
                            </div>
                        </div>
                    @endif

                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1" wire:loading wire:target="nodeVersion">
                        Installing (if needed) and running npm install/build — this can take a minute…
                    </p>
                    @if($nodeSwitchError)
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $nodeSwitchError }}</p>
                    @endif
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium mb-1">Database</label>
                <select
                    wire:change="selectDatabase($event.target.value)"
                    class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600"
                >

                    @if($dbDriver === 'none')
                        <option value="none" selected disabled>None</option>
                    @endif
                    @foreach(['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'pgsql' => 'PostgreSQL'] as $value => $label)
                        <option value="{{ $value }}" @selected($dbDriver === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                @if(!$pendingDbDriver && in_array($dbDriver, ['mysql', 'pgsql'], true))
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Using database <span class="font-mono">{{ $site->databaseName() ?? '—' }}</span>
                        · <button type="button" class="text-blue-500 hover:underline" wire:click="reconfigureDatabase">Use a different database…</button>
                    </p>
                @endif

                @if($pendingDbDriver)
                    @php
                        $engineLabel = ['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'pgsql' => 'PostgreSQL'][$pendingDbDriver];
                        $isSwitch = $pendingDbDriver !== $dbDriver;
                        $serverBased = in_array($pendingDbDriver, ['mysql', 'pgsql'], true);
@endphp
                    <div class="mt-2 p-3 rounded border border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 text-sm space-y-2">
                        <p>
                            @if($isSwitch)
                                Switch to <strong>{{ $engineLabel }}</strong>?
                                @if($serverBased)
                                    Create a new database, or point this project at one that already exists.
                                @else
                                    This provisions a fresh, empty database of the new type.
                                @endif
                                Data in the current database is <strong>not</strong> copied over automatically, but a backup of it
                                is taken first (see the Database backups panel below) in case you need it back.
                            @else
                                Choose which <strong>{{ $engineLabel }}</strong> database this project uses. A backup of the
                                current one is taken first (see the Database backups panel below).
                            @endif
                        </p>

                        @if($serverBased)
                            <div class="space-y-2">
                                <label class="flex items-start gap-2">
                                    <input type="radio" wire:model.live="dbTarget" value="new" class="mt-1.5">
                                    <span class="flex-1">
                                        Create a new database
                                        <input type="text" wire:model="newDbName" @disabled($dbTarget !== 'new')
                                            class="w-full mt-1 rounded px-3 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm disabled:opacity-50" />
                                    </span>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="radio" wire:model.live="dbTarget" value="existing" class="mt-1.5" @disabled(empty($existingDatabases))>
                                    <span class="flex-1">
                                        Use an existing database
                                        @if($existingDbError)
                                            <span class="block text-xs text-red-600 dark:text-red-400">{{ $existingDbError }}</span>
                                        @elseif(empty($existingDatabases))
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">No databases found on this server yet.</span>
                                        @else
                                            <select wire:model="existingDbName" @disabled($dbTarget !== 'existing')
                                                class="w-full mt-1 rounded px-3 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm disabled:opacity-50">
                                                @foreach($existingDatabases as $database)
                                                    <option value="{{ $database }}">{{ $database }}{{ $database === $site->databaseName() && !$isSwitch ? ' (current)' : '' }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </span>
                                </label>
                            </div>
                        @endif

                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="runMigrationsOnSwitch">
                            Run migrations (php artisan migrate)
                        </label>
                        @if($serverBased && $dbTarget === 'existing')
                            <p class="text-xs text-gray-500 dark:text-gray-400 -mt-1 ml-6">Off by default here: an existing database may already have tables and data.</p>
                        @endif
                        <label class="flex items-center gap-2" @class(['opacity-50' => !$runMigrationsOnSwitch])>
                            <input type="checkbox" wire:model="runSeedOnSwitch" @disabled(!$runMigrationsOnSwitch)>
                            Also seed the database (php artisan db:seed)
                        </label>
                        <div class="flex gap-2 pt-1">
                            <flux:button size="sm" variant="danger" icon="check" wire:click="changeDatabase" wire:loading.attr="disabled" wire:target="changeDatabase">
                                {{ $isSwitch ? 'Confirm switch' : 'Use this database' }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelDatabaseChange" wire:loading.attr="disabled" wire:target="changeDatabase">
                                Cancel
                            </flux:button>
                        </div>
                    </div>
                @endif

                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1" wire:loading wire:target="changeDatabase">
                    Provisioning{{ $runMigrationsOnSwitch || $runSeedOnSwitch ? ' and migrating' : '' }}{{ $runSeedOnSwitch ? '/seeding' : '' }}…
                </p>
                @if($dbSwitchError)
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $dbSwitchError }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <h2 class="font-medium">Flags</h2>
        <div class="flex items-center justify-between">
            <span class="text-sm">Xdebug</span>
            <flux:switch :checked="$site->xdebug_enabled" wire:click="toggleXdebug" />
        </div>
        <div class="flex items-center justify-between">
            <span class="text-sm">Queue worker</span>
            <flux:switch :checked="$site->queue_worker_enabled" wire:click="toggleQueueWorker" />
        </div>
        <div class="flex items-center justify-between">
            <span class="text-sm">
                Reverb (WebSockets)

                <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="toggleReverb">— installing, this can take a while the first time…</span>
            </span>
            <flux:switch :checked="$site->reverb_enabled" wire:click="toggleReverb" />
        </div>
        <div class="flex items-center justify-between">
            <span class="text-sm">Scheduler (schedule:work)</span>
            <flux:switch :checked="$site->scheduler_enabled" wire:click="toggleScheduler" />
        </div>
        <div class="flex items-center justify-between">
            <span class="text-sm">Meilisearch search <span class="text-xs text-gray-400 dark:text-gray-500">sets MEILISEARCH_HOST and MEILISEARCH_KEY in .env, plus SCOUT_DRIVER for Laravel Scout</span></span>
            <flux:switch :checked="$site->usesMeilisearch()" wire:click="toggleMeilisearch" />
        </div>
        @if($dbType)
            <div class="flex items-center justify-between">
                <span class="text-sm">Automatic daily database backup</span>
                <flux:switch :checked="$site->db_auto_backup_enabled" wire:click="toggleAutoBackup" />
            </div>
        @endif
    </div>

    @if($site->queue_worker_enabled)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <h2 class="font-medium">Queue worker settings</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Applies the next time the queue worker (re)starts — saving here regenerates it
            immediately if it's currently on, above.
        </p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">Queues</label>
                <input type="text" wire:model="queueNames" placeholder="default"
                    class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />

                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    Comma-separated, passed as --queue=. If the app dispatches jobs onto named
                    queues (e.g. onQueue('xero')), they must be listed here or they'll never run.
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Workers</label>
                <input type="number" min="1" max="20" wire:model="queueWorkers" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Sleep (s)</label>
                <input type="number" min="0" max="60" wire:model="queueSleep" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Tries</label>
                <input type="number" min="1" max="20" wire:model="queueTries" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Max time (s)</label>
                <input type="number" min="60" max="86400" wire:model="queueMaxTime" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
            </div>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="primary" icon="check" wire:click="saveQueueSettings" wire:loading.attr="disabled" wire:target="saveQueueSettings">Save</flux:button>
            @if($queueSettingsSaved)
                <span class="text-xs text-green-600 dark:text-green-400">Saved.</span>
            @endif
        </div>
        @if($queueSettingsError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $queueSettingsError }}</p>
        @endif
    </div>
    @endif

    <livewire:pages::site-processes :site="$site" :key="'site-processes-' . $site->id" />

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <h2 class="font-medium">Local hosts</h2>

        @if(empty($hostsEntries['automatic']) && empty($hostsEntries['manual']))
            <p class="text-xs text-yellow-700 dark:text-yellow-400">
                No entries found — this needs a re-run of <code class="font-mono">sudo ./setup-environment.sh</code> to install the helper script this feature relies on.
            </p>
        @elseif(!empty($hostsEntries['automatic']))
            <div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-1">Required for this site — kept in sync automatically:</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($hostsEntries['automatic'] as $host)
                        <span class="text-xs font-mono bg-gray-100 dark:bg-gray-700 rounded px-2 py-1">{{ $host }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Speeds up .test resolution for anything else by adding a direct /etc/hosts entry
            instead of relying on the system resolver. Add any subdomain your project creates
            dynamically here (e.g. {{ $subdomainExample }}.{{ $site->name }}.test) if it's slow
            to load and isn't listed above already.
        </p>

        @if(!empty($hostsEntries['manual']))
            <div class="flex flex-wrap gap-1.5">
                @foreach($hostsEntries['manual'] as $host)
                    <span class="text-xs font-mono bg-gray-100 dark:bg-gray-700 rounded pl-2 pr-1 py-1 flex items-center gap-1">
                        {{ $host }}

                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeHost('{{ $host }}')" wire:confirm="Remove {{ $host }} from /etc/hosts?"></flux:button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-2">
            <input type="text" wire:model="newHostname" placeholder="{{ $subdomainExample }}.{{ $site->name }}.test"
                class="rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 text-sm font-mono" />
            <flux:button size="sm" variant="filled" color="blue" icon="plus" wire:click="addHost" wire:loading.attr="disabled" wire:target="addHost">Add</flux:button>
        </div>
        @if($hostsError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $hostsError }}</p>
        @endif
    </div>

    @if($dbType)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="font-medium">Database backups</h2>
            <flux:button size="sm" variant="primary" icon="archive-box-arrow-down" wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow">
                Backup now
            </flux:button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            A backup is also taken automatically right before switching this site's database driver above.
        </p>

        @if($backupNote)
            <p class="text-xs text-green-600 dark:text-green-400">{{ $backupNote }}</p>
        @endif
        @if($backupError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $backupError }}</p>
        @endif

        @if(empty($backups))
            <p class="text-sm text-gray-400 dark:text-gray-500">No backups yet.</p>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($backups as $backup)
                    <div class="flex items-center justify-between py-1.5 text-sm">
                        <div>
                            <span class="font-mono">{{ $backup['name'] }}</span>
                            <span class="text-gray-400 dark:text-gray-500 text-xs">
                                ({{ number_format($backup['size'] / 1024, 1) }} KB, {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])->diffForHumans() }})
                            </span>
                        </div>
                        <div class="flex gap-1.5">
                            <a href="{{ route('backup-download', ['site' => $site, 'filename' => $backup['name']]) }}">
                                <flux:button size="sm" variant="filled" color="blue" icon="arrow-down-tray">Download</flux:button>
                            </a>
                            <flux:button size="sm" variant="filled" color="blue" icon="arrow-uturn-left" wire:click="restoreBackup('{{ $backup['name'] }}')"
                                wire:loading.attr="disabled" wire:target="restoreBackup('{{ $backup['name'] }}')"
                                wire:confirm="Restore {{ $backup['name'] }}? This overwrites the site's current database with this backup's contents.">
                                Restore
                            </flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteBackup('{{ $backup['name'] }}')"
                                wire:confirm="Delete this backup file? This can't be undone.">
                                Delete
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
            <label class="block text-sm font-medium mb-1">Import a backup file</label>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="importDriver" class="rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 w-44 shrink-0">
                    @foreach(['sqlite' => 'SQLite (.sqlite)', 'mysql' => 'MySQL (.sql)', 'pgsql' => 'PostgreSQL (.sql)'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <flux:input.file wire:model="importFile" size="sm" class="max-w-56 shrink-0" />
                <flux:button size="sm" variant="primary" icon="arrow-up-tray" wire:click="importBackup" wire:loading.attr="disabled" wire:target="importBackup,importFile">
                    Import and restore
                </flux:button>
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                The selected driver must match the site's current database driver — this imports data, it doesn't switch drivers.
            </p>
            <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="importFile">Uploading…</p>
            @error('importFile') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="font-medium">Composer credentials</h2>
            @unless($projectComposerShowForm)
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startProjectComposerCredential">Add for this project</flux:button>
            @endunless
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Overrides the <a href="{{ route('settings') }}" wire:navigate class="text-blue-600 dark:text-blue-400 hover:underline">global credentials</a> for this project only, such as a different Flux Pro license. Saved to the project's git-ignored <span class="font-mono">auth.json</span>.
        </p>

        @php $overridden = collect($projectComposerCredentials)->pluck('host')->all(); @endphp
        <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
            @foreach($projectComposerCredentials as $cred)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="pcc-{{ $cred['id'] }}">
                    <div>
                        <p class="font-medium">{{ $cred['host'] }} <span class="text-xs font-normal text-blue-600 dark:text-blue-400">this project</span></p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $cred['username'] }}</p>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="filled" color="blue" icon="pencil" wire:click="editProjectComposerCredential({{ $cred['id'] }})">Edit</flux:button>
                        <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteProjectComposerCredential({{ $cred['id'] }})" wire:confirm="Remove this project's credentials for {{ $cred['host'] }}? The project goes back to using the global ones, if any.">Delete</flux:button>
                    </div>
                </div>
            @endforeach
            @foreach($globalComposerCredentials as $cred)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="gcc-{{ $cred['id'] }}">
                    <div class="{{ in_array($cred['host'], $overridden, true) ? 'opacity-60' : '' }}">
                        <p class="font-medium">{{ $cred['host'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ in_array($cred['host'], $overridden, true) ? 'global, overridden here' : 'global' }}</span></p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $cred['username'] }}</p>
                    </div>
                    @unless(in_array($cred['host'], $overridden, true))
                        <flux:button size="sm" variant="filled" color="blue" icon="arrows-right-left" wire:click="startProjectComposerCredential('{{ $cred['host'] }}')">Override for this project</flux:button>
                    @endunless
                </div>
            @endforeach
            @if(!count($projectComposerCredentials) && !count($globalComposerCredentials))
                <p class="py-2 text-gray-400 dark:text-gray-500">No Composer credentials, global or for this project.</p>
            @endif
        </div>

        @if($projectComposerShowForm)
            <div class="space-y-3 border-t border-gray-100 dark:border-gray-700 pt-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Host</label>
                    <input type="text" wire:model="projectComposerHost" placeholder="composer.fluxui.dev"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('projectComposerHost') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Username</label>
                    <input type="text" wire:model="projectComposerUsername" placeholder="the account email for this license"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('projectComposerUsername') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">
                        {{ $projectComposerEditingId ? 'New secret / license key' : 'Secret / license key' }}
                        @if($projectComposerEditingId)<span class="text-gray-400 dark:text-gray-500 font-normal">(leave blank to keep the current one)</span>@endif
                    </label>
                    <input type="password" wire:model="projectComposerSecret"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('projectComposerSecret') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="primary" icon="check" wire:click="saveProjectComposerCredential">Save</flux:button>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelProjectComposerCredential">Cancel</flux:button>
                </div>
            </div>
        @endif

        @if($projectComposerNote)
            <p class="text-xs text-green-600 dark:text-green-400">{{ $projectComposerNote }}</p>
        @endif
        @if($projectComposerError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $projectComposerError }}</p>
        @endif
    </div>

    <div id="project-files" class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex justify-between items-center">
            <h2 class="font-medium">Environment / project files</h2>
            <div class="flex gap-2">
                @if($hasEnvBackup && $envEditorTarget === 'env')
                    <flux:button size="sm" variant="filled" color="blue" icon="arrow-uturn-left" wire:click="restorePreviousEnv"
                        wire:confirm="Restore the previous .env? This overwrites your current edits in the box below.">
                        Restore previous
                    </flux:button>
                @endif
                <flux:button size="sm" variant="primary" icon="check" wire:click="saveEnvContent" wire:loading.attr="disabled" wire:target="saveEnvContent">Save</flux:button>
            </div>
        </div>

        <div class="flex flex-wrap gap-1 text-sm border-b border-gray-100 dark:border-gray-700">
            @foreach($envEditorTabs as $target => $label)
                <button type="button" wire:click="switchEnvTarget('{{ $target }}')" wire:key="env-tab-{{ $target }}"
                    class="px-3 py-1.5 border-b-2 {{ $envEditorTarget === $target ? 'border-blue-500 font-medium' : 'border-transparent text-gray-500 dark:text-gray-400' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            @if($envEditorTarget === 'backup')
                Editing the backup file directly — saving here does not touch the live .env or
                roll a further backup.
            @elseif($envEditorTarget === 'example')
                The template other developers copy to make their own .env. Usually committed to git,
                so keep real secrets out of it.
            @elseif($envEditorTarget === 'testing')
                Loaded by Laravel instead of .env when APP_ENV=testing. Values set in phpunit.xml
                and your ldev test overrides take priority over it.
            @elseif(in_array($envEditorTarget, ['phpunit', 'phpunit_dist'], true))
                The project's test configuration, usually committed and shared by every developer.
                Saved straight to the file after an XML check. For settings that only apply to your
                machine, use <em>Test environment</em> in the Tests section of the Overview instead.
            @elseif($envEditorTarget === 'manifest')
                This project's shared Linux Dev setup, usually committed. Checked as JSON before saving;
                unknown or invalid values are listed in the Project file card above and ignored.
            @elseif($envEditorTarget === 'gitignore')
                Saved straight to .gitignore — no rolling backup (its own git history already
                covers that once committed). Created fresh if the project doesn't have one yet.
            @else
                Saving keeps one rolling backup of the previous version (.env.backup) — this
                doesn't restart anything on its own; use "Restart PHP-FPM" above, or toggle the
                queue worker/Reverb flags above, if a running process needs to pick up the change.
            @endif
        </p>
        <textarea wire:model="envContent" rows="14" spellcheck="false"
            class="w-full font-mono text-xs rounded px-3 py-2 border-gray-300 dark:bg-gray-900 dark:border-gray-600"></textarea>
        @if($envSaved)
            <p class="text-xs text-green-600 dark:text-green-400">Saved.</p>
        @endif
        @if($envError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $envError }}</p>
        @endif
    </div>
</div>
