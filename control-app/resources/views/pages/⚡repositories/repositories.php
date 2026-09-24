<?php

use Livewire\Component;
use App\Models\RepositoryToken;
use App\Services\RepositoryLister;

new class extends Component {
    public $tokens;
    public $provider = 'github';
    public $username = '';
    public $token = '';
    public $expiresAt = '';
    public $showForm = false;

    public $editingId = null;

    public $reposByToken = [];

    public function mount()
    {
        $this->tokens = RepositoryToken::all();
        $this->loadAllRepos();
    }

    protected function loadAllRepos(): void
    {
        foreach ($this->tokens as $repoToken) {
            $this->loadReposFor($repoToken);
        }
    }

    public function loadReposFor(RepositoryToken $repoToken): void
    {
        try {
            $this->reposByToken[$repoToken->id] = ['repos' => (new RepositoryLister)->forToken($repoToken), 'error' => null];
        } catch (\Throwable $e) {
            $this->reposByToken[$repoToken->id] = ['repos' => [], 'error' => $e->getMessage()];
        }
    }

    public function refreshRepos($tokenId)
    {
        $repoToken = RepositoryToken::find($tokenId);
        if ($repoToken) {
            $this->loadReposFor($repoToken);
        }
    }

    public function startAdding()
    {
        $this->reset(['provider', 'username', 'token', 'expiresAt', 'editingId']);
        $this->provider = 'github';
        $this->showForm = true;
    }

    public function cancelForm()
    {
        $this->showForm = false;
    }

    public function edit($id)
    {
        $repoToken = RepositoryToken::find($id);
        if (!$repoToken) {
            return;
        }

        $this->editingId = $repoToken->id;
        $this->provider = $repoToken->provider;
        $this->username = $repoToken->username;
        $this->token = '';
        $this->expiresAt = $repoToken->expires_at?->format('Y-m-d') ?? '';
        $this->showForm = true;
    }

    public function save()
    {
        $rules = [
            'provider' => ['required', 'in:github,bitbucket'],
            'username' => ['required', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date'],
            'token' => $this->editingId ? ['nullable', 'string', 'max:1000'] : ['required', 'string', 'max:1000'],
        ];
        $this->validate($rules);

        $data = [
            'provider' => $this->provider,
            'username' => $this->username,
            'expires_at' => $this->expiresAt ?: null,
        ];
        if ($this->token !== '') {
            $data['token'] = $this->token;
        }

        if ($this->editingId) {
            $repoToken = RepositoryToken::find($this->editingId);
            $repoToken->update($data);
        } else {
            $repoToken = RepositoryToken::create($data);
        }

        $this->reset(['provider', 'username', 'token', 'expiresAt', 'showForm', 'editingId']);
        $this->provider = 'github';
        $this->tokens = RepositoryToken::all();
        $this->loadReposFor($repoToken->fresh());
    }

    public function delete($id)
    {
        RepositoryToken::whereKey($id)->delete();
        unset($this->reposByToken[$id]);
        $this->tokens = RepositoryToken::all();
    }
};
