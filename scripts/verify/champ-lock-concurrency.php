<?php

// Only an already migrated, disposable local MariaDB on the dedicated test port.
// APP_ENV=testing DB_DATABASE=valzeria_champcheck_<unique> php scripts/verify/champ-lock-concurrency.php
declare(strict_types=1);

use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\ChampState;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\User;
use App\Services\ChampBattleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '13339' || getenv('DB_URL')
    || getenv('DB_USERNAME') !== 'root' || (string) getenv('DB_PASSWORD') !== ''
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! in_array('--confirm-isolated-database', $argv, true)
    || ! preg_match('/\Avalzeria_champcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Explicit testing/loopback/disposable database settings and confirmation required.\n");
    exit(2);
}
$database = getenv('DB_DATABASE');
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Cached database configuration is not allowed.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
});
config([
    'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'database.default' => getenv('DB_CONNECTION'),
    'database.connections.'.getenv('DB_CONNECTION') => [
        'driver' => getenv('DB_CONNECTION'), 'host' => '127.0.0.1', 'port' => 13339,
        'database' => $database, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ],
    'cache.default' => 'array', 'session.driver' => 'array',
    'queue.default' => 'sync', 'mail.default' => 'array',
    'gameplay_metrics.enabled' => false,
]);
$db = DB::connection();
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET SESSION innodb_lock_wait_timeout=7');
if (! str_contains($db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
    throw new RuntimeException('MariaDB required.');
}

if (($argv[1] ?? '') === 'worker') {
    $mode = $argv[2];
    if ($mode === 'challenger-wait') {
        $app->instance(\App\Services\ChampBattleTransactionRunner::class, new class extends \App\Services\ChampBattleTransactionRunner {
            protected function waitBeforeRetry(int $attempt): void
            {
                if ($attempt === 1) {
                    echo "READY\n";
                    fflush(STDOUT);
                    fgets(STDIN);
                }
                parent::waitBeforeRetry($attempt);
            }
        });
    }
    if ($mode === 'blocking-reference') {
        // Negative control: reproduce the released blocking reference lock.
        $app->instance(\App\Services\ChampBattleTransactionRunner::class, new class extends \App\Services\ChampBattleTransactionRunner {
            public function lock(\Illuminate\Database\Eloquent\Builder $query, bool $shared = false): \Illuminate\Database\Eloquent\Builder
            {
                return $shared ? $query->sharedLock() : $query->lockForUpdate();
            }

            protected function waitBeforeRetry(int $attempt): void
            {
                usleep($attempt * 50_000);
            }
        });
    }
    $character = Character::findOrFail((int) $argv[3]);
    $champ = ChampState::firstOrFail();
    $snapshots = 0;
    ChampState::retrieved(function () use (&$snapshots, $mode, $db): void {
        $snapshots++;
        if ($mode === 'legacy' && $snapshots === 1) {
            // Negative control: the previous implementation held this lock during calculation.
            $db->table('champ_states')->lockForUpdate()->first();
        }
    });
    $paused = false;
    if (in_array($mode, ['reference-wait', 'blocking-reference'], true)) {
        $db->beforeExecuting(function (string $sql) use (&$paused): void {
            if (! $paused && str_contains(strtolower($sql), 'lock in share mode')) {
                $paused = true;
                echo "READY\n";
                fflush(STDOUT);
            }
        });
    }
    $db->listen(function ($query) use ($mode, &$paused): void {
        if (in_array($mode, ['plain', 'reference-wait', 'blocking-reference', 'challenger-wait'], true) || $paused || ! str_contains($query->sql, '`character_job_art_slots`')) {
            return;
        }
        $paused = true;
        echo "READY\n";
        fflush(STDOUT);
        fgets(STDIN);
    });
    $started = microtime(true);
    try {
        $result = app(ChampBattleService::class)->executeChallenge($character, (int) $champ->character_id, $champ->appointed_at->getTimestamp());
        echo json_encode([
            'ok' => $result['ok'], 'message' => $result['message'] ?? null,
            'before' => $result['champ_hp_before'] ?? null, 'after' => $result['champ_hp_after'] ?? null,
            'snapshots' => $snapshots, 'seconds' => microtime(true) - $started,
            'timeout_restored' => (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS v')->v === 7,
            'transaction_level' => $db->transactionLevel(),
        ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    } catch (Throwable $exception) {
        echo json_encode(['exception' => $exception::class, 'message' => $exception->getMessage()]).PHP_EOL;
    }
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
    $key = bin2hex(random_bytes(8));

    return Character::create([
        'user_id' => User::factory()->create(['email' => 'champ-lock-'.$key.'@example.test'])->id,
        'name' => 'champ-lock-'.$key,
        'level' => 100, 'exp' => 0, 'hp_base' => 100, 'current_hp' => 100,
        'mp_base' => 0, 'current_mp' => 0, 'attack_base' => 10, 'defense_base' => 8,
        'speed_base' => 1000, 'magic_base' => 8, 'spirit_base' => 8, 'luck_base' => 5,
    ]);
}
function worker(Character $actor, string $mode = 'plain'): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $actor->id, '--confirm-isolated-database'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 15);
    if ($mode !== 'plain' && trim((string) fgets($pipes[1])) !== 'READY') {
        throw new RuntimeException('Worker failed to reach battle-calculation barrier: '.stream_get_contents($pipes[2]));
    }

    return [$process, $pipes, in_array($mode, ['reference-wait', 'blocking-reference'], true) ? 'plain' : $mode];
}
function finish(array $worker): array
{
    [$process, $pipes, $mode] = $worker;
    if ($mode !== 'plain') {
        fwrite($pipes[0], "GO\n");
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode(trim($output), true);
    if ($exit !== 0 || ! is_array($result) || isset($result['exception'])) {
        throw new RuntimeException('Worker failed: '.$output.$error);
    }
    check($result['timeout_restored'] && $result['transaction_level'] === 0, 'worker restores timeout and ends transaction');

    return $result;
}
function rewardsMatch(Character $actor): bool
{
    $logs = ChampBattleLog::where('challenger_character_id', $actor->id);

    return (int) CharacterMaterial::where('character_id', $actor->id)->sum('quantity') === (int) (clone $logs)->sum('material_quantity')
        && (int) $actor->fresh()->exp === (int) (clone $logs)->sum('exp_gained');
}
function unchanged(Character $actor): bool
{
    return $actor->fresh()->last_champ_battle_at === null
        && ChampBattleLog::where('challenger_character_id', $actor->id)->count() === 0
        && CharacterMaterial::where('character_id', $actor->id)->count() === 0
        && (int) $actor->fresh()->exp === 0;
}

$champ = ChampState::firstOrFail();
$champ->update([
    'character_id' => null, 'player_name' => 'Concurrency fixture', 'level' => 1,
    'current_hp' => 1000000, 'max_hp' => 1000000, 'atk' => 10000,
    'def' => 10000, 'spd' => 1, 'defense_count' => 0,
    'appointed_at' => now()->subDay(),
]);
$holder = new PDO('mysql:host=127.0.0.1;port=13339;dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy = worker(actor(), 'legacy');
try {
    $holder->beginTransaction();
    $holder->query('SELECT * FROM champ_states FOR UPDATE NOWAIT')->fetch();
    throw new RuntimeException('Legacy control did not hold the champ lock');
} catch (PDOException $exception) {
    check((int) ($exception->errorInfo[1] ?? 0) === 1205, 'legacy calculation lock reproduces immediate contention');
} finally {
    $holder->rollBack();
}
check(finish($legacy)['ok'], 'legacy control finishes after barrier');

$firstActor = actor();
$secondActor = actor();
$first = worker($firstActor, 'staged');
$second = worker($secondActor, 'staged');
$holder->beginTransaction();
$holder->query('SELECT * FROM champ_states FOR UPDATE NOWAIT')->fetch();
$holder->rollBack();
check(true, 'two actual battle calculations leave the shared champ row unlocked');
$beforeDefenses = (int) $champ->fresh()->defense_count;
$appointmentBefore = $champ->fresh()->getRawOriginal('appointed_at');
$firstResult = finish($first);
$secondResult = finish($second);
check($firstResult['ok'] && $secondResult['ok'], 'both overlapping challenges succeed');
check($secondResult['snapshots'] >= 4 && $secondResult['before'] === $firstResult['after'], 'stale HP is discarded and recalculated from latest committed champ');
check((int) $champ->fresh()->defense_count === $beforeDefenses + 2 && rewardsMatch($firstActor) && rewardsMatch($secondActor), 'damage defenses experience and materials commit once each');
check($champ->fresh()->getRawOriginal('appointed_at') === $appointmentBefore, 'defense preserves appointment even with MariaDB automatic TIMESTAMP updates');

$duplicateActor = actor();
$duplicates = [worker($duplicateActor), worker($duplicateActor)];
$duplicateResults = array_map('finish', $duplicates);
check(count(array_filter($duplicateResults, fn ($r) => $r['ok'])) === 1
    && ChampBattleLog::where('challenger_character_id', $duplicateActor->id)->count() === 1
    && rewardsMatch($duplicateActor), 'same-character concurrent submission rewards only once');

$changedActor = actor();
$changed = worker($changedActor, 'staged');
$champ->refresh()->update(['appointed_at' => $champ->appointed_at->addSecond()]);
$changedResult = finish($changed);
check(! $changedResult['ok'] && str_contains($changedResult['message'], '交代') && unchanged($changedActor), 'appointment change during battle returns stale-form feedback without rewards');

$incumbent = actor();
$champ->refresh()->update(['character_id' => $incumbent->id]);
$probe = new PDO('mysql:host=127.0.0.1;port=13339;dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// A successful calculation still holds the incumbent reference for FK safety.
$reference = worker(actor(), 'staged');
try {
    $holder->beginTransaction();
    $holder->query('SELECT id FROM characters WHERE id='.(int) $incumbent->id.' FOR UPDATE NOWAIT')->fetch();
    throw new RuntimeException('Incumbent reference was not protected during battle');
} catch (PDOException $exception) {
    check((int) ($exception->errorInfo[1] ?? 0) === 1205, 'incumbent reference remains locked through calculation');
} finally {
    $holder->rollBack();
}
check(finish($reference)['ok'], 'reference-protected battle finishes');

// A challenger lock refusal releases the previously acquired incumbent lock
// before backoff, so the incumbent's own play is not stalled by this request.
$busyChallenger = actor();
$holder->beginTransaction();
$holder->query('SELECT id FROM characters WHERE id='.(int) $busyChallenger->id.' FOR UPDATE')->fetch();
$released = worker($busyChallenger, 'challenger-wait');
$probe->beginTransaction();
$probe->query('SELECT id FROM characters WHERE id='.(int) $incumbent->id.' FOR UPDATE NOWAIT')->fetch();
$probe->query('SELECT id FROM champ_states FOR UPDATE NOWAIT')->fetch();
$probe->rollBack();
check(true, 'challenger contention releases incumbent and champ locks before backoff');
$holder->rollBack();
check(finish($released)['ok'] && rewardsMatch($busyChallenger), 'challenger retry commits once after busy operation finishes');

foreach (['blocking-reference', 'reference-wait'] as $mode) {
    $waitingActor = actor();
    $holder->beginTransaction();
    $holder->query('SELECT id FROM characters WHERE id='.(int) $incumbent->id.' FOR UPDATE')->fetch();
    $waiting = worker($waitingActor, $mode);
    $probe->beginTransaction();
    $probe->query('SELECT id FROM characters WHERE id='.(int) $waitingActor->id.' FOR UPDATE NOWAIT')->fetch();
    $probe->rollBack();
    check(true, 'incumbent wait does not lock the challenger: '.$mode);
    // Cross the previously deployed ~0.8 second NOWAIT retry window.
    usleep(1_200_000);
    $holder->rollBack();
    $waitingResult = finish($waiting);
    check($waitingResult['ok'] && rewardsMatch($waitingActor)
        && ChampBattleLog::where('challenger_character_id', $waitingActor->id)->count() === 1,
        'short incumbent lock commits one battle: '.$mode);
}
// Verify the actual MariaDB NOWAIT path against a persistently busy incumbent.
// The obsolete blocking control is covered above only with an explicit release.
foreach (['reference-wait'] as $mode) {
    $blockedActor = actor();
    $characterBefore = $blockedActor->fresh()->getRawOriginal();
    $champBefore = $champ->fresh()->getRawOriginal();
    $holder->beginTransaction();
    $holder->query('SELECT id FROM characters WHERE id='.(int) $incumbent->id.' FOR UPDATE')->fetch();
    $blocked = worker($blockedActor, $mode);
    $blockedResult = finish($blocked);
    $holder->rollBack();
    check(! $blockedResult['ok'] && $blockedResult['seconds'] < ($mode === 'reference-wait' ? 4 : 9)
        && unchanged($blockedActor) && $characterBefore === $blockedActor->fresh()->getRawOriginal()
        && $champBefore === $champ->fresh()->getRawOriginal(),
        'busy incumbent returns bounded feedback without consuming rewards or cooldown: '.$mode.' '.json_encode($blockedResult, JSON_UNESCAPED_UNICODE));
}

$incumbentBefore = $incumbent->fresh()->getRawOriginal();
$champ->refresh()->update(['current_hp' => 1, 'max_hp' => 1, 'def' => 0, 'spd' => 1]);
$victor = actor();
$victory = finish(worker($victor));
check($victory['ok'] && (int) $champ->fresh()->character_id === (int) $victor->id
    && ChampHistory::where('character_id', $incumbent->id)->where('defeated_by_character_id', $victor->id)->count() === 1
    && ChampBattleLog::where('challenger_character_id', $victor->id)->count() === 1
    && rewardsMatch($victor), 'real incumbent defeat commits history appointment and rewards once with FKs enabled');
check($incumbentBefore === $incumbent->fresh()->getRawOriginal(), 'challenge preserves incumbent gameplay state');

$champ->refresh()->update(['character_id' => null]);
foreach (['challenger', 'champ'] as $lockTarget) {
    $blockedActor = actor();
    $characterBefore = $blockedActor->fresh()->getRawOriginal();
    $champBefore = $champ->fresh()->getRawOriginal();
    $holder->beginTransaction();
    $sql = $lockTarget === 'challenger' ? 'SELECT id FROM characters WHERE id='.(int) $blockedActor->id.' FOR UPDATE' : 'SELECT id FROM champ_states FOR UPDATE';
    $holder->query($sql)->fetch();
    $blockedResult = finish(worker($blockedActor));
    $holder->rollBack();
    check(! $blockedResult['ok'] && $blockedResult['seconds'] < 2 && unchanged($blockedActor)
        && $characterBefore === $blockedActor->fresh()->getRawOriginal()
        && $champBefore === $champ->fresh()->getRawOriginal(),
        $lockTarget.' NOWAIT is bounded and leaves no partial battle: '.json_encode($blockedResult, JSON_UNESCAPED_UNICODE));
}
ChampState::query()->delete();
$initialActor = actor();
$initialResult = app(ChampBattleService::class)->executeChallenge($initialActor);
check($initialResult['ok'] && ChampState::count() === 1 && ChampBattleLog::where('challenger_character_id', $initialActor->id)->count() === 1, 'missing initial champ is recreated and challenge commits once');
echo "All champ concurrency checks passed.\n";
