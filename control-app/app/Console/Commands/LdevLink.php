<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Site;
use App\Services\NginxConfigGenerator;
use App\Services\SupervisorConfigGenerator;

class LdevLink extends Command
{
    protected $signature = 'ldev:link {name?} {path?}';
    protected $description = 'Link a directory (the current one, unless {path} is given) to a *.test domain';

    public function handle()
    {

        $path = $this->argument('path') ?? getcwd();
        $name = $this->argument('name') ?? basename($path);

        if (!Site::isValidName($name)) {
            $this->error("'$name' isn't a valid site name (lowercase alphanumeric/hyphens only) — pass one explicitly: ldev:link my-app");
            return 1;
        }

        $domain = $name . '.test';

        $documentRoot = is_dir($path . '/public') ? $path . '/public' : $path;

        $site = Site::updateOrCreate(
            ['name' => $name],
            [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'is_linked' => true,
            ]
        );
        $this->info("Linked $name to $domain");
        (new NginxConfigGenerator)->generate($site);
        (new SupervisorConfigGenerator)->generate($site);
        return 0;
    }
}
