<?php

use App\Models\Character;
use App\Models\GoldTransaction;
use App\Models\SecurityAnomalyCase;
use App\Models\User;
use App\Services\Admin\SecurityAnomalyDetectionService;
use App\Services\Admin\SecurityLoginRetentionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


    $connection = DB::connection();
    if (! app()->environment('testing') || $connection->getDatabaseName() !== 'valzeria_nameless_ruin_rewards_ci'
        || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
        || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('This verifier requires the isolated reward CI database.');
    }
    config(['cache.default' => 'array', 'security_anomaly_detection.rules.gold_change.total_threshold' => 100,
        'security_anomaly_detection.rules.gold_change.single_threshold' => 100]);
    $clone = $connection->getConfig();
    config(['database.connections.retention_lock_holder' => $clone]);
    $holder = DB::connection('retention_lock_holder');
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $assertions = 0;
    $check = function (bool $condition, string $message) use (&$assertions): void {
        $assertions++;
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $user = User::factory()->create(['role' => 'user']);
    $character = Character::query()->create(['user_id' => $user->id, 'name' => 'Retention CI fixture']);
    $row = fn (string $key, $at): array => ['user_id' => $user->id, 'ip_hash' => hash('sha256', $key), 'masked_ip' => 'fixture',
        'observed_date' => $at->toDateString(), 'first_observed_at' => $at, 'last_observed_at' => $at,
        'observation_count' => 1, 'created_at' => now(), 'updated_at' => now()];
    $expired = DB::table('security_login_observations')->insertGetId($row('expired', now()->subDays(91)));
    $active = DB::table('security_login_observations')->insertGetId($row('active', now()));
    $holder->beginTransaction();
    try {
        $holder->table('security_login_observations')->where('id', $active)->lockForUpdate()->first();
        $oldCode = null;
        $connection->beginTransaction();
        try {
            $connection->table('security_login_observations')->where('last_observed_at', '<', now()->subDays(90))->delete();
        } catch (QueryException $exception) {
            $oldCode = (int) ($exception->errorInfo[1] ?? 0);
        } finally {
            $connection->rollBack();
        }
        $check($oldCode === 1205, 'The old range DELETE must reproduce a lock wait against the active row.');
        $check(DB::table('security_login_observations')->where('id', $expired)->exists(), 'Old failure must not remove expired history.');
        $start = hrtime(true);
        $result = app(SecurityLoginRetentionService::class)->prune();
        $check($result === ['pruned' => 1, 'deferred' => 0], 'New pruning must progress despite an unrelated active row lock.');
        $check((hrtime(true) - $start) / 1e9 < .8, 'New pruning must not wait on the unrelated active row.');
        $check(DB::table('security_login_observations')->where('id', $active)->exists(), 'Active observation must be retained.');
    } finally {
        $holder->rollBack();
    }

    $busy = DB::table('security_login_observations')->insertGetId($row('busy-expired', now()->subDays(91)));
    $free = DB::table('security_login_observations')->insertGetId($row('free-expired', now()->subDays(91)));
    GoldTransaction::query()->create(['character_id' => $character->id, 'type' => 'fixture', 'amount' => 500, 'balance_after' => 500]);
    $before = $character->fresh()->getAttributes();
    $holder->beginTransaction();
    try {
        $holder->table('security_login_observations')->where('id', $busy)->lockForUpdate()->first();
        $start = hrtime(true);
        $result = app(SecurityLoginRetentionService::class)->prune();
        $check($result === ['pruned' => 1, 'deferred' => 1], 'Busy expired history must be deferred while other pruning progresses.');
        $check((hrtime(true) - $start) / 1e9 < .8, 'Busy expiry must be rejected with NOWAIT.');
        $check(DB::table('security_login_observations')->where('id', $busy)->exists(), 'Deferred history must remain.');
        $check(! DB::table('security_login_observations')->where('id', $free)->exists(), 'Free expired history must be pruned.');
        $result = app(SecurityAnomalyDetectionService::class)->scan();
        $check($result['retention_deferred'] === 1 && $result['created'] === 1 && ! $result['skipped'], 'Detection must still run while retention is deferred.');
        $result = app(SecurityAnomalyDetectionService::class)->scan();
        $check($result['created'] === 0 && $result['updated'] === 0, 'Repeating the scan must not duplicate detections.');
    } finally {
        $holder->rollBack();
    }
    $result = app(SecurityAnomalyDetectionService::class)->scan();
    $check($result['retention_pruned'] === 1 && $result['retention_deferred'] === 0, 'Next scan must retry and finish deferred pruning.');
    $check(SecurityAnomalyCase::query()->where('rule_key', 'gold_change')->where('character_id', $character->id)->count() === 1, 'Anomaly evidence must be preserved exactly once.');
    $check($before === $character->fresh()->getAttributes(), 'Pruning and detection must not change player assets or progress.');
    // The same two connections also prove concurrent compensation rejects busy play,
    // then applies once, preserving the source and returning its receipt on replay.
    config(['gold.battle.normal_drop_rate' => 100]);
    $data = ['result' => 'victory', 'turn_count' => 1, 'exp_gained' => 0,
        'enemy' => app(\App\Services\NamelessRuinService::class)->enemyStats(config('nameless_ruins.sand.enemies.0'), 1, false)];
    $source = \App\Models\NamelessWorkshopOperation::query()->create(['character_id' => $character->id,
        'request_uuid' => (string) \Illuminate\Support\Str::uuid(), 'action' => 'ruin', 'payload_hash' => str_repeat('c', 64), 'result' => $data]);
    $source->forceFill(['created_at' => '2026-10-06 23:00:00'])->save();
    $source = $source->fresh();
    $compensator = app(\App\Services\NamelessRuinCompensationService::class);
    $entry = $compensator->createPlan([['operation_id' => $source->id, 'character_id' => $character->id,
        'original_result_sha256' => hash('sha256', $source->getRawOriginal('result')), 'wins' => 1, 'zero_exp_wins' => 1]])['entries'][0];
    $before = $character->fresh()->getAttributes();
    $holder->beginTransaction();
    try {
        $holder->table('characters')->where('id', $character->id)->lockForUpdate()->first();
        $start = hrtime(true);
        $result = $compensator->applyEntry($entry);
        $check($result['status'] === 'deferred' && (hrtime(true) - $start) / 1e9 < .8, 'Busy player compensation must return immediately.');
        $check($before === $character->fresh()->getAttributes(), 'Busy compensation must not change assets.');
        $check(\App\Models\NamelessWorkshopOperation::query()->where('action', 'ruin_compensation')->count() === 0, 'Busy compensation must not create a paid receipt.');
    } finally {
        $holder->rollBack();
    }
    $paid = $compensator->applyEntry($entry);
    $after = $character->fresh()->getAttributes();
    $check($paid['status'] === 'applied' && (int) $after['wins'] === (int) $before['wins'] + 1, 'Released player compensation must apply exactly once.');
    $replayed = $compensator->applyEntry($entry);
    $check($replayed['status'] === 'replayed' && $replayed['receipt'] === $paid['receipt'], 'Concurrent retry must return the same compensation receipt.');
    $check($after === $character->fresh()->getAttributes(), 'Retry must not add growth, gold or wins.');
    $check(GoldTransaction::query()->where('type', 'ruin_compensation')->count() === 1, 'Compensation must have one Gold ledger entry.');
    $check($source->getRawOriginal('result') === $source->fresh()->getRawOriginal('result'), 'Compensation must preserve original battle history.');
    echo json_encode(['old_failure_code' => $oldCode, 'assertions' => $assertions, 'passed' => true, 'connections' => 2], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Isolated verification failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
