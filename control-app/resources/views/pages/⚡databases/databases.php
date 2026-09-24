<?php

use Livewire\Component;
use Illuminate\Support\Facades\Process;

new class extends Component {
    public $mysqlDatabases = [];
    public $pgsqlDatabases = [];
    public $mysqlError = null;
    public $pgsqlError = null;

    public function mount()
    {
        $this->refreshDatabases();
    }

    public function refreshDatabases()
    {

        $mysql = Process::run('mariadb -h 127.0.0.1 -u root -N -e ' . escapeshellarg('SHOW DATABASES'));
        if ($mysql->successful()) {
            $this->mysqlError = null;
            $builtIn = ['information_schema', 'mysql', 'performance_schema', 'sys'];
            $this->mysqlDatabases = collect(explode("\n", trim($mysql->output())))
                ->filter(fn ($name) => $name !== '' && !in_array($name, $builtIn, true))
                ->values()
                ->all();
        } else {
            $this->mysqlDatabases = [];
            $this->mysqlError = 'Could not reach MariaDB (mysql -h 127.0.0.1 -u root) — is it running?';
        }

        $pgsql = Process::env(['PATH' => getenv('PATH')])->run('psql -h 127.0.0.1 -U postgres -tAc ' . escapeshellarg('SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname'));
        if ($pgsql->successful()) {
            $this->pgsqlError = null;

            $builtIn = ['postgres'];
            $this->pgsqlDatabases = collect(explode("\n", trim($pgsql->output())))
                ->filter(fn ($name) => $name !== '' && !in_array($name, $builtIn, true))
                ->values()
                ->all();
        } else {
            $this->pgsqlDatabases = [];
            $this->pgsqlError = 'Could not reach PostgreSQL (psql -h 127.0.0.1 -U postgres) — is it running?';
        }
    }
};
