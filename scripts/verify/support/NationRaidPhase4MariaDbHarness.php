<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\NationMembership;
use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidCoordinationParticipant;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\CompetitionEventCoordinatorService;
use App\Services\Nation\NationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidSettlementService;
use App\Services\Nation\Raid\NationRaidSortieCombatService;
use App\Services\Nation\Raid\NationRaidSortieService;
use App\Services\Nation\Raid\NationRaidTransactionRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/NationRaidPhase4MariaDbRewardScenarios.php';

/**
 * CLI-only disposable-DB harness. Uses real processes/connections, not mocked SQL failures.
 * Synthetic damage fixtures test settlement arithmetic, never production balance.
 * There is deliberately no migration, Seeder, deployment or database cleanup operation here.
 */
final class NationRaidPhase4MariaDbHarness
{
    use NationRaidPhase4MariaDbRewardScenarios;

    public array $checks = [];

    public ?string $currentCheck = null;

    private array $metadata = [];

    private bool $preflightPassed = false;

    private NationRaidEvent $event;

    private string $time = '2030-01-10T09:00:00+09:00';

    public function preflight(): void
    {
        $connection = DB::connection();
        NationRaidPhase4MariaDbSafety::settings(app()->environment(), $connection->getConfig(), true, app()->configurationIsCached());
        $database = (string) $connection->getDatabaseName();
        $version = (string) DB::scalar('SELECT VERSION()');
        $actual = (string) DB::scalar('SELECT DATABASE()');
        $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $actual)
            ->whereIn('TABLE_NAME', NationRaidPhase4MariaDbSafety::tables())->pluck('ENGINE', 'TABLE_NAME')->all();
        NationRaidPhase4MariaDbSafety::server($version, $actual, $database, $engines);
        $isolation = (string) DB::scalar('SELECT @@SESSION.tx_isolation');
        $this->check($isolation === 'REPEATABLE-READ', 'REPEATABLE READ is required for the baseline.');
        $this->metadata = ['version' => $version, 'driver' => $connection->getDriverName(), 'database' => $actual, 'isolation' => $isolation,
            'ruleset_hash' => app(NationRaidRules::class)->rulesetHash()];
        $this->preflightPassed = true;
        $this->configure($this->time);
    }

    public function run(): array
    {
        $this->check($this->preflightPassed, 'Database preflight must pass before creating fixtures.');
        // Require a fresh disposable schema. Leave fixtures for inspection/container disposal.
        foreach (['characters', 'nations', 'nation_wars', 'nation_raid_events'] as $table) {
            $this->check(DB::table($table)->count() === 0, 'Disposable database is not empty: '.$table);
        }
        $citizens = [$this->character(), $this->character()];
        $nation = app(NationService::class)->create($citizens[0], '競合検証');
        NationMembership::query()->create(['nation_id' => $nation->id, 'character_id' => $citizens[1]->id,
            'role' => 'citizen', 'joined_at' => now()]);
        $events = app(NationRaidEventService::class);
        $this->event = $events->createDraft('phase4-'.bin2hex(random_bytes(8)), '隔離競合検証・公開不可', now());
        $events->approveBalance($this->event, User::factory()->create(['role' => 'admin']),
            'SYNTHETIC TEST FIXTURE ONLY: not a production balance approval');
        $events->schedule($this->event->refresh(), now()->subHours(72));
        $events->activate($this->event->refresh());
        $this->event->refresh();

        $this->scenario('same_token_admission_and_settlement', fn () => $this->duplicate());
        $this->scenario('different_tokens_one_pending', fn () => $this->onePending());
        $this->scenario('player_capture_releases_global_admission_lock', fn () => $this->captureConcurrency());
        $this->scenario('concurrent_carry_and_nation_coordination', fn () => $this->coordination($citizens));
        $this->scenario('stage10_stage20_echo_and_replay', fn () => $this->milestones());
        $this->scenario('daily_limit_fifth_slot_race', fn () => $this->dailyLimit());
        $this->scenario('settlement_refund_race', fn () => $this->refundRace());
        $this->scenario('real_1213_rollback_and_retry', fn () => $this->deadlock());
        $this->scenario('real_1205_exhaustion_session_restore_and_recovery', fn () => $this->timeoutRecovery());
        $this->scenario('persisted_damage_and_usage_conservation', fn () => $this->conservation());
        // Synthetic counter-boundary fixture starts from 255; it is outside the prior whole-run conservation check.
        $this->scenario('refund_counter_crosses_255', fn () => $this->refundCounter());
        $this->scenario('concurrent_reward_finalization', fn () => $this->rewardFinalization());
        $this->scenario('same_reward_claim_currency_once', fn () => $this->sameRewardClaim());
        $this->scenario('same_fixed_reward_bundle_once', fn () => $this->sameFixedBundleClaim());
        $this->scenario('competing_reward_choices', fn () => $this->competingRewardChoices());
        $this->scenario('different_event_rewards_inventory_race', fn () => $this->differentRewardClaims());
        $this->scenario('reward_capacity_after_owner_lock', fn () => $this->capacityAfterOwnerLock());
        $this->scenario('same_event_different_owners_claim_independently', fn () => $this->independentRewardOwners());
        $this->scenario('reward_real_1205_preserves_right_and_retry', fn () => $this->rewardLockTimeout());
        $this->scenario('reward_claim_failure_rollback_and_concurrent_retry', fn () => $this->rewardRollbackRace());

        return ['pass' => true, 'database' => $this->metadata, 'checks' => $this->checks,
            'completed_check_count' => count($this->checks),
            'scope' => 'Real MariaDB concurrency; synthetic damage fixtures are NOT balance evidence.',
            'fixtures' => 'Retained in the disposable test database; no existing data is deleted.'];
    }

    private function scenario(string $name, callable $callback): void
    {
        $this->currentCheck = $name;
        fwrite(STDERR, 'Checking '.$name.PHP_EOL);
        $start = microtime(true);
        $evidence = $callback();
        $this->checks[$name] = ['pass' => true, 'elapsed_seconds' => round(microtime(true) - $start, 3), 'evidence' => $evidence];
        $this->currentCheck = null;
    }

    private function duplicate(): array
    {
        $character = $this->character();
        $token = bin2hex(random_bytes(32));
        $job = ['op' => 'start', 'character' => $character->id, 'token' => $token];
        $rows = $this->race([$job, $job]);
        $this->outcomes($rows, ['created', 'existing']);
        $this->check(count(array_unique(array_column($rows, 'battle'))) === 1, 'Duplicate admission created multiple rows.');
        $battle = SavedBattle::query()->where('battle_token', $token)->sole();
        $this->assertUsage($character, 1, 0, 0, 240);
        $before = $this->damage();
        $job = ['op' => 'resolve', 'battle' => $battle->id, 'damage' => 11_111];
        $rows = $this->race([$job, $job]);
        $this->outcomes($rows, ['resolved', 'resolved']);
        $this->check($this->damage() - $before === 11_111, 'Duplicate settlement added damage more than once.');
        $this->assertUsage($character, 1, 1, 0, 240);

        return ['admission_rows' => 1, 'applied_damage' => 11_111, 'stamina_spent' => 10];
    }

    private function onePending(): array
    {
        $character = $this->character();
        $rows = $this->race([
            ['op' => 'start', 'character' => $character->id, 'token' => bin2hex(random_bytes(32))],
            ['op' => 'start', 'character' => $character->id, 'token' => bin2hex(random_bytes(32))],
        ]);
        $this->outcomes($rows, ['created', 'blocked_pending']);
        $battle = SavedBattle::query()->where('character_id', $character->id)->sole();
        $this->assertUsage($character, 1, 0, 0, 240);
        app(NationRaidSettlementService::class)->refund($battle, 'synthetic_test_cleanup');
        $this->assertUsage($character, 0, 0, 1, 250);

        return ['pending_rows' => 1, 'refunds' => 1];
    }

    private function coordination(array $citizens): array
    {
        $battles = array_map(fn ($character) => $this->start($character), $citizens);
        $this->check($battles[0]->target_cycle_no === $battles[1]->target_cycle_no, 'Expected the same starting cycle.');
        $before = $this->damage();
        $this->race(array_map(fn ($battle) => ['op' => 'resolve', 'battle' => $battle->id, 'damage' => 3_000_000], $battles));
        $bonuses = [];
        foreach ($battles as $battle) {
            $battle->refresh();
            $bonuses[] = $battle->coordination_damage_total;
            $this->check($battle->status === 'resolved' && $battle->applied_damage_total === 3_000_000, 'Personal damage lost during carry.');
            $this->check($battle->participation->personal_damage_total === 3_000_000, 'Coordination leaked into personal totals.');
            $this->check($battle->nation_damage_total === 3_000_000 + $battle->coordination_damage_total, 'Nation total excludes coordination.');
        }
        sort($bonuses);
        $this->check($bonuses === [0, 90_000], 'Concurrent unique citizens did not receive 0% then 3%.');
        $this->check($this->damage() - $before === 6_090_000, 'Concurrent personal/coordination damage was not conserved.');
        $this->check($this->event->refresh()->current_cycle_no === 2, 'Concurrent damage did not cross the cycle boundary.');
        $joined = $this->joinedTimes();
        // A new sortie by an existing citizen must not extend their unique-participant window.
        $this->configure(CarbonImmutable::parse($this->time)->addMinute()->toIso8601String());
        $repeat = $this->start($citizens[0]);
        $this->settle($repeat, 100);
        $this->check($this->joinedTimes() === $joined,
            'A repeated citizen refreshed the coordination window.');

        return ['bonus_distribution' => $bonuses, 'crossed_cycle' => true, 'window_not_refreshed' => true];
    }

    private function milestones(): array
    {
        $battle = $this->start($this->character());
        $targetDamage = (int) $this->event->total_target_hp + (int) $this->event->cycle_max_hp + 123;
        $damage = $targetDamage - $this->damage();
        $this->settle($battle, $damage);
        $event = $this->event->refresh();
        $this->check($event->stage10_reached_at !== null && $event->completed_at !== null
            && $event->echo_defeated_count === 1 && $event->current_cycle_no === 22, 'Stage or echo milestone is incorrect.');
        $stamps = [$event->stage10_reached_at->toIso8601String(), $event->completed_at->toIso8601String()];
        $job = ['op' => 'resolve', 'battle' => $battle->id, 'damage' => $damage];
        $this->outcomes($this->race([$job, $job]), ['resolved', 'resolved']);
        $event->refresh();
        $this->check($this->damage() === $targetDamage && $event->echo_defeated_count === 1, 'Milestone replay changed progression.');
        $this->check([$event->stage10_reached_at->toIso8601String(), $event->completed_at->toIso8601String()] === $stamps,
            'Milestone timestamps changed on replay.');

        return ['cycle' => 22, 'echo_defeated' => 1, 'remaining_hp' => $this->cycle()->current_hp];
    }

    private function dailyLimit(): array
    {
        $character = $this->character();
        for ($i = 0; $i < 4; $i++) {
            $this->settle($this->start($character), 0);
        }
        $rows = $this->race([
            ['op' => 'start', 'character' => $character->id, 'token' => bin2hex(random_bytes(32))],
            ['op' => 'start', 'character' => $character->id, 'token' => bin2hex(random_bytes(32))],
        ]);
        $this->outcomes($rows, ['created', 'blocked_pending']);
        $last = SavedBattle::query()->where('character_id', $character->id)->where('status', 'started')->sole();
        $this->settle($last, 0);
        $this->assertUsage($character, 5, 5, 0, 200);
        $rows = $this->race([['op' => 'start', 'character' => $character->id, 'token' => bin2hex(random_bytes(32))]]);
        $this->outcomes($rows, ['blocked_limit']);
        $this->assertUsage($character, 5, 5, 0, 200);
        $ordinals = SavedBattle::query()->where('character_id', $character->id)->orderBy('id')->get()
            ->map(fn ($battle) => $battle->summary['daily_resolution_no'])->all();
        $this->check($ordinals === [1, 2, 3, 4, 5], 'Daily resolution ordinals are not unique and contiguous.');

        return ['used' => 5, 'stamina_spent' => 50, 'resolution_ordinals' => $ordinals];
    }

    private function refundRace(): array
    {
        $character = $this->character();
        $battle = $this->start($character);
        $before = $this->damage();
        $rows = $this->race([
            ['op' => 'resolve', 'battle' => $battle->id, 'damage' => 1_000],
            ['op' => 'refund', 'battle' => $battle->id],
        ]);
        $battle->refresh();
        $this->check(in_array($battle->status, ['resolved', 'refunded'], true), 'Refund race left a pending battle.');
        $this->outcomes($rows, [$battle->status, $battle->status]);
        $resolved = $battle->status === 'resolved';
        $this->check($this->damage() - $before === ($resolved ? 1_000 : 0), 'Refund race double-committed or lost damage.');
        $this->assertUsage($character, $resolved ? 1 : 0, $resolved ? 1 : 0, $resolved ? 0 : 1, $resolved ? 240 : 250);

        return ['outcome' => $battle->status, 'exactly_one_terminal_effect' => true];
    }

    private function deadlock(): array
    {
        $characters = [$this->character(), $this->character()];
        $rows = $this->race([
            ['op' => 'deadlock', 'first' => $characters[0]->id, 'second' => $characters[1]->id],
            ['op' => 'deadlock', 'first' => $characters[1]->id, 'second' => $characters[0]->id],
        ]);
        $this->check(in_array(1213, array_merge(...array_column($rows, 'errors')), true), 'No real MariaDB deadlock was observed.');
        foreach ($characters as $character) {
            $this->check((int) $character->refresh()->attack_base === 3_002, 'Deadlock rollback/retry duplicated or lost the fixture update.');
        }
        foreach ($rows as $row) {
            $this->check($row['transaction_level'] === 0 && $row['timeout_restored'], 'Deadlock leaked transaction/session state.');
        }

        return ['workers' => $rows, 'both_fixture_rows_incremented_exactly_twice' => true];
    }

    private function refundCounter(): array
    {
        $type = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'nation_raid_daily_usages')->where('COLUMN_NAME', 'refunded_count')->value('COLUMN_TYPE');
        $this->check(is_string($type) && str_starts_with($type, 'int(') && str_contains($type, 'unsigned'), 'Refund counter migration is missing.');
        $character = $this->character();
        $battle = $this->start($character);
        NationRaidDailyUsage::where('participation_id', $battle->participation_id)->update(['refunded_count' => 255]);
        $rows = $this->race([['op' => 'refund', 'battle' => $battle->id], ['op' => 'refund', 'battle' => $battle->id]]);
        $this->outcomes($rows, ['refunded', 'refunded']);
        $this->assertUsage($character, 0, 0, 256, 250);

        return ['workers' => $rows, 'column_type' => $type, 'refunded_count' => 256];
    }

    private function captureConcurrency(): array
    {
        $characters = [$this->character(), $this->character()];
        $rows = $this->race([
            ['op' => 'start_slow_capture', 'character' => $characters[0]->id, 'token' => bin2hex(random_bytes(32))],
            ['op' => 'start_after_capture', 'character' => $characters[1]->id, 'token' => bin2hex(random_bytes(32))],
        ]);
        $this->outcomes($rows, ['created', 'created']);
        foreach ($rows as $row) {
            $this->check(isset($row['operational']['player_capture_ms']), 'No preparation timing evidence.');
            app(NationRaidSettlementService::class)->refund(SavedBattle::findOrFail($row['battle']), 'synthetic_capture_test');
        }

        return ['workers' => $rows, 'peer_admitted_while_capture_waited' => true];
    }

    private function startWithBarrier(array $job, string $directory): array
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $scoped = clone $dispatcher;
        $observed = false;
        if ($job['op'] === 'start_slow_capture') {
            $scoped->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($query) use (&$observed, $directory): void {
                if ($observed || ! str_contains($query->sql, 'character_items')) {
                    return;
                }
                $observed = true;
                touch($directory.'/capture-held');
                $this->wait(fn () => is_file($directory.'/capture-peer-completed'), 20, 'Player capture still holds the global admission lock.');
            });
            $connection->setEventDispatcher($scoped);
        } else {
            $this->wait(fn () => is_file($directory.'/capture-held'), 20, 'Slow capture did not start.');
        }
        try {
            [$battle, $created] = app(NationRaidSortieService::class)->start($this->event,
                Character::findOrFail($job['character']), 'assault', $job['token']);
            $this->check($battle->status === 'started' && isset($battle->summary['admission']['player']), 'Preparation failed.');
            if ($job['op'] === 'start_after_capture') {
                touch($directory.'/capture-peer-completed');
            }

            return ['outcome' => $created ? 'created' : 'existing', 'battle' => $battle->id, 'operational' => $battle->summary['operational']];
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }

    private function timeoutRecovery(): array
    {
        $character = $this->character();
        $battle = $this->start($character);
        $calculation = $this->calculation($battle, 500);
        $before = $this->damage();
        $previousTimeout = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $observed = new NationRaidPhase4ObservedTransactions;
        app()->instance(NationRaidTransactionRunner::class, $observed);
        $group = $this->launch([['op' => 'hold_coordinator']]);
        try {
            $this->wait(fn () => is_file($group['directory'].'/held'), 15, 'Lock holder did not acquire the coordinator.');
            $start = microtime(true);
            foreach (['resolve', 'refund'] as $operation) {
                $attemptStart = count($observed->attempts);
                try {
                    $service = app(NationRaidSettlementService::class);
                    $operation === 'resolve' ? $service->resolve($battle, $calculation) : $service->refund($battle, 'synthetic_lock_timeout');
                    $this->check(false, 'Expected a real lock wait timeout.');
                } catch (QueryException $exception) {
                    $this->check((int) ($exception->errorInfo[1] ?? 0) === 1205, 'Expected MariaDB error 1205.');
                }
                $this->check(array_slice($observed->attempts, $attemptStart) === [1, 2, 3], 'Retry count differs from V2-R5.');
                $this->check(DB::transactionLevel() === 0, 'Failed settlement/refund leaked a transaction.');
                $this->check((int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout') === $previousTimeout, 'Session timeout was not restored.');
            }
            $elapsed = microtime(true) - $start;
            // Server timeout granularity may round the wait; exact attempts/session values are checked below.
            $this->check($elapsed < 30, 'Settlement plus refund exceeded the 30-second CI wall-time budget.');
            $this->check(array_unique($observed->timeouts) === [3], 'Retry did not use the approved three-second session timeout.');
            $this->check($observed->waitLevels === [0, 0, 0, 0], 'Backoff ran while a transaction remained open.');
            $this->check($battle->refresh()->status === 'started' && $this->damage() === $before, 'Exhaustion partially committed.');
            $this->assertUsage($character, 1, 0, 0, 240);
        } finally {
            touch($group['directory'].'/release');
            $this->finish($group);
            app()->forgetInstance(NationRaidTransactionRunner::class);
        }
        $this->configure(CarbonImmutable::parse($this->time)->addMinutes(10)->toIso8601String());
        $job = ['op' => 'recover', 'public_enabled' => false];
        $this->race([$job, $job]);
        $this->check($battle->refresh()->status === 'refunded' && filled($battle->refund_key), 'Expired sortie was not recovered.');
        // Ten minutes of natural recovery + the complete original cost, not a capped refund.
        $this->assertUsage($character, 0, 0, 1, 260);
        $this->check($this->damage() === $before, 'Recovery changed shared HP.');

        return ['database_errors' => $observed->errors, 'attempts' => $observed->attempts,
            'settlement_plus_refund_seconds' => round($elapsed, 3), 'session_restored' => true, 'recovered_once_with_gate_off' => true];
    }

    private function conservation(): array
    {
        $cycles = NationRaidBossCycle::query()->where('event_id', $this->event->id)->orderBy('cycle_no')->get();
        $battles = SavedBattle::query()->where('event_id', $this->event->id)->get();
        $this->check($cycles->pluck('cycle_no')->all() === range(1, $cycles->count()), 'Cycle numbering has a duplicate/gap.');
        $this->check($cycles->where('current_hp', '>', 0)->count() === 1 && $cycles->last()->id === $this->cycle()->id, 'Multiple/no live cycles.');
        $this->check($cycles->sum(fn ($cycle) => $cycle->max_hp - $cycle->current_hp) === $this->damage(), 'Shared HP differs from committed damage.');
        foreach ($battles as $battle) {
            $this->check(in_array($battle->status, ['resolved', 'refunded'], true), 'Pending sortie remains after verification.');
            if ($battle->status === 'refunded') {
                $this->check($battle->applied_damage_total === 0 && $battle->coordination_damage_total === 0, 'Refunded sortie dealt damage.');

                continue;
            }
            $this->check($battle->calculated_damage_total === $battle->applied_damage_total, 'Carry discarded personal damage.');
            $this->check(array_sum(array_column($battle->damage_segments, 'damage')) === $battle->applied_damage_total + $battle->coordination_damage_total,
                'Damage segments do not sum to the settled totals.');
            foreach ($battle->damage_segments as $segment) {
                $this->check($segment['hp_after'] >= 0 && $segment['damage'] === $segment['hp_before'] - $segment['hp_after'],
                    'Invalid HP boundary segment.');
            }
        }
        foreach ($this->event->participations()->get() as $participation) {
            $resolved = $battles->where('participation_id', $participation->id)->where('status', 'resolved');
            $this->check($participation->resolved_sorties === $resolved->count()
                && $participation->personal_damage_total === $resolved->sum('applied_damage_total'), 'Participant totals do not match immutable results.');
        }
        foreach (NationRaidDailyUsage::query()->where('event_id', $this->event->id)->get() as $usage) {
            $daily = $battles->where('account_id', $usage->account_id)->where('raid_day', $usage->raid_day);
            $resolvedCount = $daily->where('status', 'resolved')->count();
            $this->check($usage->used_count === $resolvedCount && $usage->resolved_count === $resolvedCount
                && $usage->refunded_count === $daily->where('status', 'refunded')->count(),
                'Daily usage counters differ from the terminal battle rows.');
        }

        return ['cycles' => $cycles->count(), 'terminal_sorties' => $battles->count(), 'applied_damage' => $this->damage()];
    }

    public function worker(string $encoded): array
    {
        $this->check($this->preflightPassed, 'Database preflight must pass before running a worker.');
        $job = json_decode(base64_decode($encoded, true) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->check(is_array($job) && preg_match('/\A[a-f0-9]{32}\z/', $job['run'] ?? '') === 1
            && in_array($job['index'] ?? null, [0, 1], true), 'Invalid worker barrier.');
        $directory = sys_get_temp_dir().'/valzeria-raid-phase4-'.$job['run'];
        $this->check(is_dir($directory) && ! is_link($directory)
            && dirname((string) realpath($directory)) === realpath(sys_get_temp_dir()), 'Worker barrier is outside the temporary root.');
        $this->configure($job['time']);
        if (($job['public_enabled'] ?? true) === false) {
            config(['features.nation_competitive_raid_enabled' => false]);
        }
        $this->event = NationRaidEvent::query()->findOrFail($job['event']);
        // Both competing settlements consume the same parent-produced DTO, never a new battle roll.
        $calculation = null;
        if ($job['op'] === 'resolve') {
            $this->check(preg_match('/\Acalculation-[01]\.json\z/', $job['calculation_file'] ?? '') === 1, 'Invalid calculation filename.');
            $path = $directory.'/'.$job['calculation_file'];
            $this->check(is_file($path) && ! is_link($path), 'Frozen calculation is unavailable.');
            $calculation = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }
        $previousTimeout = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        touch($directory.'/ready-'.$job['index']);
        $this->wait(fn () => is_file($directory.'/go'), 20, 'Parent did not release the worker.');
        switch ($job['op']) {
            case 'reward_finalize':
            case 'reward_claim':
            case 'reward_claim_timeout':
            case 'reward_claim_fail_notification':
            case 'reward_claim_after_failure':
            case 'reward_claim_after_peer_lock':
            case 'reward_claim_hold_owner':
            case 'reward_claim_hold_inventory':
            case 'reward_claim_after_inventory_staged':
                $result = $this->rewardWorker($job, $directory);
                break;
            case 'start_slow_capture':
            case 'start_after_capture':
                $result = $this->startWithBarrier($job, $directory);
                break;
            case 'start':
                try {
                    [$battle, $created] = app(NationRaidSortieService::class)->start($this->event,
                        Character::query()->findOrFail($job['character']), 'assault', $job['token']);
                    $result = ['outcome' => $created ? 'created' : 'existing', 'battle' => $battle->id];
                } catch (DomainException $exception) {
                    $result = match ($exception->getMessage()) {
                        '前の出撃を処理中です。しばらく待ってから確認してください。' => ['outcome' => 'blocked_pending'],
                        '本日の残り出撃回数がありません。' => ['outcome' => 'blocked_limit'],
                        default => throw $exception,
                    };
                }
                break;
            case 'resolve':
            case 'refund':
                $battle = SavedBattle::query()->findOrFail($job['battle']);
                $service = app(NationRaidSettlementService::class);
                $saved = $job['op'] === 'resolve' ? $service->resolve($battle, $calculation) : $service->refund($battle, 'synthetic_race');
                $result = ['outcome' => $saved->status, 'battle' => $saved->id];
                break;
            case 'recover':
                $result = ['outcome' => 'recovery', 'counts' => app(NationRaidSettlementService::class)->recoverExpired(100, $this->event->id)];
                break;
            case 'hold_coordinator':
                DB::transaction(function () use ($directory): void {
                    app(CompetitionEventCoordinatorService::class)->lock();
                    touch($directory.'/held');
                    $this->wait(fn () => is_file($directory.'/release'), 40, 'Parent did not release the held coordinator.');
                });
                $result = ['outcome' => 'released'];
                break;
            case 'deadlock':
                $transactions = new NationRaidPhase4ObservedTransactions;
                $transactions->run(function (int $attempt) use ($job, $directory): void {
                    $first = Character::query()->whereKey($job['first'])->lockForUpdate()->firstOrFail();
                    $first->increment('attack_base'); // Rolled back if this transaction is the victim.
                    if ($attempt === 1) {
                        touch($directory.'/locked-'.$job['index']);
                        $this->wait(fn () => is_file($directory.'/locked-0') && is_file($directory.'/locked-1'), 10,
                            'Deadlock peer did not obtain its first row.');
                    }
                    Character::query()->whereKey($job['second'])->lockForUpdate()->firstOrFail()->increment('attack_base');
                });
                $result = ['outcome' => 'committed', 'attempts' => $transactions->attempts, 'errors' => $transactions->errors];
                break;
            default:
                throw new RuntimeException('Unknown worker operation.');
        }
        $result['transaction_level'] = DB::transactionLevel();
        $result['connection_id'] = (int) DB::scalar('SELECT CONNECTION_ID()');
        $result['timeout_restored'] = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout') === $previousTimeout;
        $this->check($result['transaction_level'] === 0 && $result['timeout_restored'], 'Worker leaked session state.');

        return $result;
    }

    private function race(array $jobs): array
    {
        return $this->finish($this->launch($jobs));
    }

    private function launch(array $jobs): array
    {
        $this->check(count($jobs) >= 1 && count($jobs) <= 2, 'The harness allows at most two worker processes.');
        $run = bin2hex(random_bytes(16));
        $directory = sys_get_temp_dir().'/valzeria-raid-phase4-'.$run;
        $this->check(mkdir($directory, 0700), 'Cannot create the isolated worker directory.');
        $group = ['directory' => $directory, 'workers' => []];
        $calculations = [];
        try {
            foreach ($jobs as $index => $job) {
                if ($job['op'] === 'resolve') {
                    $key = $job['battle'].':'.$job['damage'];
                    if (! isset($calculations[$key])) {
                        $name = 'calculation-'.$index.'.json';
                        $calculation = $this->calculation(SavedBattle::query()->findOrFail($job['battle']), $job['damage']);
                        $this->check(file_put_contents($directory.'/'.$name, json_encode($calculation, JSON_THROW_ON_ERROR)) !== false,
                            'Cannot freeze the settlement calculation.');
                        $calculations[$key] = $name;
                    }
                    $job['calculation_file'] = $calculations[$key];
                }
                $payload = base64_encode(json_encode([...$job, 'run' => $run, 'index' => $index,
                    'event' => $job['event'] ?? $this->event->id, 'time' => $this->time], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('scripts/verify/nation-raid-phase4-mariadb.php'),
                    'worker', $payload, '--confirm-isolated-database'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->check(is_resource($process), 'Could not start a worker process.');
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $group['workers'][] = ['process' => $process, 'pipes' => $pipes];
            }
            $this->wait(function () use ($jobs, $directory): bool {
                foreach (array_keys($jobs) as $index) {
                    if (! is_file($directory.'/ready-'.$index)) {
                        return false;
                    }
                }

                return true;
            }, 20, 'Workers failed their preflight or did not reach the barrier.');
            touch($directory.'/go');

            return $group;
        } catch (Throwable $exception) {
            $this->cleanup($group);
            throw $exception;
        }
    }

    private function finish(array $group): array
    {
        $results = [];
        try {
            foreach ($group['workers'] as $worker) {
                $output = '';
                $error = '';
                $status = null;
                $this->wait(function () use ($worker, &$output, &$error, &$status): bool {
                    $output .= stream_get_contents($worker['pipes'][1]);
                    $error .= stream_get_contents($worker['pipes'][2]);
                    $this->check(strlen($output) + strlen($error) < 2_000_000, 'Worker output exceeded its budget.');
                    $status = proc_get_status($worker['process']);

                    return ! $status['running'];
                }, 55, 'Worker exceeded its wall-time budget.');
                $output .= stream_get_contents($worker['pipes'][1]);
                $this->check($status['exitcode'] === 0, 'Worker failed: '.substr($output, 0, 1_000));
                $result = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
                $this->check(is_array($result) && ! isset($result['pass']), 'Worker returned a failure record.');
                $results[] = $result;
            }
            $connections = array_column($results, 'connection_id');
            $this->check(count(array_unique($connections)) === count($results)
                && ! in_array((int) DB::scalar('SELECT CONNECTION_ID()'), $connections, true),
                'Concurrency verification requires independent worker/parent database connections.');

            return $results;
        } finally {
            $this->cleanup($group);
        }
    }

    private function cleanup(array $group): void
    {
        foreach ($group['workers'] as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
        // Only named barrier files created by these workers; never recurse or delete DB fixtures.
        foreach (['go', 'held', 'release', 'ready-0', 'ready-1', 'locked-0', 'locked-1', 'calculation-0.json', 'calculation-1.json',
            'reward-writes-staged', 'reward-peer-started'] as $name) {
            $path = $group['directory'].'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($group['directory']);
    }

    private function configure(string $time): void
    {
        $this->time = $time;
        Carbon::setTestNow(CarbonImmutable::parse($time));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($time));
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true, 'features.nation_war_enabled' => false,
            'battle.job_art_v2.dynamic_single' => true, 'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true, 'battle.job_art_v2.resources' => true]);
    }

    private function character(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '競合検証'.bin2hex(random_bytes(4)), 'level' => 30,
            'hp_base' => 20_000, 'mp_base' => 500, 'attack_base' => 3_000, 'defense_base' => 3_000,
            'magic_base' => 500, 'spirit_base' => 3_000, 'speed_base' => 1_000, 'luck_base' => 100,
            'current_hp' => 10, 'current_mp' => 1, 'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(), 'last_battle_at' => now(),
        ]);
    }

    private function start(Character $character): SavedBattle
    {
        return app(NationRaidSortieService::class)->start($this->event, $character, 'assault', bin2hex(random_bytes(32)))[0];
    }

    private function calculation(SavedBattle $battle, int $damage): array
    {
        if (isset($battle->summary['calculation'])) {
            $saved = $battle->summary['calculation'];
            $this->check($saved['engine_result']['calculatedBossDamage'] === $damage, 'Replay must use the originally committed DTO.');

            return $saved;
        }
        $result = app(NationRaidSortieCombatService::class)->resolve($battle);
        $result['engine_result']['calculatedBossDamage'] = $damage;
        $result['engine_result']['maxOneActionDamage'] = min(100, $damage);

        return $result;
    }

    private function settle(SavedBattle $battle, int $damage): SavedBattle
    {
        return app(NationRaidSettlementService::class)->resolve($battle, $this->calculation($battle, $damage));
    }

    private function damage(): int
    {
        return (int) SavedBattle::query()->where('event_id', $this->event->id)->where('status', 'resolved')
            ->selectRaw('COALESCE(SUM(applied_damage_total + coordination_damage_total), 0) AS total')->value('total');
    }

    private function joinedTimes(): array
    {
        return NationRaidCoordinationParticipant::query()->where('event_id', $this->event->id)->orderBy('id')
            ->pluck('window_joined_at', 'id')->map(fn ($at) => (string) $at)->all();
    }

    private function cycle(): NationRaidBossCycle
    {
        return NationRaidBossCycle::query()->where('event_id', $this->event->id)
            ->where('cycle_no', $this->event->refresh()->current_cycle_no)->sole();
    }

    private function assertUsage(Character $character, int $used, int $resolved, int $refunded, int $stamina): void
    {
        $usage = NationRaidDailyUsage::query()->where('event_id', $this->event->id)->where('account_id', $character->user_id)->sole();
        $this->check([$usage->used_count, $usage->resolved_count, $usage->refunded_count, $character->refresh()->explore_stamina]
            === [$used, $resolved, $refunded, $stamina], 'Stamina or daily usage differs from the expected terminal effect.');
    }

    private function outcomes(array $rows, array $expected): void
    {
        $actual = array_column($rows, 'outcome');
        sort($actual);
        sort($expected);
        $this->check($actual === $expected, 'Unexpected worker outcomes: '.json_encode($actual));
    }

    private function wait(callable $condition, int $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache();
            if ($condition()) {
                return;
            }
            usleep(20_000);
        }
        throw new RuntimeException($message);
    }

    private function check(bool $condition, string $message): void
    {
        NationRaidPhase4MariaDbSafety::require($condition, $message);
    }
}

/** Observe the production runner, without replacing retry classification, sleep or transaction code. */
final class NationRaidPhase4ObservedTransactions extends NationRaidTransactionRunner
{
    public array $attempts = [];

    public array $timeouts = [];

    public array $errors = [];

    public array $waitLevels = [];

    public function run(callable $callback): mixed
    {
        return parent::run(function (int $attempt) use ($callback): mixed {
            $this->attempts[] = $attempt;
            $this->timeouts[] = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
            try {
                return $callback($attempt);
            } catch (QueryException $exception) {
                $this->errors[] = (int) ($exception->errorInfo[1] ?? 0);
                throw $exception;
            }
        });
    }

    protected function waitBeforeRetry(int $attempt): void
    {
        $this->waitLevels[] = DB::transactionLevel();
        parent::waitBeforeRetry($attempt);
    }
}
