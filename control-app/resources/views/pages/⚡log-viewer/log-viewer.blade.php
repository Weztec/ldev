<div>
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-semibold">Logs</h1>
        <div class="flex gap-2">
            <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshLog">Refresh</flux:button>
            @if($selected)
                <flux:button size="sm" variant="danger" icon="trash" wire:click="clearLog" wire:confirm="Clear this log file? This can't be undone.">Clear log</flux:button>
            @endif
            @if(!empty($files))
                <flux:button size="sm" variant="danger" icon="trash" wire:click="clearAllLogs" wire:confirm="Clear every log listed here ({{ count($files) }} files)? This can't be undone.">Clear all logs</flux:button>
            @endif
        </div>
    </div>

    <div class="flex gap-4 items-start">
        <div class="w-64 bg-white dark:bg-gray-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-gray-700 shrink-0 max-h-[70vh] overflow-y-auto">
            @forelse($groups as $groupName => $group)
                <div>
                    <div class="px-3 py-1.5 flex items-center justify-between gap-2 bg-gray-50 dark:bg-gray-700/50">
                        <span class="text-xs font-medium uppercase tracking-wide truncate {{ $group['orphaned'] ?? false ? 'text-yellow-700 dark:text-yellow-400' : 'text-gray-500 dark:text-gray-400' }}"
                            title="{{ ($group['orphaned'] ?? false) ? 'No site named \''.$groupName.'\' exists anymore — leftover from a deleted project' : $groupName }}">
                            {{ $groupName }}
                        </span>
                        @if($group['orphaned'] ?? false)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeOrphanedGroup('{{ $groupName }}')"
                                wire:confirm="Remove these {{ count($group['files']) }} leftover log file(s) for '{{ $groupName }}'? This can't be undone.">
                            </flux:button>
                        @endif
                    </div>
                    @foreach($group['files'] as $path => $label)
                        @php $size = \Illuminate\Support\Facades\File::exists($path) ? \Illuminate\Support\Facades\File::size($path) : 0;@endphp
                        <div class="flex items-center gap-1 pl-5 pr-2 py-1 {{ $selected === $path ? 'bg-gray-100 dark:bg-gray-700' : '' }}">
                            <span class="inline-block w-1.5 h-1.5 rounded-full shrink-0 {{ $size > 0 ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600' }}"
                                title="{{ $size > 0 ? 'Has content (' . number_format($size / 1024, 1) . ' KB)' : 'Empty' }}"></span>
                            <button wire:click="selectLog('{{ $path }}')" title="{{ $label }}"
                                class="flex-1 text-left py-1.5 text-sm truncate {{ $selected === $path ? 'font-medium' : '' }}">
                                {{ $label }}
                            </button>
                            @if($size > 0)
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="clearSpecificLog('{{ $path }}')"
                                    wire:confirm="Clear this log file? This can't be undone." class="shrink-0" title="Clear this log — keeps the file, empties its content">
                                </flux:button>
                            @endif

                            @unless($group['protected'] ?? false)
                                <flux:button size="sm" variant="danger" icon="x-circle" wire:click="deleteSpecificLog('{{ $path }}')"
                                    wire:confirm="Delete this log file completely? Nothing will force it to come back — this is for watching whether/when the process that writes it recreates it on its own." class="shrink-0" title="Delete this log file entirely">
                                </flux:button>
                            @endunless
                        </div>
                    @endforeach
                    @if($group['truncated'] ?? 0)
                        <div class="pl-5 pr-3 py-1 text-xs text-gray-400 dark:text-gray-500" title="Newest-first — the oldest {{ $group['truncated'] }} weren't recent enough to show here">
                            +{{ $group['truncated'] }} more not shown
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No logs yet.</div>
            @endforelse
        </div>

        <div class="flex-1 bg-gray-900 text-gray-100 rounded-lg shadow p-4 overflow-x-auto max-h-[70vh] overflow-y-auto">
            @forelse($lines as $line)
                <div class="text-xs font-mono whitespace-pre-wrap">{{ $line }}</div>
            @empty
                <div class="text-xs text-gray-500">Nothing to show yet.</div>
            @endforelse
        </div>
    </div>
</div>
