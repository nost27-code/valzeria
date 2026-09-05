<?php

declare(strict_types=1);

use App\Models\CompetitionEventCoordinator;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? 'all';
if ($command === 'worker') {
    assertSafeDatabase();
    runScheduleWorker((string) ($argv[2] ?? ''));
    exit(0);
}

if (! in_array('--confirm-isolated-database', $argv, true)) {
    fwrite(STDERR, "Refusing to run without --confirm-isolated-database.\n");
    exit(2);
}

assertSafeDatabase();
verifyAssert(
    in_array($command, ['all', 'schema', 'race'], true),
    'Usage: php scripts/verify/nation-raid-phase3-mariadb.php [all|schema|race] --confirm-isolated-database',
);

$result = [
    'pass' => true,
    'database' => databaseMetadata(),
];

if (in_array($command, ['all', 'schema'], true)) {
    $result['schema'] = verifySchemaAndTransitions();
}
if (in_array($command, ['all', 'race'], true)) {
    $result['coordinator_race'] = verifyCoordinatorRace();
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

function assertSafeDatabase(): void
{
    $connection = DB::connection();
    $driver = $connection->getDriverName();
    $database = (string) $connection->getDatabaseName();
    $version = (string) DB::scalar('SELECT VERSION()');

    verifyAssert(app()->environment('testing'), 'The Phase 3 harness requires APP_ENV=testing.');
    verifyAssert(in_array($driver, ['mysql', 'mariadb'], true), 'A MariaDB-compatible mysql/mariadb driver is required.');
    verifyAssert(str_starts_with($database, 'valzeria_nation_raid_phase3_'), 'Refusing to run outside an isolated Phase 3 database.');
    verifyAssert(str_contains(strtolower($version), 'mariadb'), 'The database server product is not MariaDB.');
    verifyAssert(
        preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) === 1
            && version_compare($matches[1], '10.5.13', '>='),
        'MariaDB 10.5.13 or newer is required.',
    );
}

/** @return array<string, string> */
function databaseMetadata(): array
{
    return [
        'driver' => DB::connection()->getDriverName(),
        'database' => (string) DB::connection()->getDatabaseName(),
        'version' => (string) DB::scalar('SELECT VERSION()'),
        // transaction_isolation was introduced in MariaDB 11.1.1; the baseline is 10.5.13.
        'isolation' => (string) DB::scalar('SELECT @@SESSION.tx_isolation'),
    ];
}

