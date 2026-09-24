<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-semibold">Settings</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.3fr] gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
            <h2 class="font-medium">Dashboard access</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Token: <code>{{ $maskedToken }}</code></p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Required on every request — it's what stops anyone else on this machine's network
                from opening your sites, database credentials, or any control on this page. The
                desktop launcher already has it built in, so you won't normally see it; only
                regenerate if you think it's leaked.
            </p>
            @if($justRegenerated)
                <p class="text-sm text-green-600 dark:text-green-400">Regenerated — this browser is already using the new token, but any other open Linux Dev tab/device will need to be relaunched from the app icon.</p>
            @endif
            <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="regenerateToken" wire:confirm="Regenerate the dashboard token? Any other open tab or device using the old link will be locked out until relaunched.">Regenerate token</flux:button>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
            <h2 class="font-medium">Defaults</h2>
            <div>
                <label class="block text-sm font-medium mb-1">New project PHP version</label>
                <select wire:model.live="defaultPhpVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'] as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Default Node.js version</label>
                <select wire:model.live="defaultNodeVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach($availableNodeVersions as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Currently: {{ $activeDefaultNodeVersion ?? 'not found' }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Pre-selected for a site's Node.js version on its detail page until you pick one explicitly there — doesn't install or rebuild anything on its own.</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Add a Node.js version</label>
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="newNodeVersion" placeholder="20"
                        class="w-24 rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />
                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" wire:click="installNodeVersion" wire:loading.attr="disabled" wire:target="installNodeVersion">
                        Install
                    </flux:button>
                    <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="installNodeVersion">Installing via nvm — this can take a minute…</span>
                </div>
                @if($newNodeVersionNote)
                    <p class="text-xs text-green-600 dark:text-green-400 mt-1">{{ $newNodeVersionNote }}</p>
                @endif
                @if($newNodeVersionError)
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $newNodeVersionError }}</p>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
            <h2 class="font-medium">Appearance</h2>

            <div id="appearance-buttons" class="flex gap-2">
                <flux:button data-appearance="light" size="sm" variant="ghost" icon="sun" onclick="ldevSetAppearance('light')" class="flex-1 justify-center">Light</flux:button>
                <flux:button data-appearance="dark" size="sm" variant="ghost" icon="moon" onclick="ldevSetAppearance('dark')" class="flex-1 justify-center">Dark</flux:button>
                <flux:button data-appearance="system" size="sm" variant="ghost" icon="computer-desktop" onclick="ldevSetAppearance('system')" class="flex-1 justify-center">System</flux:button>
            </div>
            <script>
                (function () {
                    const buttons = document.querySelectorAll('#appearance-buttons [data-appearance]');
                    function highlight(value) {
                        buttons.forEach((btn) => {
                            btn.classList.toggle('ring-2', btn.dataset.appearance === value);
                            btn.classList.toggle('ring-blue-500', btn.dataset.appearance === value);
                        });
                    }
                    window.ldevSetAppearance = function (value) {
                        if (typeof window.Flux.applyAppearance === 'function') {
                            window.Flux.applyAppearance(value);
                        } else {
                            window.Flux.appearance = value;
                        }
                        highlight(value);
                    };
                    highlight(localStorage.getItem('flux.appearance') || 'system');
                })();
            </script>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">Updates</h2>
            <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="checkVersionsNow" wire:loading.attr="disabled" wire:target="checkVersionsNow">
                Check now
            </flux:button>
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="checkVersionsNow">Checking upstream…</p>

        @if($versionCheckLastRun)
            <p class="text-xs text-gray-400 dark:text-gray-500">Last checked {{ \Illuminate\Support\Carbon::parse($versionCheckLastRun)->diffForHumans() }}</p>
        @else
            <p class="text-sm text-gray-400 dark:text-gray-500">Never checked yet — click "Check now", or wait for the daily timer.</p>
        @endif

        @php
            $php = $versionChecks['php'] ?? null;
            $node = $versionChecks['node'] ?? null;
