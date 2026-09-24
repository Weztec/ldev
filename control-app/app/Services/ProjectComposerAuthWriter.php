<?php

namespace App\Services;

use App\Models\ComposerCredential;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ProjectComposerAuthWriter
{
    public function path(Site $site): string
    {
        return $site->projectRoot() . '/auth.json';
    }

    public function trackedByGit(Site $site): bool
    {
        $root = $site->projectRoot();
        if (!File::isDirectory($root . '/.git')) {
            return false;
        }

        return Process::path($root)->run('git ls-files --error-unmatch auth.json')->successful();
    }

    public function ensureIgnored(Site $site): ?string
    {
        $root = $site->projectRoot();
        if (!File::isDirectory($root . '/.git')) {
            return null;
        }

        if (Process::path($root)->run('git check-ignore -q auth.json')->successful()) {
            return null;
        }

        $exclude = $root . '/.git/info/exclude';
        File::ensureDirectoryExists(dirname($exclude));
        $current = File::exists($exclude) ? File::get($exclude) : '';
        File::put($exclude, rtrim($current, "\n") . ($current === '' ? '' : "\n") . "/auth.json\n");

        return 'auth.json was not git-ignored in this project, so it has been added to .git/info/exclude (this machine only; the project\'s .gitignore is unchanged).';
    }

    public function sync(Site $site, array $removeHosts = []): ?string
    {
        if ($this->trackedByGit($site)) {
            throw new \RuntimeException('This project\'s auth.json is tracked by git, so credentials written to it would be committed. Remove it from git first (git rm --cached auth.json), then save again.');
        }

        $path = $this->path($site);
        $credentials = ComposerCredential::forSite($site)->get();
        $auth = File::exists($path) ? json_decode(File::get($path), true) : [];
        if (!is_array($auth)) {
            throw new \RuntimeException('The project\'s auth.json is not valid JSON, so it was left alone. Fix or delete it, then save again.');
        }

        if ($credentials->isEmpty() && !File::exists($path)) {
            return null;
        }

        $note = $this->ensureIgnored($site);
        $writer = new ComposerAuthWriter;

        $httpBasic = array_diff_key(is_array($auth['http-basic'] ?? null) ? $auth['http-basic'] : [], array_flip($removeHosts));
        foreach ($credentials as $credential) {
            $secret = $writer->secret($credential);
            if ($secret !== null) {
                $httpBasic[$credential->host] = ['username' => $credential->username, 'password' => $secret];
            }
        }
        ksort($httpBasic);
        $auth['http-basic'] = $httpBasic ?: new \stdClass();

        $json = json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp-' . getmypid();
        File::put($tmp, $json . "\n");
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the project\'s auth.json.');
        }

        return $note;
    }
}