/** @return array<string, mixed> */
function verifySchemaAndTransitions(): array
{
    $tables = [
        'competition_event_coordinators',
        'nation_raid_events',
        'nation_raid_boss_cycles',
        'nation_raid_participations',
        'nation_raid_daily_usages',
        'nation_raid_battle_results',
        'nation_raid_daily_lineage_snapshots',
        'nation_raid_coordination_participants',
        'nation_raid_personal_rewards',
        'nation_raid_nation_rewards',
    ];
    foreach ($tables as $table) {
        verifyAssert(Schema::hasTable($table), "Missing Phase 3 table: {$table}");
        $engine = DB::table('information_schema.tables')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->value('engine');
        verifyAssert(strcasecmp((string) $engine, 'InnoDB') === 0, "{$table} must use InnoDB.");
    }
    verifyAssert(NationRaidEvent::query()->count() === 0, 'The isolated database must start without nation raid events.');
    verifyAssert(CompetitionEventCoordinator::query()->count() === 1, 'The singleton coordinator row is missing or duplicated.');

    $expectedIndexes = [
        'competition_event_coordinators' => [
            'competition_coordinator_slot_unique' => ['slot_key'],
        ],
        'nation_raid_events' => [
            'nation_raid_event_key_unique' => ['event_key'],
        ],
        'nation_raid_boss_cycles' => [
            'nation_raid_cycle_no_unique' => ['event_id', 'cycle_no'],
            'nation_raid_stage_no_unique' => ['event_id', 'stage_no'],
            'nation_raid_echo_no_unique' => ['event_id', 'echo_no'],
        ],
        'nation_raid_participations' => [
            'nation_raid_participation_account_unique' => ['event_id', 'account_id'],
        ],
        'nation_raid_daily_usages' => [
            'nation_raid_daily_usage_unique' => ['event_id', 'account_id', 'raid_day'],
        ],
        'nation_raid_battle_results' => [
            'nation_raid_battle_token_unique' => ['battle_token'],
            'nation_raid_refund_key_unique' => ['refund_key'],
        ],
        'nation_raid_daily_lineage_snapshots' => [
            'nation_raid_daily_lineage_unique' => ['event_id', 'raid_day'],
        ],
        'nation_raid_coordination_participants' => [
            'nation_raid_coordination_member_unique' => ['event_id', 'nation_id_snapshot', 'character_id_snapshot'],
        ],
        'nation_raid_personal_rewards' => [
            'nation_raid_personal_reward_unique' => ['event_id', 'character_id_snapshot', 'reward_key'],
            'nation_raid_personal_reward_idem_unique' => ['idempotency_key'],
        ],
        'nation_raid_nation_rewards' => [
            'nation_raid_nation_reward_unique' => ['event_id', 'nation_id_snapshot', 'reward_key'],
            'nation_raid_nation_reward_idem_unique' => ['idempotency_key'],
        ],
    ];
    foreach ($expectedIndexes as $table => $indexes) {
        foreach ($indexes as $index => $columns) {
            verifyUniqueIndex($table, $index, $columns);
        }
    }

    $foreign = DB::table('information_schema.key_column_usage')
        ->where('table_schema', DB::connection()->getDatabaseName())
        ->where('table_name', 'nation_raid_nation_rewards')
        ->where('column_name', 'nation_resource_transaction_id')
        ->whereNotNull('referenced_table_name')
        ->first();
    verifyAssert($foreign !== null, 'The nation resource ledger foreign key is missing.');
    verifyAssert($foreign->constraint_name === 'nation_raid_nation_resource_tx_fk', 'The shortened ledger foreign key name was not applied.');
    verifyAssert(strlen((string) $foreign->constraint_name) <= 64, 'A MariaDB identifier exceeds 64 characters.');

    foreach (['ruleset_snapshot', 'published_nation_counts_snapshot'] as $column) {
        $createSql = (string) array_values((array) DB::selectOne('SHOW CREATE TABLE nation_raid_events'))[1];
        verifyAssert(
            str_contains(strtolower($createSql), "json_valid(`{$column}`)"),
            "MariaDB JSON validation is missing for nation_raid_events.{$column}.",
        );
    }

    $stateResult = verifyStateMachine();
    $downResult = verifyDownPolicyAndReapply();

    return [
        'tables' => count($tables),
        'named_unique_indexes' => array_sum(array_map('count', $expectedIndexes)),
        'shortened_foreign_key' => (string) $foreign->constraint_name,
        'state_machine' => $stateResult,
        'down_policy' => $downResult,
    ];
}

/** @param list<string> $columns */
function verifyUniqueIndex(string $table, string $index, array $columns): void
{
    $rows = DB::table('information_schema.statistics')
        ->where('table_schema', DB::connection()->getDatabaseName())
        ->where('table_name', $table)
        ->where('index_name', $index)
        ->orderBy('seq_in_index')
        ->get(['column_name', 'non_unique']);
    verifyAssert($rows->count() === count($columns), "Unique index {$index} has an unexpected column count.");
    verifyAssert($rows->every(fn ($row): bool => (int) $row->non_unique === 0), "Index {$index} is not unique.");
    verifyAssert($rows->pluck('column_name')->all() === $columns, "Unique index {$index} has an unexpected column order.");
}

