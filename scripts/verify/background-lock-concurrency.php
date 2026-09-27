<?php

// Requires a disposable, already migrated local MariaDB database. Never production.
declare(strict_types=1);

if (getenv('APP_ENV') !== 'testing' || ! preg_match('/\Avalzeria_lockcheck_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "APP_ENV=testing and DB_DATABASE=valzeria_lockcheck_<unique> are required.\n");
    exit(2);
}
$database = getenv('DB_DATABASE');
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, 'FAIL: '.$exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
config([
    'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'database.default' => 'mysql',
    'database.connections.mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13328, 'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
    'cache.default' => 'array', 'session.driver' => 'array',
    'security_anomaly_detection.rules.inventory_growth.equipment_threshold' => 100000,
]);
Illuminate\Support\Carbon::setTestNow('2030-01-10 09:00:00');
Carbon\CarbonImmutable::setTestNow('2030-01-10 09:00:00');
use Illuminate\Support\Facades\DB;
use App\Models\Character;
use App\Models\User;

function check(bool $ok, string $label): void {
    if (! $ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$db = DB::connection();
check(str_contains($db->selectOne('SELECT VERSION() AS v')->v, '10.5.26-MariaDB'), 'MariaDB 10.5.26');
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_28_010000_preserve_map_history_after_account_deletion.php';
$migration->down();
$migration->up();
check(true, 'history migration down/up on non-anonymized local database');
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET SESSION innodb_lock_wait_timeout=1');
$actors = [];
foreach (['busy', 'available'] as $label) {
    $actors[] = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'lock-check-'.$label, 'money' => 1000, 'explore_stamina' => 250, 'last_seen_at' => now()->subMinutes(5)]);
}
[$busy, $available] = $actors;
$itemId = DB::table('items')->where('type','weapon')->value('id') ?? DB::table('items')->insertGetId(['name'=>'lock-check-weapon','type'=>'weapon','created_at'=>now(),'updated_at'=>now()]);
foreach ($actors as $actor) DB::table('character_items')->insert(['character_id'=>$actor->id,'item_id'=>$itemId,'created_at'=>now(),'updated_at'=>now()]);
$holder = new PDO('mysql:host=127.0.0.1;port=13328;dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->beginTransaction();
$holder->query('SELECT id FROM characters WHERE id='.(int)$busy->id.' FOR UPDATE')->fetch();
try {
    // Negative control: the previous whole-table upsert waits on a busy parent FK.
    try {
        DB::table('security_inventory_snapshots')->upsert(array_map(fn ($actor) => ['character_id'=>$actor->id,'equipment_count'=>1,'material_quantity'=>0,'captured_at'=>now(),'created_at'=>now(),'updated_at'=>now()], $actors), ['character_id'], ['equipment_count','material_quantity','captured_at','updated_at']);
        throw new RuntimeException('Old bulk upsert unexpectedly succeeded');
    } catch (Illuminate\Database\QueryException $exception) {
        check((int)($exception->errorInfo[1]??0) === 1205, 'old bulk upsert reproduces FK lock timeout');
    }
    $before = $busy->last_seen_at;
    $started = microtime(true);
    app(App\Services\CharacterPresenceService::class)->touch($busy);
    app(App\Services\CharacterPresenceService::class)->touch($available);
    check(microtime(true)-$started < 1, 'presence never waits behind busy player');
    check($busy->fresh()->last_seen_at->equalTo($before), 'busy presence unchanged');
    check($available->fresh()->last_seen_at->greaterThan($before), 'available presence updated');
    $service = app(App\Services\Admin\SecurityAnomalyDetectionService::class);
    $result = ['created'=>0,'updated'=>0,'rules'=>[],'skipped'=>false];
    $scan = new ReflectionMethod($service, 'detectInventoryGrowth');
    $started=microtime(true);$scan->invokeArgs($service, [&$result]);
    check(microtime(true)-$started < 1, 'inventory scan does not wait on busy player');
    check(!DB::table('security_inventory_snapshots')->where('character_id',$busy->id)->exists(), 'busy baseline deferred');
    check(DB::table('security_inventory_snapshots')->where('character_id',$available->id)->exists(), 'other player baseline saved');
} finally { $holder->rollBack(); }
$scan->invokeArgs($service, [&$result]);
check(DB::table('security_inventory_snapshots')->where('character_id',$busy->id)->exists(), 'deferred baseline saved on next scan');

// Actual raid admission must release its global coordinator immediately and
// must not create a battle/cost record when another connection owns Character.
config(['features.nation_competitive_raid_enabled'=>true, 'features.nation_community_enabled'=>true, 'features.nation_development_enabled'=>true, 'features.nation_war_enabled'=>false]);
foreach (['dynamic_single','hit_resolution','damage_application','resources'] as $flag) config(['battle.job_art_v2.'.$flag=>true]);
$events=app(App\Services\Nation\Raid\NationRaidEventService::class);
$event=App\Models\NationRaidEvent::where('event_key','like','lock-check-%')->whereIn('status',['scheduled','active'])->first();
if (!$event) {
    $event=$events->createDraft('lock-check-'.bin2hex(random_bytes(6)), 'lock-check raid', now());
    $event=$events->approveBalance($event, User::factory()->create(['role'=>'admin']), 'local test only');
    $event=$events->schedule($event, now()->subHours(72));
}
if ($event->status==='scheduled') $event=$events->activate($event);
$token=bin2hex(random_bytes(32));
$holder->beginTransaction();$holder->query('SELECT id FROM characters WHERE id='.(int)$busy->id.' FOR UPDATE')->fetch();
try {
    $started=microtime(true);
    try {
        app(App\Services\Nation\Raid\NationRaidSortieService::class)->start($event,$busy,'boss_set',$token);
        throw new RuntimeException('Busy raid unexpectedly admitted');
    } catch (DomainException $exception) {
        check(str_contains($exception->getMessage(),'ほかの操作を処理中'), 'raid returns explicit busy message');
    }
    check(microtime(true)-$started < 1, 'raid does not hold coordinator waiting for character');
    check(!DB::table('nation_raid_battle_results')->where('battle_token',$token)->exists(), 'busy raid creates no battle');
    check((int)$busy->fresh()->explore_stamina===250, 'busy raid consumes no stamina');
    $holder->query('SELECT * FROM competition_event_coordinators FOR UPDATE NOWAIT')->fetchAll();
    check(true, 'raid coordinator released on busy admission');
} finally { $holder->rollBack(); }

// Actual result controller: inject one deadlock into its read-only recovery query.
Illuminate\Support\Facades\Auth::setUser($busy->user);
session(['current_character_id'=>$busy->id]);
$reads=0;$inject=true;
DB::listen(function ($query) use (&$reads,&$inject) {
    if (str_contains($query->sql,'`characters`') && str_contains(strtolower($query->sql),'for update')) {
        $reads++;
        if ($inject) { $inject=false;$error=new PDOException('Deadlock found when trying to get lock');$error->errorInfo=['40001',1213,'injected'];throw new Illuminate\Database\QueryException('mysql',$query->sql,[],$error); }
    }
});
$request=Illuminate\Http\Request::create('/battle/result','GET',['result_id'=>(string)Illuminate\Support\Str::uuid()]);
$constant=(new ReflectionClass(App\Http\Controllers\BattleController::class))->getConstant('BATTLE_RESULT_QUERY_KEY');
$request->query->replace([$constant=>(string)Illuminate\Support\Str::uuid()]);
app(App\Http\Controllers\BattleController::class)->showResult($request);
check($reads===2, 'result controller retries one deadlock without executing battle');
check((int)$busy->fresh()->money===1000, 'result read does not grant rewards');
check(DB::transactionLevel()===0, 'all transactions released');
