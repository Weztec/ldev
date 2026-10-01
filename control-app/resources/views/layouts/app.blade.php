<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Linux Dev Dashboard</title>

    @php($iconVersion = fn (string $file) => filemtime(public_path($file)) ?: time())
    <link rel="icon" href="{{ asset('favicon.svg') }}?v={{ $iconVersion('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v={{ $iconVersion('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('favicon-32x32.png') }}?v={{ $iconVersion('favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="icon" href="{{ asset('favicon-16x16.png') }}?v={{ $iconVersion('favicon-16x16.png') }}" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v={{ $iconVersion('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}?v={{ $iconVersion('site.webmanifest') }}">
    <meta name="theme-color" content="#6d28d9">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @fluxAppearance
    <script>

        document.addEventListener('livewire:navigated', () => {
            const stored = window.localStorage.getItem('flux.appearance') || 'system';
            if (typeof window.Flux.applyAppearance === 'function') {
                window.Flux.applyAppearance(stored);
            } else {
                window.Flux.appearance = stored;
            }
        });

        (() => {
            const pollers = {};
            const subscribe = (url, listener) => {
                const poller = pollers[url] ??= { listeners: new Set(), timer: null };
                poller.listeners.add(listener);
                const tick = async () => {
                    try {
                        const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                        if (response.ok) {
                            const data = await response.json();
                            poller.listeners.forEach((l) => l(data));
                        }
                    } catch (e) {}
                    poller.timer = poller.listeners.size ? setTimeout(tick, 1500) : null;
                };
                if (!poller.timer) {
                    poller.timer = setTimeout(tick, 1500);
                }
                return () => poller.listeners.delete(listener);
            };
            const elapsed = (ms) => {
                const secs = Math.max(0, Math.floor(ms / 1000));
                const h = Math.floor(secs / 3600);
                const m = Math.floor((secs % 3600) / 60);
                return [h ? h + ' h' : '', h || m ? m + ' min' : '', (secs % 60) + ' s'].filter(Boolean).join(' ');
            };
            window.ldevElapsed = elapsed;

            document.addEventListener('alpine:init', () => {
                window.Alpine.data('ldevJobProgress', (url, job, unit, initial) => ({
                    done: initial.done ?? 0,
                    total: initial.total ?? 0,
                    sub: initial.sub ?? null,
                    label: initial.label || 'Starting',
                    started: Date.parse(initial.startedAt) || Date.now(),
                    now: Date.now(),
                    finished: false,
                    best: 0,
                    init() {
                        this.clock = setInterval(() => { this.now = Date.now(); }, 1000);
                        this.stop = subscribe(url, (data) => {
                            const state = data[job];
                            if (!state || this.finished) {
                                return;
                            }
                            if (!state.running) {
                                this.finished = true;
                                this.done = this.total;
                                this.sub = null;
                                clearInterval(this.clock);
                                this.$wire.refreshJobs();
                                return;
                            }
                            this.done = state.done ?? 0;
                            this.total = state.total ?? 0;
                            this.sub = state.sub ?? null;
                            this.label = state.label || this.label;
                        });
                    },
                    destroy() {
                        clearInterval(this.clock);
                        this.stop && this.stop();
                    },
                    get percent() {
                        if (this.finished) {
                            return 100;
                        }
                        const part = this.sub && this.sub[1] ? this.sub[0] / this.sub[1] : 0;
                        const now = this.total ? Math.round((this.done + part) / this.total * 100) : 0;
                        this.best = Math.min(99, Math.max(this.best, 3, now));
                        return this.best;
                    },
                    get position() {
                        if (!this.total) {
                            return '';
                        }
                        let text = unit + ' ' + Math.min(this.done + 1, this.total) + ' of ' + this.total;
                        if (this.sub && this.sub[1]) {
                            text += ', file ' + Math.min(this.sub[0] + 1, this.sub[1]) + ' of ' + this.sub[1];
                        }
                        return text;
                    },
                    get time() {
                        return elapsed(this.now - this.started);
                    },
                }));
            });

            document.addEventListener('livewire:init', () => {
                const active = new Map();
                let seq = 0;
                let clock = null;
                const render = () => {
                    let box = document.getElementById('ldev-busy');
                    if (!active.size) {
                        box && box.remove();
                        clearInterval(clock);
                        clock = null;
                        return;
                    }
                    if (!box) {
                        box = document.createElement('div');
                        box.id = 'ldev-busy';
                        box.setAttribute('role', 'status');
                        box.className = 'fixed bottom-4 right-4 z-50 w-72 max-w-[calc(100vw-2rem)] space-y-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 shadow-lg text-sm';
                        document.body.appendChild(box);
                    }
                    box.replaceChildren(...[...active.values()].map((item) => {
                        const row = document.createElement('div');
                        row.className = 'space-y-1';
                        const head = document.createElement('div');
                        head.className = 'flex justify-between gap-2 text-gray-700 dark:text-gray-200';
                        const name = document.createElement('span');
                        name.className = 'truncate';
                        name.textContent = item.text + '…';
                        const time = document.createElement('span');
                        time.className = 'shrink-0 tabular-nums text-gray-500 dark:text-gray-400';
                        time.textContent = elapsed(Date.now() - item.started);
                        head.append(name, time);
                        const track = document.createElement('div');
                        track.className = 'h-1.5 overflow-hidden rounded bg-gray-200 dark:bg-gray-700';
                        const fill = document.createElement('div');
                        fill.className = 'ldev-indeterminate h-full w-1/3 rounded bg-blue-500 dark:bg-blue-400';
                        track.append(fill);
                        row.append(head, track);
                        return row;
                    }));
                    clock ??= setInterval(render, 1000);
                };

                window.Livewire.interceptAction(({ action, onFinish }) => {
                    const el = action.origin?.el;
                    if (!el || !el.getAttributeNames || el.getAttributeNames().some((n) => n.startsWith('wire:poll'))) {
                        return;
                    }
                    const id = ++seq;
                    const text = (el.dataset.busyLabel || el.innerText || el.value || el.title || 'Working').trim().replace(/\s+/g, ' ').slice(0, 60) || 'Working';
                    const timer = setTimeout(() => {
                        active.set(id, { text, started: Date.now() - 600 });
                        render();
                    }, 600);
                    onFinish(() => {
                        clearTimeout(timer);
                        active.delete(id);
                        render();
                    });
                });
            });
        })();
    </script>

