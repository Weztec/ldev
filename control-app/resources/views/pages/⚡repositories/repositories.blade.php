<div>
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-2xl font-semibold">Repositories</h1>
        <flux:button size="sm" variant="primary" icon="{{ $showForm ? 'x-mark' : 'plus' }}" wire:click="{{ $showForm ? 'cancelForm' : 'startAdding' }}">{{ $showForm ? 'Cancel' : 'Add token' }}</flux:button>
    </div>

    @php
        $expired = $tokens->filter(fn ($t) => $t->isExpired());
        $expiringSoon = $tokens->filter(fn ($t) => $t->expiresSoon());
@endphp
    @if($expired->isNotEmpty() || $expiringSoon->isNotEmpty())
        <div class="mb-4 rounded-lg px-4 py-3 text-sm {{ $expired->isNotEmpty() ? 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300' : 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300' }}">
            @foreach($expired as $t)
                <p>⚠ {{ ucfirst($t->provider) }} token for {{ $t->username }} expired {{ $t->expires_at->format('Y-m-d') }} — generate a new one and click "Renew" below.</p>
            @endforeach
            @foreach($expiringSoon as $t)
                <p>⚠ {{ ucfirst($t->provider) }} token for {{ $t->username }} expires {{ $t->expires_at->format('Y-m-d') }} ({{ $t->daysUntilExpiry() }} days) — worth renewing soon.</p>
            @endforeach
        </div>
    @endif

    @if($showForm)
        <form wire:submit="save" class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mb-6 max-w-lg space-y-3">
            @if($editingId)
                <p class="text-sm font-medium">Renewing token</p>
            @endif
            <div>
                <label class="block text-sm font-medium mb-1">Provider</label>
                <select wire:model="provider" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    <option value="github">GitHub</option>
                    <option value="bitbucket">Bitbucket</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Username</label>
                <input type="text" wire:model="username" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                @error('username') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Personal access token{{ $editingId ? ' (leave blank to keep the current one)' : '' }}</label>
                <input type="password" wire:model="token" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                @error('token') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Stored encrypted; used to list your repositories below, clone a private one, or push a newly created project to a new repository.</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Expires on <span class="text-gray-400 dark:text-gray-500 font-normal">(optional — matches whatever expiry you set when generating the token)</span></label>
                <input type="date" wire:model="expiresAt" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                @error('expiresAt') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Neither GitHub nor Bitbucket lets a token renew itself — this just warns you here before an old one silently stops working.</p>
            </div>
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="check">{{ $editingId ? 'Save' : 'Save token' }}</flux:button>
            </div>
        </form>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
        @if($tokens->isEmpty())
            <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No repository tokens saved yet.</p>
        @else
            <table class="w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm font-medium w-32">Provider</th>
                        <th class="px-4 py-2 text-left text-sm font-medium w-1/4">Username</th>
                        <th class="px-4 py-2 text-left text-sm font-medium w-56">Expires</th>
                        <th class="px-4 py-2 text-left text-sm font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($tokens as $repoToken)
                        <tr>
                            <td class="px-4 py-2 text-sm capitalize truncate">{{ $repoToken->provider }}</td>
                            <td class="px-4 py-2 text-sm truncate">{{ $repoToken->username }}</td>
                            <td class="px-4 py-2 text-sm truncate">
                                @if(!$repoToken->expires_at)
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @elseif($repoToken->isExpired())
                                    <span class="text-red-600 dark:text-red-400 font-medium">Expired {{ $repoToken->expires_at->format('Y-m-d') }}</span>
                                @elseif($repoToken->expiresSoon())
                                    <span class="text-yellow-600 dark:text-yellow-400 font-medium">Expires {{ $repoToken->expires_at->format('Y-m-d') }} ({{ $repoToken->daysUntilExpiry() }}d)</span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">{{ $repoToken->expires_at->format('Y-m-d') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <flux:button size="sm" variant="filled" color="blue" icon="pencil" wire:click="edit({{ $repoToken->id }})">{{ $repoToken->isExpired() ? 'Renew' : 'Edit' }}</flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $repoToken->id }})" wire:confirm="Remove this token?">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @foreach($tokens as $repoToken)
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto mt-6">
            <div class="flex justify-between items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-medium text-sm">
                    <span class="capitalize">{{ $repoToken->provider }}</span> repositories — {{ $repoToken->username }}
                </h2>
                <flux:button size="sm" variant="filled" color="blue" icon="arrow-path" wire:click="refreshRepos({{ $repoToken->id }})" wire:loading.attr="disabled" wire:target="refreshRepos({{ $repoToken->id }})">Refresh</flux:button>
            </div>

            @php $entry = $reposByToken[$repoToken->id] ?? ['repos' => [], 'error' => null];@endphp

            @if($entry['error'])
                <p class="px-4 py-3 text-sm text-red-600 dark:text-red-400">Could not list repositories: {{ $entry['error'] }}</p>
            @elseif(empty($entry['repos']))
                <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No repositories found.</p>
            @else
                <table class="w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($entry['repos'] as $repo)
                            <tr>
                                <td class="px-4 py-2 text-sm truncate">{{ $repo['name'] }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400 w-24">{{ $repo['private'] ? 'Private' : 'Public' }}</td>
                                <td class="px-4 py-2 text-right w-32">

                                    <a href="{{ route('new-project', ['clone_url' => $repo['clone_url_ssh'] ?? $repo['clone_url'], 'token_id' => $repoToken->id]) }}" wire:navigate>
                                        <flux:button size="sm" variant="filled" color="green" icon="document-duplicate">Clone</flux:button>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</div>
