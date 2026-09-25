<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\DumpStore;
use Illuminate\Console\Command;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Server\DumpServer;

class LdevDumpServer extends Command
{
    protected $signature = 'ldev:dump-server';

    protected $description = 'Collect dump() and dd() output from projects that send it here, for the dashboard';

    public function handle(): int
    {
        $server = new DumpServer(config('ldev.dump_server'));
        $server->start();
        $this->info('Listening on ' . config('ldev.dump_server'));

        $store = new DumpStore;
        $dumper = new CliDumper;
        $dumper->setColors(false);
        $roots = [];
        $loadedAt = 0;

        $server->listen(function (Data $data, array $context) use ($store, $dumper, &$roots, &$loadedAt) {
            if (time() - $loadedAt > 30) {
                $roots = Site::all()
                    ->mapWithKeys(fn (Site $site) => [$site->name => rtrim($site->projectRoot(), '/') . '/'])
                    ->sortByDesc(fn ($root) => strlen($root))
                    ->all();
                $loadedAt = time();
            }

            $file = (string) ($context['source']['file'] ?? '');
            $siteName = collect($roots)->search(fn ($root) => str_starts_with($file, $root));
            if ($siteName === false) {
                return;
            }

            $evaluated = str_contains($file, "eval()'d code");

            $store->append($siteName, [
                'time' => $context['timestamp'] ?? microtime(true),
                'label' => $context['label'] ?? null,
                'file' => $evaluated ? 'tinker' : substr($file, strlen($roots[$siteName])),
                'line' => $evaluated ? null : ($context['source']['line'] ?? null),
                'request' => isset($context['request']['uri']) ? trim(($context['request']['method'] ?? '') . ' ' . $context['request']['uri']) : null,
                'command' => $context['cli']['command_line'] ?? null,
                'text' => mb_substr((string) $dumper->dump($data, true), 0, 20000),
            ]);
        });

        return 0;
    }
}
