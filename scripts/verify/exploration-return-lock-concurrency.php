<?php
// Disposable loopback MariaDB only. Inherit the isolated bootstrap for every worker.
declare(strict_types=1);

use App\Models\{Area, Character, CharacterExplorationState, PlayerValmon, PlayerValmonEgg, PublicLog, User, ValmonMaster};
use App\Services\{ExplorationReturnTransactionRunner, ExplorationStateService};
use Illuminate\Support\Facades\{DB, Auth};
use Illuminate\Database\QueryException;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '13339'
    || getenv('DB_URL') || getenv('DB_USERNAME') !== 'root' || (string) getenv('DB_PASSWORD') !== ''
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! preg_match('/\Avalzeria_champcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))
    || ! in_array('--confirm-isolated-database', $argv, true)) {
    fwrite(STDERR, "Explicit disposable loopback database settings required.\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) throw new RuntimeException('Cached config not allowed.');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, 'FAIL: '.$e->getMessage().PHP_EOL); exit(1); });
config(['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'gameplay_metrics.enabled' => false]);
$db = DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout=7');
if (! str_contains($db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) throw new RuntimeException('MariaDB required.');
function check(bool $ok, string $label): void { if (! $ok) throw new RuntimeException($label); echo "PASS: $label\n"; }

if (($argv[1] ?? '') === 'worker') {
    $mode = $argv[2]; $hero = Character::findOrFail((int) $argv[3]);
    Auth::login($hero->user);
    session(['current_character_id' => $hero->id, 'current_location' => 'dungeon', 'lastBattleData' => ['fixture' => 1], 'exploration_selected_count.'.$hero->id => 50]);
    $attempts = 0;
    if (in_array($mode, ['late-once', 'late-always'], true)) {
        $db->beforeExecuting(function (string $sql) use (&$attempts, $mode) {
            if (str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'character_exploration_states')) {
                $attempts++;
                if ($mode === 'late-always' || $attempts === 1) {
                    $cause = new PDOException('fixture late lock failure'); $cause->errorInfo = ['HY000', 1205, 'fixture'];
                    throw new QueryException('mysql', 'fixture', [], $cause);
                }
            }
        });
    }
    if ($mode === 'short') {
        $app->instance(ExplorationReturnTransactionRunner::class, new class extends ExplorationReturnTransactionRunner {
            protected function waitBeforeRetry(int $attempt): void {
                if ($attempt === 1) { echo "READY\n"; fflush(STDOUT); fgets(STDIN); }
                parent::waitBeforeRetry($attempt);
            }
        });
    }
    $started = hrtime(true);
    $response = app(App\Http\Controllers\BattleController::class)->returnToTown(Illuminate\Http\Request::create('/battle/return', 'POST'));
    echo json_encode(['ok' => str_contains($response->getTargetUrl(), 'skip_resume=1'), 'location' => session('current_location'),
        'battle_preserved' => session('lastBattleData') === ['fixture' => 1], 'count' => session('exploration_selected_count.'.$hero->id),
        'elapsed_ms' => (int) ((hrtime(true)-$started)/1e6), 'attempts' => $attempts,
        'level' => $db->transactionLevel(), 'timeout' => (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS v')->v]).PHP_EOL;
    exit;
}

foreach (['long', 'short', 'late-once', 'late-always'] as $mode) {
    $key = bin2hex(random_bytes(6));
    $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'return-'.$key, 'money' => 999]);
    $state = app(ExplorationStateService::class)->getOrStart($hero, Area::firstOrFail()->id)->fresh();
    $stateBefore = $state->getRawOriginal();
    $master = ValmonMaster::create(['valmon_key' => 'return-'.$key, 'name' => 'Return fixture', 'rarity' => 'normal', 'is_active' => true]);
    $egg = PlayerValmonEgg::create(['character_id' => $hero->id, 'valmon_master_id' => $master->id, 'found_at' => now()]);
    if ($mode === 'long' || $mode === 'short') { $db->beginTransaction(); Character::whereKey($hero->id)->lockForUpdate()->first(); }
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $hero->id, '--confirm-isolated-database'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    stream_set_timeout($pipes[1], 20);
    try {
        if ($mode === 'short') {
            check(trim((string) fgets($pipes[1])) === 'READY', 'return reaches retry only after initial lock fails');
            $db->rollBack(); fwrite($pipes[0], "released\n"); fflush($pipes[0]);
        }
        $result = json_decode(trim((string) stream_get_contents($pipes[1])), true);
        $error = stream_get_contents($pipes[2]);
    } finally {
        foreach ($pipes as $pipe) fclose($pipe);
        $exit = proc_close($process);
        if ($db->transactionLevel() > 0) $db->rollBack();
    }
    check($exit === 0 && is_array($result), 'return worker completes: '.$mode.' '.$error);
    check($result['level'] === 0 && $result['timeout'] === 7, 'return restores timeout and closes transaction: '.$mode);
    $success = $mode === 'short' || $mode === 'late-once';
    check($result['ok'] === $success, 'return outcome: '.$mode);
    check((int) $hero->fresh()->money === 999, 'Gold unchanged: '.$mode);
    check(PlayerValmon::where('character_id', $hero->id)->count() === ($success ? 1 : 0), 'hatch exactly once or fully rolled back: '.$mode);
    check((bool) $egg->fresh()->is_hatched === $success, 'egg state is atomic: '.$mode);
    check(PublicLog::where('character_id', $hero->id)->where('type', 'valmon')->count() === ($success ? 1 : 0), 'hatch announcement is atomic: '.$mode);
    if (! $success) {
        check($stateBefore === $state->fresh()->getRawOriginal(), 'failed return preserves exploration: '.$mode);
        check($result['location'] === 'dungeon' && $result['battle_preserved'] && $result['count'] === 50, 'failed return preserves session: '.$mode);
        check($result['elapsed_ms'] < 4500, 'busy return is bounded: '.$mode);
    } else {
        check($result['location'] === 'town' && ! $result['battle_preserved'] && $result['count'] === null, 'successful return clears session only after commit: '.$mode);
        check($state->fresh()->area_id === null, 'successful return resets exploration: '.$mode);
    }
}
