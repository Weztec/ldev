<div wire:poll.15s.visible="poll">
    <h1 class="text-2xl font-semibold mb-4">Services</h1>

    @php
        $displayName = fn ($service) => match ($service) {
            'valkey' => 'Valkey (Redis)',
            default => str_starts_with($service, 'ldev-') ? substr($service, strlen('ldev-')) : $service,
        };
        $human = fn ($bytes) => \App\Services\SystemInfo::humanBytes($bytes);
        $uptime = $system['uptime'] ?? null;
        $uptimeLabel = $uptime === null ? 'unknown' : (intdiv($uptime, 86400) > 0 ? intdiv($uptime, 86400) . 'd ' : '') . intdiv($uptime % 86400, 3600) . 'h ' . intdiv($uptime % 3600, 60) . 'm';
        $memory = $system['memory'] ?? null;
        $load = $system['load'] ?? [];
        $cpus = $system['cpus'] ?? null;
        $certs = $storage['certs'] ?? [];
        $nearestCert = $certs[0] ?? null;
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1.5 text-sm">
            <h2 class="font-medium mb-2">System</h2>
            <p><span class="text-gray-500 dark:text-gray-400">OS:</span> {{ $system['os'] ?? 'unknown' }}</p>
            <p><span class="text-gray-500 dark:text-gray-400">Kernel:</span> <span class="font-mono">{{ $system['kernel'] ?? 'unknown' }}</span></p>
            <p><span class="text-gray-500 dark:text-gray-400">Uptime:</span> {{ $uptimeLabel }}</p>
            @php
                $recentLoad = $load[0] ?? null;
                $loadRatio = ($cpus && $recentLoad !== null) ? $recentLoad / $cpus : null;
                [$loadLabel, $loadClass] = match (true) {
                    $loadRatio === null => [null, ''],
                    $loadRatio >= 1 => ['Overloaded: more work waiting than CPUs to run it', 'text-red-600 dark:text-red-400'],
                    $loadRatio >= 0.7 => ['Busy', 'text-yellow-700 dark:text-yellow-400'],
                    default => ['Quiet', 'text-green-600 dark:text-green-400'],
                };
            @endphp
            <div title="Load average: how many processes were running or waiting for a CPU, averaged over the last 1, 5 and 15 minutes. Compare it with the number of CPUs: below that number the machine keeps up, above it work is queuing.">
                <p>
                    <span class="text-gray-500 dark:text-gray-400">Load average:</span>
                    @if($loadLabel)<span class="{{ $loadClass }}">{{ $loadLabel }}</span>@endif
                    @if($cpus)<span class="text-xs text-gray-400 dark:text-gray-500">({{ $cpus }} CPUs)</span>@endif
                </p>
                <p class="flex flex-wrap gap-x-4 font-mono text-xs">
                    @foreach(['1 min' => $load[0] ?? null, '5 min' => $load[1] ?? null, '15 min' => $load[2] ?? null] as $window => $value)
                        <span><span class="font-sans text-gray-500 dark:text-gray-400">{{ $window }}</span> {{ $value ?? '?' }}</span>
                    @endforeach
                </p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3 text-sm">
            <h2 class="font-medium">Memory &amp; disk</h2>
            @if($memory)
                <div>
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">Memory</span><span>{{ $human($memory['used']) }} / {{ $human($memory['total']) }}</span></div>
                    <div class="h-1.5 rounded bg-gray-200 dark:bg-gray-700 mt-1"><div class="h-1.5 rounded {{ $memory['usedPercent'] > 90 ? 'bg-red-500' : 'bg-blue-500' }}" style="width: {{ $memory['usedPercent'] }}%"></div></div>
                </div>
            @endif
            @foreach($system['disks'] ?? [] as $disk)
                @php $usedPercent = 100 - $disk['freePercent'];@endphp
                <div>
                    <div class="flex justify-between"><span class="text-gray-500 dark:text-gray-400">{{ $disk['label'] }} disk</span><span>{{ $human($disk['free']) }} free of {{ $human($disk['total']) }}</span></div>
                    <div class="h-1.5 rounded bg-gray-200 dark:bg-gray-700 mt-1"><div class="h-1.5 rounded {{ $disk['freePercent'] < 10 ? 'bg-red-500' : 'bg-blue-500' }}" style="width: {{ $usedPercent }}%"></div></div>
                </div>
            @endforeach
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-1.5 text-sm">
            <div class="flex justify-between items-center mb-2">
                <h2 class="font-medium">Databases &amp; certificates</h2>
                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshStorage">Refresh</flux:button>
            </div>
            @foreach($storage['databases'] ?? [] as $engine => $bytes)
                <p><span class="text-gray-500 dark:text-gray-400">{{ $engine }} data:</span> {{ $human($bytes) }}</p>
            @endforeach
            @if($nearestCert)
                <p>
                    <span class="text-gray-500 dark:text-gray-400">Next certificate expiry:</span>
                    <span class="{{ $nearestCert['daysLeft'] <= 7 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $nearestCert['name'] }} in {{ $nearestCert['daysLeft'] }} days</span>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ count($certs) }} certificate(s), renewed automatically 30 days before expiry.</p>
            @else
                <p class="text-gray-500 dark:text-gray-400">No certificates found.</p>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-gray-700">
        @foreach($services as $service => $isActive)
        @php $state = $states[$service] ?? ($isActive ? 'active' : 'inactive');@endphp
        <div class="flex items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
                <span class="inline-block w-2 h-2 rounded-full {{ $isActive ? 'bg-green-500' : ($state === 'failed' ? 'bg-red-500' : 'bg-gray-400') }}"></span>
                <span class="font-medium">{{ $displayName($service) }}</span>
                @if($versions[$service] ?? null)
                    <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $versions[$service] }}</span>
                @endif
                @if($state === 'failed')
                    <span class="text-xs text-red-600 dark:text-red-400">failed &mdash; check the Logs page</span>
                @endif
            </div>
            <flux:switch :checked="$isActive" wire:click="toggle('{{ $service }}')" />
        </div>
        @endforeach
    </div>
    <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Status refreshes every 15 seconds while this page is open.</p>
</div>
