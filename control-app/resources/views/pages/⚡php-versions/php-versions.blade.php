<div>
    <h1 class="text-2xl font-semibold mb-4">PHP Versions</h1>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-gray-700">
        @foreach($versions as $version => $isActive)
        <div class="flex items-center justify-between px-4 py-3">
            <div class="flex items-center gap-3">
                <span class="inline-block w-2 h-2 rounded-full {{ $isActive ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                <span class="font-medium">PHP {{ $version }}</span>
            </div>
            <flux:switch :checked="$isActive" wire:click="toggle('{{ $version }}')" />
        </div>
        @endforeach
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mt-6 space-y-3">
        <h2 class="font-medium">Install a new version</h2>

        @if(!$installerAvailable)
            <p class="text-xs text-yellow-700 dark:text-yellow-400">
                Needs a re-run of <code class="font-mono">sudo ./setup-environment.sh</code> to install the helper script this feature relies on.
            </p>
        @else
            <div class="flex items-center gap-2">
                <input type="text" wire:model="newVersion" placeholder="8.6"
                    class="w-24 rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm" />
                <flux:button size="sm" variant="primary" icon="arrow-down-tray" wire:click="installVersion" wire:loading.attr="disabled" wire:target="installVersion">
                    Install
                </flux:button>
                <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="installVersion">Installing — this can take 10-30 seconds…</span>
            </div>
            @if($installNote)
                <p class="text-xs text-green-600 dark:text-green-400">{{ $installNote }}</p>
            @endif
            @if($installError)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $installError }}</p>
            @endif
        @endif
    </div>
</div>
