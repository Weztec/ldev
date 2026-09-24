<div>
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-semibold">Databases</h1>
        <div class="flex gap-2">
            <a href="/adminer.php" target="_blank"><flux:button size="sm" variant="primary" icon="arrow-top-right-on-square">Open Adminer</flux:button></a>
            <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshDatabases">Refresh</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-medium">MariaDB (127.0.0.1:3306)</h2>
                <a href="/adminer.php?server=127.0.0.1&username=root" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="arrow-top-right-on-square">Open in Adminer</flux:button></a>
            </div>
            <div class="px-4 py-2 flex items-center gap-2 text-sm border-b border-gray-200 dark:border-gray-700">
                <span class="inline-block w-2 h-2 rounded-full {{ $mysqlError ? 'bg-red-500' : 'bg-green-500' }}"></span>
                <span class="{{ $mysqlError ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $mysqlError ? 'Not reachable' : 'Connected' }}
                </span>
            </div>
            @if($mysqlError)
                <p class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ $mysqlError }}</p>
            @elseif(empty($mysqlDatabases))
                <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No databases found.</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($mysqlDatabases as $name)
                        <li>

                            <a href="/adminer.php?server=127.0.0.1&username=root&db={{ urlencode($name) }}" target="_blank"
                                class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-blue-600 dark:hover:text-blue-400">
                                {{ $name }}
                                <flux:icon name="arrow-top-right-on-square" class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-medium">PostgreSQL (127.0.0.1:5432)</h2>
                <a href="/adminer.php?pgsql=127.0.0.1&username=postgres" target="_blank"><flux:button size="sm" variant="filled" color="blue" icon="arrow-top-right-on-square">Open in Adminer</flux:button></a>
            </div>
            <div class="px-4 py-2 flex items-center gap-2 text-sm border-b border-gray-200 dark:border-gray-700">
                <span class="inline-block w-2 h-2 rounded-full {{ $pgsqlError ? 'bg-red-500' : 'bg-green-500' }}"></span>
                <span class="{{ $pgsqlError ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $pgsqlError ? 'Not reachable' : 'Connected' }}
                </span>
            </div>
            @if($pgsqlError)
                <p class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ $pgsqlError }}</p>
            @elseif(empty($pgsqlDatabases))
                <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No databases found.</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($pgsqlDatabases as $name)
                        <li>
                            <a href="/adminer.php?pgsql=127.0.0.1&username=postgres&db={{ urlencode($name) }}" target="_blank"
                                class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-blue-600 dark:hover:text-blue-400">
                                {{ $name }}
                                <flux:icon name="arrow-top-right-on-square" class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
