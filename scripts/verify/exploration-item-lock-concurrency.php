<?php

declare(strict_types=1);

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\ExplorationItemCarry;
use App\Models\ExplorationMap;
use App\Models\Item;
use App\Models\MapExplorationItemCarry;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\ExplorationItemService;
use App\Services\MapExplorationItemService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '13339' || getenv('DB_URL')
    || getenv('DB_USERNAME') !== 'root' || (string) getenv('DB_PASSWORD') !== ''
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! in_array('--confirm-isolated-database', $argv, true)
    || ! preg_match('/\Avalzeria_champcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Explicit disposable loopback database settings required.\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Cached config not allowed.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'gameplay_metrics.enabled' => false]);
$db = DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout=7');
if (! str_contains($db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
    throw new RuntimeException('MariaDB required.');
}

if (($argv[1] ?? '') === 'worker') {
    [$mode, $characterId, $itemId, $contextId, $inject] = array_slice($argv, 2, 5);
    $character = Character::findOrFail((int) $characterId);
    $item = Item::findOrFail((int) $itemId);
    $paused = $failed = false;
    $db->beforeExecuting(function (string $sql) use (&$paused, &$failed, $inject): void {
        if (! $paused && str_contains(strtolower($sql), 'for update')) {
            $paused = true;
            echo "READY\n";
            fflush(STDOUT);
        }
        if ($inject === 'rollback' && ! $failed && str_starts_with(strtolower($sql), 'update')
            && str_contains($sql, 'used_count')) {
            $failed = true;
            $cause = new PDOException('fixture timeout after HP and item changes');
            $cause->errorInfo = ['HY000', 1205, 'fixture'];
            throw new QueryException('mysql', $sql, [], $cause);
        }
    });
    $started = microtime(true);
    $result = $mode === 'map'
        ? app(MapExplorationItemService::class)->use($character, $item, (int) $contextId)
        : app(ExplorationItemService::class)->use($character, $item, (int) $contextId);
    echo json_encode($result + [
        'seconds' => microtime(true) - $started,
        'timeout_restored' => (int) $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS v')->v === 7,
        'transaction_level' => $db->transactionLevel(), 'injected' => $failed,
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit;
}

function check(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo "PASS: $label\n";
}
function worker(string $mode, Character $character, Item $item, int $context, string $inject = 'none'): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $character->id,
        (string) $item->id, (string) $context, $inject, '--confirm-isolated-database'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 15);
    if (trim((string) fgets($pipes[1])) !== 'READY') {
        throw new RuntimeException('Worker did not reach entry lock: '.stream_get_contents($pipes[2]));
    }

    return [$process, $pipes];
}
function finish(array $worker): array
{
    [$process, $pipes] = $worker;
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || ! is_array($result = json_decode(trim($output), true))) {
        throw new RuntimeException('Worker failed: '.$output.$error);
    }
    check($result['timeout_restored'] && $result['transaction_level'] === 0, 'timeout restored and transaction closed');

    return $result;
}

$area = Area::firstOrFail();
$item = Item::where('type', 'consumable')->where('name', '薬草')->firstOrFail();
$holder = new PDO('mysql:host=127.0.0.1;port=13339;dbname='.getenv('DB_DATABASE'), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['normal', 'map'] as $mode) {
    $character = Character::create([
        'user_id' => User::factory()->create()->id, 'name' => 'item-lock-fixture',
        'hp_base' => 100, 'current_hp' => 20, 'mp_base' => 0, 'current_mp' => 0,
    ]);
    $context = (int) $area->id;
    if ($mode === 'map') {
        $map = ExplorationMap::create([
            'uuid' => (string) Str::uuid(), 'owner_character_id' => $character->id,
            'source_area_id' => $area->id, 'source_drop_event_uuid' => (string) Str::uuid(),
            'seed_encrypted' => 'fixture', 'seed_hash' => hash('sha256', 'fixture'),
            'map_grade' => 'D', 'map_level' => 1, 'dungeon_type' => 'normal',
            'reward_profile' => 'fixture', 'exploration_limit' => 10, 'name' => 'fixture',
            'name_parts_json' => [], 'normal_monster_variants_json' => [],
        ]);
        $context = (int) TownMapRegistration::create([
            'map_id' => $map->id, 'town_id' => $area->city_id,
            'exploration_limit' => 10, 'remaining_explorations' => 10,
        ])->id;
        $carry = MapExplorationItemCarry::create([
            'character_id' => $character->id, 'item_id' => $item->id,
            'registration_id' => $context, 'carried_count' => 10, 'used_count' => 0,
        ]);
    } else {
        $carry = ExplorationItemCarry::create([
            'character_id' => $character->id, 'item_id' => $item->id,
            'area_id' => $context, 'carried_count' => 10, 'used_count' => 0,
        ]);
    }
    $addItem = fn () => CharacterItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'is_equipped' => false]);
    $count = fn () => CharacterItem::where('character_id', $character->id)->where('item_id', $item->id)->count();
    $addItem();
    $holder->beginTransaction();
    $holder->query('SELECT id FROM characters WHERE id='.(int) $character->id.' FOR UPDATE')->fetch();
    $short = worker($mode, $character, $item, $context);
    usleep(1_200_000);
    $holder->rollBack();
    $result = finish($short);
    check($result['success'] && (int) $carry->fresh()->used_count === 1 && $count() === 0
        && (int) $character->fresh()->current_hp > 20, $mode.' 1.2 second conflict commits recovery exactly once');

    $character->refresh()->update(['current_hp' => 20]);
    $addItem();
    $before = $character->fresh()->getRawOriginal();
    $holder->beginTransaction();
    $holder->query('SELECT id FROM characters WHERE id='.(int) $character->id.' FOR UPDATE')->fetch();
    $busy = finish(worker($mode, $character, $item, $context));
    $holder->rollBack();
    check(! $busy['success'] && $busy['seconds'] < 4 && $count() === 1
        && (int) $carry->fresh()->used_count === 1 && $before === $character->fresh()->getRawOriginal(),
        $mode.' persistent conflict returns bounded feedback and preserves all assets');

    $rolledBack = finish(worker($mode, $character, $item, $context, 'rollback'));
    check($rolledBack['success'] && $rolledBack['injected'] && $count() === 0
        && (int) $carry->fresh()->used_count === 2,
        $mode.' failure after recovery and deletion rolls back before a single successful retry');

    $character->refresh()->update(['current_hp' => 20]);
    $addItem();
    $first = worker($mode, $character, $item, $context);
    $second = worker($mode, $character, $item, $context);
    $results = [finish($first), finish($second)];
    check(count(array_filter($results, fn ($r) => $r['success'])) === 1 && $count() === 0
        && (int) $carry->fresh()->used_count === 3, $mode.' overlapping requests cannot consume the same owned item twice');
}
echo "All exploration item concurrency checks passed.\n";
