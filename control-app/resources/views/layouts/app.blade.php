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
