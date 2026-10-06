<?php

declare(strict_types=1);

// This creates a fresh disposable loopback database and never reads production credentials.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || ! ctype_digit((string) getenv('DB_PORT')) || (int) getenv('DB_PORT') < 1024
    || (int) getenv('DB_PORT') > 65535 || getenv('DB_USERNAME') === false
    || getenv('DB_PORT') === '3306' || getenv('DB_URL')
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! preg_match('/\Avalzeria_lockcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))
    || ! in_array('--confirm-isolated-database', $argv, true)) {
    fwrite(STDERR, "Explicit testing/loopback/disposable database settings and confirmation required.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
requireCheck(! is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php'), 'Cached database configuration is not allowed');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = getenv('DB_CONNECTION');
$connectionConfig = config('database.connections.'.$driver);
requireCheck(config('database.default') === $driver && $connectionConfig['host'] === '127.0.0.1'
    && (string) $connectionConfig['port'] === getenv('DB_PORT')
    && $connectionConfig['database'] === getenv('DB_DATABASE')
    && $connectionConfig['username'] === getenv('DB_USERNAME')
    && (string) $connectionConfig['password'] === (string) getenv('DB_PASSWORD')
    && empty($connectionConfig['url']), 'Effective database configuration does not match isolated settings');
config(['cache.default' => 'array', 'session.driver' => 'array', 'nameless_relics.enabled' => false]);

use App\Models\Character;
use App\Services\OwnedConsumableService;
use Illuminate\Support\Facades\DB;

function requireCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
}

if (($argv[1] ?? '') === 'worker') {
    $mode = $argv[2];
    $characterId = (int) $argv[3];
    $db = DB::connection();
    $timeout = (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
    echo "READY\n";
    fflush(STDOUT);
    fgets(STDIN);
    $started = microtime(true);
    try {
        $consumed = $db->transaction(function () use ($db, $mode, $characterId): bool {
            Character::query()->whereKey($characterId)->lockForUpdate()->firstOrFail();
            $db->table('characters')->where('id', $characterId)->increment('money');
            $owned = app(OwnedConsumableService::class)->lockFirst($characterId, 7);
            if ($mode === 'empty-hold') {
                requireCheck($owned === null, 'Empty player should have no item');
                echo "EMPTY\n";
                fflush(STDOUT);
                fgets(STDIN);
            }
            if (! $owned) {
                // Do not count a request which consumed nothing.
                $db->table('characters')->where('id', $characterId)->decrement('money');

                return false;
            }
            $owned->delete();
            if ($mode === 'rollback') {
                throw new LogicException('Intentional post-consumption failure');
            }

            return true;
        });
        echo json_encode(['ok' => true, 'consumed' => $consumed, 'timeout' => $timeout, 'elapsed' => microtime(true) - $started]).PHP_EOL;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => $e->errorInfo[1] ?? null, 'exception' => $e::class,
            'transaction_level' => $db->transactionLevel(), 'timeout' => $timeout, 'elapsed' => microtime(true) - $started]).PHP_EOL;
    }
    exit;
}

// Refuse database reuse; never drop or truncate existing data.
$database = getenv('DB_DATABASE');
$pdo = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
requireCheck(str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'), 'MariaDB server required');
$pdo->exec('CREATE DATABASE `'.$database.'`');
$pdo->exec('USE `'.$database.'`');
$pdo->exec('SET SESSION innodb_lock_wait_timeout=3');
$pdo->exec('CREATE TABLE characters (id BIGINT UNSIGNED PRIMARY KEY, money INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('INSERT INTO characters(id) VALUES(1),(2),(3),(4),(5)');
$pdo->exec('CREATE TABLE character_items (id BIGINT UNSIGNED PRIMARY KEY, character_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL, is_equipped BOOLEAN NOT NULL DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, KEY character_index(character_id), KEY item_index(item_id)) ENGINE=InnoDB');
$pdo->exec('INSERT INTO character_items(id,character_id,item_id) VALUES(1,1,7),(2,2,7),(3,3,7),(5,5,7)');

function startWorker(string $mode, int $id): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $id, '--confirm-isolated-database'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    requireCheck(is_resource($process), 'Worker did not start');
    $worker = ['process' => $process, 'pipes' => $pipes];
    requireCheck(readWorkerLine($worker) === 'READY', 'Worker did not reach barrier');

    return $worker;
}

function readWorkerLine(array &$worker): string
{
    // Windows process pipes do not support nonblocking stream_get_contents.
    $line = fgets($worker['pipes'][1]);
    requireCheck($line !== false, 'Worker ended without a barrier/result');

    return trim($line);
}

function releaseWorker(array $worker): void
{
    fwrite($worker['pipes'][0], "GO\n");
    fflush($worker['pipes'][0]);
}

function finishWorker(array &$worker): array
{
    try {
        return json_decode(readWorkerLine($worker), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        foreach ($worker['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($worker['process']);
    }
}

$results = ['engine' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'driver' => $driver];
// A different player holding the same item must not stall this player's consumption.
$pdo->beginTransaction();
$pdo->query('SELECT id FROM character_items WHERE id=2 FOR UPDATE')->fetch();
try {
    $worker = startWorker('consume', 1);
    releaseWorker($worker);
    $results['distinct_player'] = finishWorker($worker);
} finally {
    $pdo->rollBack();
}
requireCheck($results['distinct_player']['consumed'] ?? false, 'Distinct player was blocked');

// Actual primary-key contention must time out, roll back all earlier writes and release the connection.
$pdo->beginTransaction();
$pdo->query('SELECT id FROM character_items WHERE id=2 FOR UPDATE')->fetch();
try {
    $worker = startWorker('consume', 2);
    releaseWorker($worker);
    $results['bounded_wait'] = finishWorker($worker);
} finally {
    $pdo->rollBack();
}
$wait = $results['bounded_wait'];
requireCheck(($wait['code'] ?? null) === 1205 && $wait['timeout'] === 3
    && $wait['elapsed'] < 6 && $wait['transaction_level'] === 0, 'Lock wait was not bounded and rolled back');
requireCheck((int) $pdo->query('SELECT money FROM characters WHERE id=2')->fetchColumn() === 0, 'Timed out request left writes');
requireCheck((int) $pdo->query('SELECT COUNT(*) FROM character_items WHERE id=2')->fetchColumn() === 1, 'Timed out request consumed item');

$workers = [startWorker('consume', 3), startWorker('consume', 3)];
foreach ($workers as $worker) {
    releaseWorker($worker);
}
$results['same_player'] = [];
foreach ($workers as &$worker) {
    $results['same_player'][] = finishWorker($worker);
}
unset($worker);
requireCheck(count(array_filter($results['same_player'], fn ($r) => $r['consumed'] ?? false)) === 1, 'Same player consumed twice');
requireCheck((int) $pdo->query('SELECT money FROM characters WHERE id=3')->fetchColumn() === 1, 'Same player wrote rewards twice');

$worker = startWorker('empty-hold', 4);
releaseWorker($worker);
requireCheck(readWorkerLine($worker) === 'EMPTY', 'Empty lookup failed');
$pdo->exec('INSERT INTO character_items(id,character_id,item_id) VALUES(4,2,7)');
releaseWorker($worker);
$results['empty_lookup_no_gap_lock'] = finishWorker($worker);
requireCheck($results['empty_lookup_no_gap_lock']['ok'], 'Empty lookup blocked another inventory insertion');

$worker = startWorker('rollback', 5);
releaseWorker($worker);
$results['post_delete_rollback'] = finishWorker($worker);
requireCheck(! $results['post_delete_rollback']['ok']
    && (int) $pdo->query('SELECT COUNT(*) FROM character_items WHERE id=5')->fetchColumn() === 1
    && (int) $pdo->query('SELECT money FROM characters WHERE id=5')->fetchColumn() === 0, 'Post-consumption failure did not roll back');

$connection = DB::connection();
$results['initial_timeout'] = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
DB::reconnect();
$results['reconnected_timeout'] = (int) DB::connection()->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
requireCheck($results['initial_timeout'] === 3 && $results['reconnected_timeout'] === 3, 'New/reconnected PDO lost timeout initialization');
$results['pass'] = true;
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),PHP_EOL;