/** @return array<string, mixed> */
function verifyStateMachine(): array
{
    $service = app(NationRaidEventService::class);
    $suffix = bin2hex(random_bytes(5));
    $admin = User::query()->create([
        'name' => 'Phase 3 MariaDB verifier',
        'email' => "nation-raid-phase3-{$suffix}@example.test",
        'role' => 'admin',
    ]);
    $start = isolatedStartTime();
    $event = $service->createDraft("phase3-state-{$suffix}", '国家対抗レイド検証', $start);

    $unapprovedBlocked = catches(
        static fn () => $service->schedule($event, $start->subHours(72)),
        DomainException::class,
    );
    verifyAssert($unapprovedBlocked, 'An unapproved event was scheduled.');

    $event = $service->approveBalance($event, $admin, 'scripts/verify/nation-raid-phase3-mariadb.php');
    $event = $service->schedule($event, $start->subHours(72));

    config()->set('features.nation_competitive_raid_enabled', true);
    config()->set('features.nation_community_enabled', true);
    config()->set('features.nation_development_enabled', true);
    config()->set('features.nation_war_enabled', false);
    foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
        config()->set("battle.job_art_v2.{$flag}", true);
    }

    $event = $service->activate($event, $start);
    verifyAssert($event->status === NationRaidEvent::STATUS_ACTIVE, 'The event did not become active.');
    verifyAssert($event->cycles()->count() === 1, 'Activation did not create exactly one first cycle.');
    $event = $service->pauseSorties($event, 'MariaDB state verification');
    verifyAssert(! $event->acceptsNewSortiesAt($start->addHour()), 'A paused event accepted a sortie.');
    $event = $service->resumeSorties($event);
    verifyAssert($event->acceptsNewSortiesAt($start->addHour()), 'A resumed event rejected a valid sortie.');
    $event = $service->beginFinalization($event, $event->ends_at);
    $event = $service->completeFinalization($event, $event->ends_at->copy()->addMinutes(10));
    verifyAssert($event->status === NationRaidEvent::STATUS_COMPLETED, 'The event did not complete finalization.');

    $event->delete();
    $admin->delete();
    verifyAssert(NationRaidEvent::query()->count() === 0, 'The state scenario did not clean up its isolated event.');

    return [
        'unapproved_schedule_blocked' => $unapprovedBlocked,
        'transitions' => ['draft', 'scheduled', 'active', 'finalizing', 'completed'],
        'pause_resume_verified' => true,
    ];
}

/** @return array<string, bool> */
function verifyDownPolicyAndReapply(): array
{
    $service = app(NationRaidEventService::class);
    $suffix = bin2hex(random_bytes(5));
    $event = $service->createDraft("phase3-down-{$suffix}", 'rollback拒否検証', isolatedStartTime());
    $migration = require database_path('migrations/2026_09_03_120000_create_nation_raid_event_foundation.php');

    $refused = catches(static fn () => $migration->down(), RuntimeException::class);
    verifyAssert($refused, 'The migration accepted rollback with retained raid history.');
    verifyAssert(Schema::hasTable('nation_raid_events'), 'Rollback refusal changed the schema.');

    $event->delete();
    verifyAssert(NationRaidEvent::query()->count() === 0, 'The down-policy fixture was not removed.');
    $migration->down();
    verifyAssert(! Schema::hasTable('nation_raid_events'), 'Empty rollback did not remove the Phase 3 schema.');
    $migration->up();
    verifyAssert(Schema::hasTable('nation_raid_events'), 'Phase 3 migration could not be reapplied.');
    verifyAssert(CompetitionEventCoordinator::query()->count() === 1, 'Reapply did not restore the singleton coordinator.');

    return [
        'retained_history_refused' => $refused,
        'empty_rollback_succeeded' => true,
        'reapply_succeeded' => true,
    ];
}