</head>
<body class="bg-gray-100 dark:bg-gray-900">
    @php($updateSummary = new \App\Services\UpdateSummary)
    @php($updateNotices = $updateSummary->notices())
    @php($updateFingerprint = $updateSummary->fingerprint())
    <div class="flex h-screen">

        <aside
            x-data="{
                collapsed: false,
                init() {
                    try { this.collapsed = window.localStorage.getItem('ldev.sidebar.collapsed') === 'true'; } catch (e) {}
                },
                toggle() {
                    this.collapsed = !this.collapsed;
                    try { window.localStorage.setItem('ldev.sidebar.collapsed', this.collapsed); } catch (e) {}
                },
            }"
            :class="collapsed ? 'w-16' : 'w-64'"
            class="flex flex-col bg-white dark:bg-gray-800 border-r dark:border-gray-700 shrink-0 transition-[width] duration-150"
        >

            <div x-show="!collapsed" x-cloak class="flex items-center justify-between gap-2 p-4">
                <div class="flex items-center gap-2 font-bold text-lg overflow-hidden">
                    <x-app-mark class="size-7 rounded-md" />
                    <span>Linux Dev</span>
                </div>
                <button type="button" x-on:click="toggle" title="Collapse sidebar"
                    class="shrink-0 p-1.5 rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <flux:icon name="chevron-double-left" class="size-4" />
                </button>
            </div>
            <div x-show="collapsed" x-cloak class="flex flex-col items-center gap-2 py-4">
                <x-app-mark class="size-7 rounded-md" />
                <button type="button" x-on:click="toggle" title="Expand sidebar"
                    class="p-1.5 rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <flux:icon name="chevron-double-right" class="size-4" />
                </button>
            </div>

            <nav class="space-y-1 flex-1 overflow-y-auto overflow-x-hidden">
                <x-nav-link route="dashboard" icon="squares-2x2" label="Sites" :active="request()->routeIs('dashboard') || request()->routeIs(['site-detail', 'site-settings'])" />
                <x-nav-link route="services" icon="server-stack" label="Services" :active="request()->routeIs('services')" />
                <x-nav-link route="php-versions" icon="cpu-chip" label="PHP Versions" :active="request()->routeIs('php-versions')" />
                <x-nav-link route="databases" icon="circle-stack" label="Databases" :active="request()->routeIs('databases')" />
                <x-nav-link route="repositories" icon="link" label="Repositories" :active="request()->routeIs('repositories')" />
                <x-nav-link route="logs" icon="document-text" label="Logs" :active="request()->routeIs('logs')" />
                <x-nav-link route="settings" icon="cog-6-tooth" label="Settings" :active="request()->routeIs('settings')" :badge="count($updateNotices) ?: null" />
            </nav>

            <div class="border-t dark:border-gray-700 py-1">
                <x-nav-link route="help" icon="question-mark-circle" label="Help" :active="request()->routeIs('help')" />
                @php($ldevCheck = json_decode((string) \App\Models\Setting::get('version_check_ldev'), true))
                @php($ldevNewVersion = \App\Services\VersionChecker::ldevUpdateAvailable($ldevCheck) ? $ldevCheck['latest'] : null)
                <a href="{{ route('settings', $ldevNewVersion ? ['update' => 1] : []) }}" wire:navigate x-show="!collapsed" x-cloak
                    class="block px-4 pt-1 pb-2 text-xs {{ $ldevNewVersion ? 'text-yellow-700 dark:text-yellow-400' : 'text-gray-400 dark:text-gray-500' }} hover:underline">
                    Linux Dev {{ config('ldev.version') }}@if($ldevNewVersion) &middot; Update available: {{ $ldevNewVersion }}@endif
                </a>
            </div>
        </aside>
        <main class="flex-1 overflow-y-auto p-6">
            @if($updateFingerprint)
                <div
                    x-data="{
                        key: 'ldev.updates.dismissed',
                        fingerprint: @js($updateFingerprint),
                        dismissed: false,
                        init() {
                            try { this.dismissed = window.localStorage.getItem(this.key) === this.fingerprint; } catch (e) {}
                        },
                        dismiss() {
                            this.dismissed = true;
                            try { window.localStorage.setItem(this.key, this.fingerprint); } catch (e) {}
                        },
                    }"
                    x-show="!dismissed" x-cloak
                    class="mb-4 flex items-start gap-3 rounded-lg border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-950 px-4 py-3 text-sm text-yellow-800 dark:text-yellow-200"
                >
                    <flux:icon name="arrow-up-circle" class="size-5 shrink-0" />
                    <div class="flex-1">
                        <span class="font-medium">Updates available:</span>
                        {{ collect($updateNotices)->map(fn ($n) => $n['label'] . ' ' . $n['detail'])->implode(' · ') }}
                        <a href="{{ route('settings') }}" wire:navigate class="ml-1 underline">View details</a>
                    </div>
                    <button type="button" x-on:click="dismiss" title="Dismiss until something new comes out"
                        class="shrink-0 p-0.5 rounded hover:bg-yellow-100 dark:hover:bg-yellow-900">
                        <flux:icon name="x-mark" class="size-4" />
                    </button>
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
    @fluxScripts
    @livewireScripts
</body>
</html>
