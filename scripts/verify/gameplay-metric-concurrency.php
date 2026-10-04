<?php

// APP_ENV=testing DB_DATABASE=valzeria_metricscheck_<unique> php scripts/verify/gameplay-metric-concurrency.php
// Requires a migrated disposable MariaDB at 127.0.0.1:13330; never uses configured production credentials.
declare(strict_types=1);

use App\Models\Character;
use App\Models\User;
use App\Services\GameplayMetricService;
use App\Services\LegacyGameplayMetricService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (getenv('APP_ENV') !== 'testing' || ! preg_match('/\Avalzeria_metricscheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Requires APP_ENV=testing and DB_DATABASE=valzeria_metricscheck_<unique>.\n");
    exit(2);
}
$database = getenv('DB_DATABASE');
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
config([
    'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'database.default' => 'mysql',
    'database.connections.mysql' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13330,
        'database' => $database, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ],
    'cache.default' => 'array', 'session.driver' => 'array',
    'queue.default' => 'sync', 'mail.default' => 'array',
]);
$db = DB::connection();
Carbon::setTestNow('2026-10-04 12:30:00');
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET SESSION innodb_lock_wait_timeout=7');
if (! str_contains($db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
    throw new RuntimeException('MariaDB required.');
}
// Optional negative control: a local renamed copy of the original service, never deployed.
$legacy = in_array('--legacy', $argv, true);
if ($legacy) {
    require dirname(__DIR__, 2).'/scratch/LegacyGameplayMetricService.php';
}
$service = $legacy ? app(LegacyGameplayMetricService::class) : app(GameplayMetricService::class);

function battle(int $first, int $skill): array
{
    return ['result' => 'victory', 'turn_count' => 2, 'character_level_at_start' => 48,
        'job_art_loadout' => [['skill_id' => $first], ['skill_id' => 3 - $first]],
        'job_art_usage' => [['skill_id' => $skill, 'activation_count' => 1]],
        'job_art_activation_attempts' => [['skill_id' => $skill, 'effective_rate' => 50, 'activation_roll' => 25]],
    ];
}
function ready(): void
{
    echo "READY\n";
    fflush(STDOUT);
}
if (($argv[1] ?? '') === 'worker') {
    $mode = $argv[2];
    $actor = Character::findOrFail((int) $argv[3]);
    $context = $argv[4];
    $first = (int) ($argv[5] ?? 1);
    $warnings = [];
    Log::listen(function (MessageLogged $event) use (&$warnings): void {
        if (str_contains($event->message, '[GameplayMetrics]')) {
            $warnings[] = $event->context;
        }
    });
    $inserts = 0;
    $rollups = 0;
    $db->beforeExecuting(function ($sql) use ($mode, &$inserts, &$rollups): void {
        if (str_starts_with($sql, 'insert into `gameplay_metrics`')) {
            $inserts++;
        }
        $target = $mode === 'deadlock' ? 'gameplay_job_art_skill_rollups' : 'gameplay_job_art_rollups';
        if ($mode !== 'batch' && str_starts_with($sql, 'insert into `'.$target.'`')) {
            $rollups++;
            if ($rollups === 1) {
                ready();
            } elseif ($mode === 'transient' && $rollups === 2) {
                echo "RETRY\n";
                fflush(STDOUT);
                fgets(STDIN);
            }
        }
    });
    $started = microtime(true);
    $failure = null;
    try {
        $db->transaction(function () use ($actor, $mode, $service, $context, $first): void {
            $actor->increment('exp', 17);
            $service->recordJobArtBattle($actor, $context, battle($first, $mode === 'deadlock' ? 1 : $first));
            if ($mode === 'batch') {
                ready();
                fgets(STDIN);
                $service->recordJobArtBattle($actor, $context, battle($first, 3 - $first));
            }
        });
    } catch (Throwable $exception) {
        $failure = $exception::class;
    }
    echo json_encode([
        'failure' => $failure, 'warnings' => $warnings, 'inserts' => $inserts,
        'exp' => (int) $actor->fresh()->exp, 'seconds' => microtime(true) - $started,
        'transaction_level' => $db->transactionLevel(),
        'timeout_restored' => (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS v')->v === 7,
    ]).PHP_EOL;
    exit;
}

function check(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo "PASS: $label\n";
}
function actor(): Character
{
    return Character::create(['user_id' => User::factory()->create(['role' => 'user'])->id,
        'name' => 'metric-'.bin2hex(random_bytes(4)), 'current_hp' => 100, 'current_mp' => 10, 'exp' => 0]);
}
function worker(Character $actor, string $mode, string $context, int $first = 1): array
{
    global $legacy;
    $command = [PHP_BINARY, __FILE__, 'worker', $mode, (string) $actor->id, $context, (string) $first];
    if ($legacy) {
        $command[] = '--legacy';
    }
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 20);
    if (trim((string) fgets($pipes[1])) !== 'READY') {
        throw new RuntimeException('Worker did not reach barrier: '.stream_get_contents($pipes[2]));
    }

    return [$process, $pipes];
}
function resume(array $worker): void
{
    fwrite($worker[1][0], "GO\n");
    fflush($worker[1][0]);
}
function finish(array $worker): array
{
    [$process, $pipes] = $worker;
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode(trim($output), true);
    if ($exit !== 0 || ! is_array($result)) {
        throw new RuntimeException('Worker failed: '.$output.$errors);
    }

    return $result;
}
function counts(string $context): array
{
    return [
        DB::table('gameplay_metrics')->where('context', $context)->count(),
        (int) DB::table('gameplay_job_art_rollups')->where('context', $context)->sum('battles'),
        (int) DB::table('gameplay_job_art_skill_rollups')->where('context', $context)->sum('activations'),
        (int) DB::table('gameplay_job_art_activation_rollups')->where('context', $context)->sum('attempts'),
    ];
}
function assertCommitted(array $result): void
{
    check($result['failure'] === null && $result['exp'] === 17, 'gameplay reward committed exactly once');
    check($result['timeout_restored'] && $result['transaction_level'] === 0, 'timeout restored and transaction closed');
}

$context = 'race-'.bin2hex(random_bytes(4));
$one = worker(actor(), 'batch', $context, 1);
$two = worker(actor(), 'batch', $context, 2);
resume($one);
resume($two);
$results = [finish($one), finish($two)];
if ($legacy) {
    echo json_encode(['negative_control' => $results, 'counts' => counts($context)]).PHP_EOL;
    check(counts($context) !== [4, 4, 4, 4], 'original service loses metrics under opposite skill locks');
    exit;
}
foreach ($results as $result) {
    assertCommitted($result);
    check($result['warnings'] === [], 'parallel batch has no metric failures');
}
check(counts($context) === [4, 4, 4, 4], 'opposite skill orders retain every raw and aggregate count');

$holder = new PDO('mysql:host=127.0.0.1;port=13330;dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->exec('SET SESSION innodb_lock_wait_timeout=7');
foreach (['transient', 'persistent', 'deadlock'] as $mode) {
    $context = $mode.'-'.bin2hex(random_bytes(4));
    $seed = actor();
    $service->recordJobArtBattle($seed, $context, battle(1, 1));
    $target = $mode === 'deadlock' ? 'gameplay_job_art_skill_rollups' : 'gameplay_job_art_rollups';
    $rowId = DB::table($target)->where('context', $context)->value('id');
    if ($mode === 'deadlock') {
        // Make the holder heavier so InnoDB deterministically chooses the metric writer as victim.
        $holder->exec('CREATE TABLE IF NOT EXISTS metric_lock_weights (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        for ($i = 1; $i <= 20; $i++) {
            $holder->exec("INSERT IGNORE INTO metric_lock_weights VALUES ($i, 0)");
        }
    }
    $contender = actor();
    $holder->beginTransaction();
    try {
        if ($mode === 'deadlock') {
            $holder->exec('UPDATE metric_lock_weights SET value=value+1');
        }
        $holder->query("SELECT id FROM $target WHERE id=".(int) $rowId.' FOR UPDATE')->fetch();
        $job = worker($contender, $mode, $context, $mode === 'deadlock' ? 2 : 1);
        if ($mode === 'transient') {
            $retryLine = trim((string) fgets($job[1][1]));
            check($retryLine === 'RETRY', 'real lock wait timeout reached a second attempt: '.$retryLine);
            $holder->commit();
            resume($job);
        } elseif ($mode === 'deadlock') {
            // The raw metric FK owns a shared character lock while waiting on our skill row.
            $holder->query('SELECT id FROM characters WHERE id='.(int) $contender->id.' FOR UPDATE')->fetch();
            $holder->commit();
        }
        $result = finish($job);
    } finally {
        if ($holder->inTransaction()) {
            $holder->rollBack();
        }
    }
    assertCommitted($result);
    if ($mode === 'persistent') {
        check(counts($context) === [1, 1, 1, 1], 'exhausted retries leave no partial metric');
        check(count($result['warnings']) === 1 && $result['warnings'][0]['database_error_code'] === 1205, 'persistent contention logs sanitized code and phase');
        check($result['seconds'] < 5, 'persistent lock wait is bounded');
    } else {
        check($result['warnings'] === [] && $result['inserts'] >= 2, "$mode retries metric only");
        check(counts($context) === [2, 2, 2, 2], "$mode preserves exact totals after rollback and retry");
    }
}