@endphp

        <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
            @php
                $ldev = $versionChecks['ldev'] ?? null;
                $ldevRepository = config('ldev.github_repository');
            @endphp
            <div class="flex items-center justify-between gap-4 py-1.5">
                <span class="text-gray-500 dark:text-gray-400">Linux Dev {{ config('ldev.version') }} ({{ ucfirst(config('ldev.platform')) }})</span>
                @if(!$ldevRepository)
                    <span class="text-gray-400 dark:text-gray-500">Update check not configured</span>
                @elseif(\App\Services\VersionChecker::ldevUpdateAvailable($ldev))
                    <span class="text-right text-yellow-700 dark:text-yellow-400">
                        {{ $ldev['latest'] }} available &mdash; <span class="font-mono">git pull</span> then <span class="font-mono">sudo ./deploy-app.sh</span>
                        <a href="{{ $ldev['url'] }}" target="_blank" rel="noopener" class="ml-1 underline">Release notes</a>
                    </span>
                @elseif($ldev)
                    <span class="text-green-600 dark:text-green-400">Up to date &mdash; latest release is {{ $ldev['latest'] }}</span>
                @else
                    <span class="text-gray-400 dark:text-gray-500">No release found yet on <a href="https://github.com/{{ $ldevRepository }}/releases" target="_blank" rel="noopener" class="underline">GitHub</a></span>
                @endif
            </div>

            @if($php)
                <div class="flex items-center justify-between py-1.5">
                    <span class="text-gray-500 dark:text-gray-400">PHP</span>
                    @if(!empty($php['newCycles']))
                        <span class="text-yellow-700 dark:text-yellow-400">
                            {{ implode(', ', $php['newCycles']) }} available (installed up to 8.5)
                        </span>
                    @else
                        <span class="text-green-600 dark:text-green-400">Up to date — latest is {{ $php['latestPatch'] }}</span>
                    @endif
                </div>
            @endif

            @if($node)
                <div class="flex items-center justify-between py-1.5">
                    <span class="text-gray-500 dark:text-gray-400">Node.js (latest LTS)</span>
                    @if($node['isNew'])
                        <span class="text-yellow-700 dark:text-yellow-400">{{ $node['latestLtsVersion'] }} available — not in the known-versions list yet</span>
                    @else
                        <span class="text-green-600 dark:text-green-400">Up to date — latest LTS is {{ $node['latestLtsVersion'] }}</span>
                    @endif
                </div>
            @endif

            @foreach(['laravel' => 'Laravel', 'livewire' => 'Livewire', 'flux' => 'Flux'] as $key => $label)
                @php $check = $versionChecks[$key] ?? null;@endphp
                @if($check)
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                        @if($check['satisfiesCurrent'])
                            <span class="text-green-600 dark:text-green-400">Up to date — latest is {{ $check['latest'] }}</span>
                        @else
                            <span class="text-yellow-700 dark:text-yellow-400">{{ $check['latest'] }} available — a new major version, outside this app's current constraint</span>
                        @endif
                    </div>
                @endif
            @endforeach

            @php $dnf = $versionChecks['dnf'] ?? null;@endphp
            @if($dnf)
                <div class="py-1.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500 dark:text-gray-400">System packages (nginx, PHP, databases&hellip;)</span>
                        @if($dnf['error'] ?? null)
                            <span class="text-red-600 dark:text-red-400">Check failed: {{ $dnf['error'] }}</span>
                        @elseif(empty($dnf['packages']))
                            <span class="text-green-600 dark:text-green-400">Up to date</span>
                        @else
                            <span class="text-yellow-700 dark:text-yellow-400">{{ count($dnf['packages']) }} update(s) available</span>
                        @endif
                    </div>
                    @if(!empty($dnf['packages']))
                        @php $upgradeCommand = 'sudo dnf upgrade --refresh ' . collect($dnf['packages'])->pluck('name')->implode(' ');@endphp
                        <div class="rounded bg-gray-50 dark:bg-gray-900 p-2 space-y-1 font-mono text-xs">
                            @foreach($dnf['packages'] as $pkg)
                                <div class="flex justify-between gap-4">
                                    <span>{{ $pkg['name'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ $pkg['installed'] ?? '?' }} &rarr; {{ $pkg['available'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 text-xs font-mono break-all bg-gray-50 dark:bg-gray-900 rounded px-2 py-1">{{ $upgradeCommand }}</code>
                            <flux:button size="sm" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText(@js($upgradeCommand))">Copy</flux:button>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Run this in a terminal. The dashboard never runs sudo itself.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">Desktop notifications</h2>
            <flux:button size="sm" variant="filled" color="blue" icon="bell" wire:click="sendTestNotification">Send test</flux:button>
        </div>
        <flux:switch wire:model.live="desktopNotifications" label="Notify me about failed services, expiring certificates, low disk space and new updates" />
        <div class="flex items-center gap-3 text-sm">
            <label for="notification-timeout" class="text-gray-500 dark:text-gray-400">Keep on screen</label>
            <select id="notification-timeout" wire:model.live="notificationTimeout"
                class="rounded px-2 py-1 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                <option value="0">Until I dismiss it</option>
                <option value="10">10 seconds</option>
                <option value="30">30 seconds</option>
                <option value="60">1 minute</option>
            </select>
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500">Missed one? Past notifications stay in KDE's notification history (the bell in the system tray).</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Health is checked every 5 minutes and updates once a day. Each problem is only announced once, until it clears.</p>
        @if($notificationNote)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $notificationNote }}</p>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-4">
        <h2 class="font-medium">Local service credentials</h2>

        @php
            $credRow = function ($label, $value) {
                return '<div class="flex items-center justify-between gap-4 text-sm py-1">'
                    . '<span class="text-gray-500 dark:text-gray-400">' . e($label) . '</span>'
                    . '<code class="text-right break-all">' . e($value) . '</code>'
                    . '</div>';
            };
@endphp

        <div>
            <p class="text-sm font-medium mb-1">
                MinIO console —
                <a href="http://127.0.0.1:9001" target="_blank" class="text-blue-500">http://127.0.0.1:9001</a>
            </p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                {!! $credRow('Username', $minioAccessKey) !!}
                {!! $credRow('Password', $minioSecretKey) !!}
                {!! $credRow('Bucket data on disk', $minioDataDir) !!}
            </div>
        </div>

        <div>
            <p class="text-sm font-medium mb-1">Adminer (per-site "Open in Adminer" links)</p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                {!! $credRow('MySQL username', 'root') !!}
                {!! $credRow('MySQL password', '(leave blank)') !!}
                {!! $credRow('PostgreSQL username', 'postgres') !!}
                {!! $credRow('PostgreSQL password', '(leave blank)') !!}
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Both use trust auth on 127.0.0.1 — no real password is set.</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">Global Composer credentials</h2>
            @unless($composerShowForm)
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startAddingComposerCredential">Add credential</flux:button>
            @endunless
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">HTTP Basic credentials for a private Composer package repository (e.g. Flux Pro's <code>composer.fluxui.dev</code>) — saved here once, then used automatically for every project's <code>composer install</code>/<code>require</code>, scaffolded or cloned. A project that needs a different account or license for the same host (for example its own Flux Pro license) can override it under that project's <em>Project settings &rarr; Composer credentials</em>.</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Mirrored to <code>~/.config/composer/auth.json</code> with a snapshot after every change. Either copy rebuilds the other if one is lost.</p>

        @if($composerRepairNote)
            <p class="text-sm text-blue-700 dark:text-blue-300">{{ $composerRepairNote }}</p>
        @endif

        @if($composerCredentials->isEmpty() && !$composerShowForm)
            <p class="text-sm text-gray-400 dark:text-gray-500">No Composer credentials saved yet.</p>
        @endif

        @if($composerCredentials->isNotEmpty())
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($composerCredentials as $cred)
                    <div class="flex items-center justify-between gap-4 py-2 text-sm">
                        <div>
                            <p class="font-medium">{{ $cred->host }}</p>
                            <p class="text-gray-500 dark:text-gray-400">{{ $cred->username }}</p>
                            @if(in_array($cred->host, $composerUnreadable, true))
                                <p class="text-xs text-red-600 dark:text-red-400">Saved secret can't be decrypted (the dashboard's APP_KEY changed). Composer keeps using the copy in auth.json; click Edit and re-enter the secret to fix.</p>
                            @endif
                        </div>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="filled" color="blue" icon="pencil" wire:click="editComposerCredential({{ $cred->id }})">Edit</flux:button>
                            <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteComposerCredential({{ $cred->id }})" wire:confirm="Remove Composer credentials for {{ $cred->host }}? Any project needing them will fail composer install again until re-added.">Delete</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($composerShowForm)
            <div class="space-y-3 border-t border-gray-100 dark:border-gray-700 pt-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Host</label>
                    <input type="text" wire:model="composerHost" placeholder="composer.fluxui.dev"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('composerHost') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Username</label>
                    <input type="text" wire:model="composerUsername" placeholder="your account email"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('composerUsername') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">
                        {{ $composerEditingId ? 'New secret / license key' : 'Secret / license key' }}
                        @if($composerEditingId)<span class="text-gray-400 dark:text-gray-500 font-normal">(leave blank to keep the current one)</span>@endif
                    </label>
                    <input type="password" wire:model="composerSecret"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('composerSecret') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="primary" icon="check" wire:click="saveComposerCredential">Save</flux:button>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelComposerForm">Cancel</flux:button>
                </div>
            </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">Dashboard backup</h2>
            <flux:button size="sm" variant="primary" icon="archive-box-arrow-down" wire:click="backupDashboardNow" wire:loading.attr="disabled" wire:target="backupDashboardNow">Back up now</flux:button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            The dashboard's own database (your sites, saved repository tokens, settings) plus its
            <span class="font-mono">.env</span>, which the saved tokens can't be decrypted without. Taken
            automatically every day and before every <span class="font-mono">deploy-app.sh</span>; the newest 14 are kept.
        </p>

        @if($dashboardBackups)
            <div class="divide-y divide-gray-100 dark:divide-gray-700 text-sm max-h-80 overflow-y-auto pr-3 [scrollbar-gutter:stable]">
                @foreach($dashboardBackups as $snap)
                    <div class="py-1.5 flex flex-wrap items-center justify-between gap-3" wire:key="dash-bk-{{ $snap['name'] }}">
                        <span class="font-mono text-xs">{{ $snap['name'] }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($snap['time'])->diffForHumans() }} · {{ round($snap['dbSize'] / 1024) }} KB{{ $snap['hasEnv'] ? '' : ' · no .env' }}
                            </span>
                            <flux:button size="xs" variant="danger" icon="arrow-uturn-left" wire:click="restoreDashboard('{{ $snap['name'] }}')"
                                wire:confirm="Restore the dashboard from {{ $snap['name'] }}? Its sites, settings, saved tokens and .env replace the current ones. The current state is saved first, so you can undo this."
                                wire:loading.attr="disabled" wire:target="restoreDashboard">Restore</flux:button>
                        </span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-yellow-600 dark:text-yellow-400">No backups yet — click "Back up now".</p>
        @endif

        @if($dashboardSafetyCopies)
            <details class="text-sm">
                <summary class="cursor-pointer text-gray-600 dark:text-gray-300">Before a restore ({{ count($dashboardSafetyCopies) }})</summary>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">What the dashboard held just before each restore. Restore one of these to undo a restore.</p>
                <div class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($dashboardSafetyCopies as $snap)
                    <div class="py-1.5 flex flex-wrap items-center justify-between gap-3" wire:key="dash-bk-{{ $snap['name'] }}">
                        <span class="font-mono text-xs">{{ $snap['name'] }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($snap['time'])->diffForHumans() }} · {{ round($snap['dbSize'] / 1024) }} KB{{ $snap['hasEnv'] ? '' : ' · no .env' }}
                            </span>
                            <flux:button size="xs" variant="danger" icon="arrow-uturn-left" wire:click="restoreDashboard('{{ $snap['name'] }}')"
                                wire:confirm="Restore the dashboard from {{ $snap['name'] }}? Its sites, settings, saved tokens and .env replace the current ones. The current state is saved first, so you can undo this."
                                wire:loading.attr="disabled" wire:target="restoreDashboard">Restore</flux:button>
                        </span>
                    </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if($dashboardBackupNote)
            <p class="text-xs text-green-600 dark:text-green-400">{{ $dashboardBackupNote }}</p>
        @endif
        @if($dashboardBackupError)
            <p class="text-xs text-red-600 dark:text-red-400">{{ $dashboardBackupError }}</p>
        @endif

        <p class="text-xs text-gray-400 dark:text-gray-500">
            Stored in <span class="font-mono">{{ $dashboardBackupDir }}</span>. If the dashboard itself won't open, restore from a terminal instead:
            <span class="font-mono">php artisan ldev:restore-dashboard</span> (lists backups) from <span class="font-mono">~/.ldev/app</span>, then restart the dashboard.
        </p>
    </div>
</div>
