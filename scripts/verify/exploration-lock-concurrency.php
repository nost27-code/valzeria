<?php

// Only a disposable database on the dedicated local test port is accepted.
// APP_ENV=testing DB_DATABASE=valzeria_lockcheck_<unique> php scripts/verify/exploration-lock-concurrency.php
declare(strict_types=1);

if (getenv('APP_ENV') !== 'testing' || ! preg_match('/\Avalzeria_lockcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Set APP_ENV=testing and a new DB_DATABASE=valzeria_lockcheck_<unique>.\n");
    exit(2);
}

$database = getenv('DB_DATABASE');
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

if (($argv[1] ?? '') === 'worker') {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'database.default' => 'mysql',
        'database.connections.mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13327,
            'database' => $database, 'username' => 'root', 'password' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ],
        'cache.default' => 'database', 'cache.prefix' => 'lock-check-', 'session.driver' => 'array',
    ]);
    $db = Illuminate\Support\Facades\DB::connection();
    $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->statement('SET SESSION innodb_lock_wait_timeout=3');
    $mode = $argv[2];
    try {
        if ($mode === 'cache') {
            $db->beginTransaction();
            $value = app(App\Services\GameSettingService::class)->getInt('test.value', 0);
            $db->rollBack();
            echo json_encode(['ok' => $value === 7, 'value' => $value]).PHP_EOL;
            exit;
        }
        $id = (int) $argv[3];
        $token = sprintf('00000000-0000-4000-8000-%012d', $id);
        $actor = App\Models\Character::findOrFail($id);
        $request = Illuminate\Http\Request::create('/test-explore', 'POST', ['exploration_request_id' => $token]);
        $request->setUserResolver(fn () => new class($actor) {
            public function __construct(private $actor) {}
            public function currentCharacter() { return $this->actor; }
        });
        $request->setLaravelSession(app('session')->driver());
        $request->session()->start();
        if ($mode !== 'same-character') {
            $waiting = false;
            $db->listen(function ($query) use (&$waiting, $db, $id, $token, $mode) {
                if ($waiting || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, '`exploration_requests`')) return;
                $waiting = true;
                if ($mode === 'gap-lock-control') {
                    // Recreate the removed missing-token locking read as the negative control.
                    $db->table('exploration_requests')->where('character_id', $id)->where('token', $token)->lockForUpdate()->first();
                }
                echo "READY\n";
                fflush(STDOUT);
                fgets(STDIN);
            });
        }
        $response = (new App\Http\Middleware\CommitExplorationRequest)->handle($request, function () use ($db, $id) {
            $db->table('characters')->where('id', $id)->increment('money', 10);
            usleep(100000);
            return redirect('/saved-result/'.$id);
        });
        echo json_encode(['ok' => true, 'redirect' => $response->getTargetUrl()]).PHP_EOL;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e::class, 'code' => $e->errorInfo[1] ?? $e->getCode()]).PHP_EOL;
    }
    exit;
}

$pdo = new PDO('mysql:host=127.0.0.1;port=13327', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'`'); // Refuses reuse; never drops existing data.
$pdo->exec('USE `'.$database.'`');
$pdo->exec('CREATE TABLE characters (id BIGINT UNSIGNED PRIMARY KEY, money INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('INSERT INTO characters(id) VALUES(1),(2),(3),(4),(5)');
$pdo->exec('CREATE TABLE exploration_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, character_id BIGINT UNSIGNED NOT NULL, token CHAR(36) NOT NULL, request_hash VARCHAR(64) NOT NULL, redirect_url TEXT NOT NULL, battle_data LONGTEXT NULL, created_at TIMESTAMP NULL, UNIQUE KEY operation(character_id,token), KEY created(created_at)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE game_settings(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100), value VARCHAR(100), value_type VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO game_settings(setting_key,value,value_type) VALUES('test.value','7','int')");
$pdo->exec('CREATE TABLE cache (`key` VARCHAR(255) PRIMARY KEY, value MEDIUMTEXT, expiration INT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO cache VALUES('lock-check-game_settings.all','a:0:{}',1)");

function worker(string $mode, int $id = 0): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $id], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    return [$process, $pipes];
}
function finish(array $worker): array {
    [$process, $pipes] = $worker;
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    return json_decode(trim($output), true) ?? ['ok' => false, 'exit' => $exit, 'stderr' => $error, 'output' => $output];
}
function pair(string $mode, int $first, int $second): array {
    $workers = [worker($mode, $first), worker($mode, $second)];
    if ($mode !== 'same-character') {
        foreach ($workers as [, $pipes]) {
            if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker did not reach token barrier.');
        }
        foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
    }
    return array_map('finish', $workers);
}

$results = ['engine' => $pdo->query('SELECT VERSION()')->fetchColumn()];
$results['gap_lock_control'] = pair('gap-lock-control', 1, 2);
$results['distinct_characters'] = pair('fixed', 3, 4);
$results['same_character_replay'] = pair('same-character', 5, 5);
$results['money'] = $pdo->query('SELECT id,money FROM characters ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->beginTransaction();
$pdo->query("SELECT * FROM cache WHERE `key`='lock-check-game_settings.all' FOR UPDATE")->fetchAll();
$results['settings_under_locked_expired_cache'] = finish(worker('cache'));
$pdo->rollBack();
$results['pass'] = count(array_filter($results['gap_lock_control'], fn ($r) => ($r['code'] ?? null) === 1213)) === 1
    && ! in_array(false, array_column($results['distinct_characters'], 'ok'), true)
    && ! in_array(false, array_column($results['same_character_replay'], 'ok'), true)
    && (int) $results['money'][3] === 10 && (int) $results['money'][4] === 10 && (int) $results['money'][5] === 10
    && $results['settings_under_locked_expired_cache']['ok'];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($results['pass'] ? 0 : 1);