/** @return array<string, mixed> */
function verifyCoordinatorRace(): array
{
    verifyAssert(NationRaidEvent::query()->count() === 0, 'The isolated database must be empty before the race scenario.');
    $service = app(NationRaidEventService::class);
    $suffix = bin2hex(random_bytes(5));
    $admin = User::query()->create([
        'name' => 'Phase 3 race verifier',
        'email' => "nation-raid-phase3-race-{$suffix}@example.test",
        'role' => 'admin',
    ]);
    $start = isolatedStartTime();
    $events = [];
    foreach (['a', 'b'] as $key) {
        $event = $service->createDraft("phase3-race-{$suffix}-{$key}", '開催排他競合検証', $start);
        $events[] = $service->approveBalance($event, $admin, 'scripts/verify/nation-raid-phase3-mariadb.php#race');
    }

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nation-raid-phase3-'.bin2hex(random_bytes(8));
    verifyAssert(mkdir($directory, 0700, true), 'Unable to create the race barrier directory.');
    $goFile = $directory.DIRECTORY_SEPARATOR.'go';
    $workers = [];
    foreach ($events as $index => $event) {
        $payload = base64_encode(json_encode([
            'event_id' => $event->id,
            'announced_at' => $start->subHours(72)->toIso8601String(),
            'ready_file' => $directory.DIRECTORY_SEPARATOR."ready-{$index}",
            'go_file' => $goFile,
        ], JSON_THROW_ON_ERROR));
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, 'worker', $payload],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true],
        );
        verifyAssert(is_resource($process), 'Unable to start a schedule worker.');
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready_file' => $directory.DIRECTORY_SEPARATOR."ready-{$index}"];
    }

    waitUntil(
        fn (): bool => count(array_filter($workers, fn (array $worker): bool => is_file($worker['ready_file']))) === count($workers),
        20,
        'The schedule workers did not reach the barrier.',
    );
    touch($goFile);

    $outcomes = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        verifyAssert($exitCode === 0, "Schedule worker failed: {$stderr}");
        $decoded = json_decode(trim((string) $stdout), true, flags: JSON_THROW_ON_ERROR);
        $outcomes[] = (string) $decoded['outcome'];
    }
    sort($outcomes);
    verifyAssert($outcomes === ['blocked_overlap', 'scheduled'], 'The coordinator race did not converge to one scheduled event.');
    verifyAssert(NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_SCHEDULED)->count() === 1, 'The race committed an unexpected number of scheduled events.');

    foreach ($events as $event) {
        $service->cancelBeforeStart($event->refresh());
        $event->refresh()->delete();
    }
    $admin->delete();
    verifyAssert(NationRaidEvent::query()->count() === 0, 'The race scenario did not clean up its isolated events.');
    @unlink($goFile);
    foreach ($workers as $worker) {
        @unlink($worker['ready_file']);
    }
    @rmdir($directory);

    return [
        'workers' => count($workers),
        'outcomes' => $outcomes,
        'exactly_one_scheduled' => true,
    ];
}

function runScheduleWorker(string $encoded): void
{
    $payload = json_decode(base64_decode($encoded, true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    touch((string) $payload['ready_file']);
    waitUntil(fn (): bool => is_file((string) $payload['go_file']), 20, 'The parent did not release the race barrier.');

    try {
        app(NationRaidEventService::class)->schedule(
            NationRaidEvent::query()->findOrFail((int) $payload['event_id']),
            CarbonImmutable::parse((string) $payload['announced_at']),
        );
        $outcome = 'scheduled';
    } catch (DomainException $exception) {
        verifyAssert(str_contains($exception->getMessage(), '別の国家対抗レイド'), 'The worker failed for an unexpected domain reason.');
        $outcome = 'blocked_overlap';
    }

    fwrite(STDOUT, json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR).PHP_EOL);
}

function isolatedStartTime(): CarbonImmutable
{
    $latest = collect([
        DB::table('nation_raid_events')->max('ends_at'),
        Schema::hasTable('nation_wars') ? DB::table('nation_wars')->max('ends_at') : null,
    ])->filter()->max();
    $floor = CarbonImmutable::now()->addDays(10)->startOfHour();

    if (! $latest) {
        return $floor;
    }

    $afterExistingEvents = CarbonImmutable::parse((string) $latest)->addDays(10);

    return $floor->gte($afterExistingEvents) ? $floor : $afterExistingEvents;
}

function catches(callable $callback, string $expected): bool
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expected) {
            return true;
        }
        throw $exception;
    }

    return false;
}

function waitUntil(callable $condition, int $seconds, string $message): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($condition()) {
            return;
        }
        usleep(20_000);
    }

    throw new RuntimeException($message);
}

function verifyAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
