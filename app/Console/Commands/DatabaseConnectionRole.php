<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DatabaseConnectionRole extends Command
{
    protected $signature = 'db:connection-role {--probe : Verify the active account in a read-only transaction}';

    protected $description = 'Inspect the process database role without exposing credentials';

    public function handle(): int
    {
        $name = config('database.default');
        $connection = config('database.connections.'.$name);
        $result = ['connection' => $name, 'worker_enabled' => (bool) config('database.worker.enabled'),
            'username' => $connection['username'] ?? null, 'database' => $connection['database'] ?? null];
        if ($this->option('probe')) {
            $db = DB::connection();
            $db->statement('SET SESSION TRANSACTION READ ONLY');
            $db->beginTransaction();
            try {
                $actual = $db->selectOne('SELECT CURRENT_USER() AS account, DATABASE() AS db, CONNECTION_ID() AS id');
                $limit = $db->selectOne("SHOW VARIABLES LIKE 'max_user_connections'");
                $result['actual'] = (array) $actual;
                $result['max_user_connections'] = $limit->Value;
            } finally {
                $db->rollBack();
                DB::disconnect();
            }
        }
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
