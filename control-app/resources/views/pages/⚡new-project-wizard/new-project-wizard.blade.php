<div>
    <h1 class="text-2xl font-semibold mb-4">New Project</h1>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 max-w-xl space-y-4">
        @if($step === 1)
            <div>
                <label class="block text-sm font-medium mb-1">Project name</label>
                <input type="text" wire:model.live="name" placeholder="my-app"
                    class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Source</label>
                <select wire:model.live="source" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    <option value="scaffold">Start from a starter kit</option>
                    <option value="clone">Clone an existing repository</option>
                </select>
            </div>
            @if($source === 'clone')
                <div>
                    <label class="block text-sm font-medium mb-1">Connection type</label>
                    <select wire:model.live="repoProtocol" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                        <option value="ssh" @selected($repoProtocol === 'ssh')>SSH (recommended) — uses the SSH key already set up on this machine, no token needed</option>
                        <option value="https" @selected($repoProtocol === 'https')>HTTPS + token — needed for a private repository with no SSH key set up</option>
                    </select>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">SSH authenticates with a key already trusted on this machine — nothing else to set up. HTTPS needs a saved repository token below (or none at all, for a public repository).</p>
                    @if($repoProtocol === 'https')

                        <p class="text-xs text-yellow-700 dark:text-yellow-400 mt-1">Linux Dev doesn't keep your token in the project after cloning, so every future push from VS Code or the terminal will prompt you to log in again. Pick SSH above to avoid that.</p>
                    @endif

                    @if($repoProtocol === 'ssh')
                        <div class="mt-2 text-xs" wire:loading.remove wire:target="runSshDiagnosis,repoProtocol,source">
                            @if($sshDiagnosis === null)
                                <p class="text-gray-400 dark:text-gray-500">Checking SSH access…</p>
                            @elseif(!$sshDiagnosis['hasLocalKey'])
                                <div class="p-2 rounded border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 space-y-1">
                                    <p class="font-medium">No SSH key found on this machine.</p>
                                    <p>Generate one with <code class="font-mono">ssh-keygen -t ed25519</code>, then add the public key (<code class="font-mono">~/.ssh/id_ed25519.pub</code>) to your GitHub/Bitbucket account before cloning over SSH — or switch to HTTPS + token above instead.</p>
                                </div>
                            @else
                                <div class="space-y-1">
                                    @foreach($sshDiagnosis['hosts'] as $host => $result)
                                        <div class="flex items-center gap-1.5">
                                            <span class="w-2 h-2 rounded-full {{ $result['ok'] ? 'bg-green-500' : 'bg-red-500' }} shrink-0"></span>
                                            <span class="font-mono">{{ $host }}</span>
                                            <span class="text-gray-500 dark:text-gray-400">— {{ $result['ok'] ? 'SSH access works' : $result['message'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <flux:button size="xs" variant="filled" color="blue" icon="arrow-path" wire:click="runSshDiagnosis" class="mt-1">Re-test</flux:button>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2" wire:loading wire:target="runSshDiagnosis,repoProtocol,source">Testing SSH access to GitHub/Bitbucket…</p>
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Repository URL</label>
                    <input type="text" wire:model.live="repoUrl"
                        placeholder="{{ $repoProtocol === 'ssh' ? 'git@bitbucket.org:user/repo.git' : 'https://github.com/user/repo.git' }}"
                        class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600" />
                    @error('repoUrl') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">If the repository has an <span class="font-mono">ldev.json</span>, its PHP, Node, database and other settings are used instead of the choices below.</p>
                </div>
                @if($repoProtocol === 'https')
                    <div>
                        <label class="block text-sm font-medium mb-1">Using token <span class="text-gray-400 dark:text-gray-500 font-normal">(only needed for a private repository)</span></label>
                        <select wire:model="repositoryTokenId" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                            <option value="">None (public repository)</option>
                            @foreach($repositoryTokens as $repoToken)
                                <option value="{{ $repoToken->id }}">{{ $repoToken->username }} ({{ $repoToken->provider }})</option>
                            @endforeach
                        </select>
                        @if($repositoryTokens->isEmpty())
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No tokens saved yet — add one on the <a href="{{ route('repositories') }}" wire:navigate class="text-blue-500">Repositories</a> page, or switch to SSH above instead.</p>
                        @endif
                    </div>
                @endif
            @endif
            <div>
                <label class="block text-sm font-medium mb-1">PHP version</label>
                <select wire:model="phpVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'] as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Node.js version</label>
                <select wire:model="nodeVersion" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    @foreach(\App\Services\NodeVersionManager::KNOWN_VERSIONS as $version)
                        <option value="{{ $version }}">{{ $version }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Ignored if the project ends up with no package.json (a plain static site, or --db=none).</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Database</label>
                <select wire:model.live="dbType" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                    <option value="sqlite">SQLite</option>
                    <option value="mysql">MySQL</option>
                    <option value="pgsql">PostgreSQL</option>
                    <option value="none">None (plain static site, or a project that manages its own database)</option>
                </select>
                @if($dbType === 'none' && $source === 'scaffold')
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">A starter-kit scaffold still gets Laravel's own default SQLite setup — this just means Linux Dev won't also provision anything on top of it.</p>
                @elseif($dbType === 'mysql' && $source === 'clone')
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">If this is a WordPress project, its own wp-config.php gets wired up to this database automatically (WordPress doesn't read .env). WordPress only supports MySQL natively — PostgreSQL/SQLite won't work for it without a plugin.</p>
                @endif
            </div>
            @if(in_array($dbType, ['mysql', 'pgsql'], true))

                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2">
                        <input type="radio" wire:model.live="dbTarget" value="new" class="mt-1.5">
                        <span class="flex-1">
                            Create a new database
                            <input type="text" wire:model="dbNewName" placeholder="{{ str_replace('-', '_', $name ?: 'project-name') }}" @disabled($dbTarget !== 'new')
                                class="w-full mt-1 rounded px-3 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm disabled:opacity-50" />
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Leave blank to name it after the project.</span>
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
                                <select wire:model="dbExisting" @disabled($dbTarget !== 'existing')
                                    class="w-full mt-1 rounded px-3 py-1.5 border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono text-sm disabled:opacity-50">
                                    @foreach($existingDatabases as $database)
                                        <option value="{{ $database }}">{{ $database }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </span>
                    </label>
                </div>
            @endif
            <div class="flex justify-end">
                <flux:button variant="primary" icon:trailing="arrow-right" wire:click="$set('step', 2)" :disabled="! $name || ($source === 'clone' && ! $repoUrl)">Next</flux:button>
            </div>
        @elseif($step === 2)
            @if($source === 'scaffold')
                <div>
                    <label class="block text-sm font-medium mb-1">Starter kit</label>
                    <select wire:model="starter" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                        <option value="none">None</option>
                        <option value="breeze">Breeze</option>
                        <option value="jetstream">Jetstream</option>
                    </select>
                </div>
                @if($starter !== 'none')
                    <div>
                        <label class="block text-sm font-medium mb-1">Stack</label>
                        @if($starter === 'breeze')
                            <select wire:model="stack" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                <option value="blade">Blade</option>
                                <option value="livewire">Livewire</option>
                                <option value="react">React</option>
                                <option value="vue">Vue</option>
                            </select>
                        @else
                            <select wire:model="stack" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                <option value="livewire">Livewire</option>
                                <option value="inertia">Inertia</option>
                            </select>
                        @endif
                    </div>
                    @if($starter === 'breeze')
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="dark" /> Dark mode support
                    </label>
                    @endif
                    @if($starter === 'jetstream')
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="teams" /> Team support
                        </label>
                    @endif
                @endif
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="pest" /> Use Pest for testing (instead of PHPUnit)
                </label>

                <p class="text-xs text-gray-500 dark:text-gray-400">A local git repository is created automatically. Optionally also push it to a new remote:</p>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="createRepo" /> Also create a repository on GitHub/Bitbucket
                </label>
                @if($createRepo)
                    <div class="pl-6 space-y-3 border-l-2 border-gray-200 dark:border-gray-700">
                        <div>
                            <label class="block text-sm font-medium mb-1">Using token</label>
                            <select wire:model="createRepoTokenId" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
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
                            <label class="block text-sm font-medium mb-1">Visibility</label>
                            <select wire:model="createRepoVisibility" class="w-full rounded px-3 py-2 border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                <option value="private">Private</option>
                                <option value="public">Public</option>
                            </select>
                        </div>
                    </div>
                @endif
            @endif
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="s3" /> Local S3 storage (RustFS) for files/images
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="reverb" /> WebSockets (Laravel Reverb)
            </label>
            @if($createError)
                <div class="rounded border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-3">
                    <p class="text-sm text-red-700 dark:text-red-400 whitespace-pre-wrap">{{ $createError }}</p>
                </div>
            @endif
            <div class="flex justify-between items-center">
                <flux:button variant="ghost" icon="arrow-left" wire:click="$set('step', 1)" wire:loading.attr="disabled" wire:target="create">Back</flux:button>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400 dark:text-gray-500" wire:loading wire:target="create">
                        Creating project — this can take a minute…
                    </span>

                    <flux:button variant="primary" icon="rocket-launch" wire:click="create" wire:loading.attr="disabled" wire:target="create">Create project</flux:button>
                </div>
            </div>
        @endif
    </div>
</div>
