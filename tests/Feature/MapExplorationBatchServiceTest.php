<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\MapExplorationResult;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\ExplorationMapGenerator;
use App\Services\ExplorationStaminaService;
use App\Services\GameSettingService;
use App\Services\MapExplorationBatchService;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MapExplorationBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_batch_stops_at_the_defeat_run_and_returns_the_normal_batch_stop_text(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図連続探索試験地', 'map-batch-stop-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $batch = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());

        $defeat = new BattleResult();
        $defeat->result = 'defeat';
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($defeat);
        $this->app->instance(BattleService::class, $battleService);

        $execution = $batchService->execute($visitor, $batch);
        $result = $execution['battle_result'];

        $this->assertSame('defeat', $result['result']);
        $this->assertSame(1, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('defeat', data_get($result, 'batch_explore.stop_reason'));
        $stoppedEnemyName = (string) data_get($result, 'batch_explore.runs.0.enemy_name');
        $this->assertSame('1回目の' . $stoppedEnemyName . '戦で敗北したため、途中で探索を止めました。HPは敗北後に最大HPの30%まで回復した状態です。', data_get($result, 'batch_explore.stop_text'));
        $this->assertStringContainsString('【停止理由】', (string) $result['log']);
        $this->assertCount(1, data_get($result, 'batch_explore.runs'));
        $this->assertSame(1, $batch->fresh()->executed_count);
        $this->assertSame(1, $batch->fresh()->reserved_count);
        $this->assertSame(1, $batch->fresh()->results()->count());
        $this->assertSame($remainingBefore - 1, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(10000, (int) $visitor->fresh()->money);
        $this->assertSame(0, (int) data_get($result, 'batch_explore.defeat_loss.gold_amount'));
        $this->assertSame('不思議な加護に守られ、Gold・戦利品・ヴァルモンの卵は失われなかった！', data_get($result, 'batch_explore.defeat_loss.support_label'));
        $this->assertStringContainsString('【駆け出しの加護】不思議な加護があなたを包んだ！', (string) $result['log']);
    }

    public function test_batch_stops_at_timeout_and_applies_defeat_loss_only_once(): void
    {
        config()->set('battle.beginner_defeat_protection.battle_limit', 0);

        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図時間切れ試験地', 'map-batch-timeout-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $batch = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());

        $timeout = new BattleResult();
        $timeout->result = 'timeout';
        $timeout->turnCount = 50;
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($timeout);
        $this->app->instance(BattleService::class, $battleService);

        $execution = $batchService->execute($visitor, $batch);
        $result = $execution['battle_result'];
        $replayedResult = $batchService->execute($visitor, $batch)['battle_result'];

        $this->assertSame('timeout', $result['result']);
        $this->assertSame(1, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('timeout', data_get($result, 'batch_explore.stop_reason'));
        $stoppedEnemyName = (string) data_get($result, 'batch_explore.runs.0.enemy_name');
        $this->assertSame(50, (int) data_get($result, 'batch_explore.runs.0.turn_count'));
        $this->assertSame(
            '1回目の' . $stoppedEnemyName . '戦は50ターンで決着せず、時間切れ敗北となったため探索を終了しました。HPは敗北後に最大HPの30%まで回復した状態です。',
            data_get($result, 'batch_explore.stop_text'),
        );
        $this->assertStringContainsString('時間切れ敗北（50ターン）', (string) $result['log']);
        $this->assertSame(1, $batch->fresh()->executed_count);
        $this->assertSame(1, $batch->fresh()->reserved_count);
        $this->assertSame($remainingBefore - 1, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(9000, (int) $visitor->fresh()->money);
        $this->assertSame(1, (int) $visitor->fresh()->losses);
        $this->assertSame(1000, (int) data_get($result, 'batch_explore.defeat_loss.gold_amount'));
        $this->assertSame('timeout', $replayedResult['result']);
        $this->assertSame(50, $replayedResult['turn_count']);
        $this->assertTrue($replayedResult['timeout_defeat_display']);
    }

    public function test_batch_stops_after_the_sixth_run_defeat_without_repeating_the_loss(): void
    {
        config()->set('battle.beginner_defeat_protection.battle_limit', 0);

        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図連敗防止試験地', 'map-batch-repeated-loss-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $batch = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());

        $victory = new BattleResult();
        $victory->result = 'victory';
        $victory->exp = 20;
        $victory->gold = 0;
        $victory->jobExp = 1;
        $defeat = new BattleResult();
        $defeat->result = 'defeat';
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')
            ->times(6)
            ->andReturn($victory, $victory, $victory, $victory, $victory, $defeat);
        $this->app->instance(BattleService::class, $battleService);

        $execution = $batchService->execute($visitor, $batch);
        $result = $execution['battle_result'];

        $this->assertSame(6, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('defeat', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame(6, $batch->fresh()->executed_count);
        $this->assertSame(6, $batch->fresh()->reserved_count);
        $this->assertSame(6, $batch->fresh()->results()->count());
        $this->assertSame($remainingBefore - 6, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(9000, (int) $visitor->fresh()->money);
        $this->assertSame(1, (int) $visitor->fresh()->losses);
        $this->assertSame(1000, (int) data_get($result, 'batch_explore.defeat_loss.gold_amount'));
    }

    public function test_batch_uses_remaining_stamina_and_stops_without_an_error(): void
    {
        $this->enableStaminaMode();
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図探索力試験地', 'map-batch-stamina-test');
        $visitor->forceFill([
            'explore_stamina' => 2,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ])->save();

        $victory = new BattleResult();
        $victory->result = 'victory';
        $victory->exp = 20;
        $victory->gold = 0;
        $victory->jobExp = 1;
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->twice()->andReturn($victory);
        $this->app->instance(BattleService::class, $battleService);

        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $batch = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());
        $execution = $batchService->execute($visitor, $batch);
        $result = $execution['battle_result'];

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('stamina_empty', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame('探索力が尽きたため、途中で探索を止めました。回復後にまた探索できます。', data_get($result, 'batch_explore.stop_text'));
        $this->assertSame(2, $batch->fresh()->executed_count);
        $this->assertSame(2, $batch->fresh()->reserved_count);
        $this->assertSame(2, $batch->fresh()->results()->count());
        $this->assertSame($remainingBefore - 2, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(0, (int) $visitor->fresh()->explore_stamina);
        $this->assertGreaterThan(0, (int) data_get($result, 'batch_explore.total_exp'));
    }

    public function test_same_request_uuid_reserves_the_exploration_range_only_once(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図二重予約試験地', 'map-batch-idempotency-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $requestUuid = (string) Str::uuid();

        $first = $batchService->reserve($visitor, $registration, 10, $requestUuid);
        $second = $batchService->reserve($visitor, $registration->fresh(), 10, $requestUuid);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($remainingBefore - 10, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(10, (int) $first->reserved_count);
    }

    public function test_separate_reservations_receive_non_overlapping_global_ranges(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図探索番号試験地', 'map-batch-index-range-test');
        $registration->update([
            'exploration_limit' => 12,
            'remaining_explorations' => 12,
            'consumed_explorations' => 0,
        ]);
        $batchService = app(MapExplorationBatchService::class);

        $first = $batchService->reserve($visitor, $registration->fresh(), 10, (string) Str::uuid());
        $second = $batchService->reserve($visitor, $registration->fresh(), 10, (string) Str::uuid());

        $this->assertSame(1, (int) $first->first_exploration_index);
        $this->assertSame(10, (int) $first->last_exploration_index);
        $this->assertSame(11, (int) $second->first_exploration_index);
        $this->assertSame(12, (int) $second->last_exploration_index);
        $this->assertSame(2, (int) $second->reserved_count);
        $this->assertSame(0, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(12, (int) $registration->fresh()->consumed_explorations);
    }

    public function test_batch_reports_map_availability_exhaustion_when_fewer_runs_can_be_reserved(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図残回数試験地', 'map-batch-availability-test');
        $registration->update([
            'exploration_limit' => 3,
            'remaining_explorations' => 3,
            'consumed_explorations' => 0,
        ]);
        $victory = new BattleResult();
        $victory->result = 'victory';
        $victory->exp = 20;
        $victory->gold = 10;
        $victory->jobExp = 1;
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->times(3)->andReturn($victory);
        $this->app->instance(BattleService::class, $battleService);

        $batchService = app(MapExplorationBatchService::class);
        $batch = $batchService->reserve($visitor, $registration->fresh(), 10, (string) Str::uuid());
        $result = $batchService->execute($visitor, $batch)['battle_result'];

        $this->assertSame(10, (int) data_get($result, 'batch_explore.requested'));
        $this->assertSame(3, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('map_availability_exhausted', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame('探索可能回数が尽きたため、途中で探索を止めました。', data_get($result, 'batch_explore.stop_text'));
    }

    public function test_stale_unexecuted_reservation_is_recovered_before_the_next_reservation(): void
    {
        config()->set('exploration_maps.stale_batch_recovery_seconds', 60);
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図予約回収試験地', 'map-batch-stale-recovery-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;

        $stale = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());
        $stale->update(['created_at' => now()->subMinutes(2)]);
        $next = $batchService->reserve($visitor, $registration->fresh(), 1, (string) Str::uuid());

        $this->assertSame('recovered', $stale->fresh()->status);
        $this->assertSame(0, (int) $stale->fresh()->reserved_count);
        $this->assertSame(1, (int) $next->reserved_count);
        $this->assertSame($remainingBefore - 1, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(1, (int) $registration->fresh()->consumed_explorations);
    }

    public function test_stale_non_tail_reservation_does_not_reuse_completed_global_indices(): void
    {
        config()->set('exploration_maps.stale_batch_recovery_seconds', 60);
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図予約順序試験地', 'map-batch-stale-non-tail-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;

        $stale = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());
        $completed = $batchService->reserve($visitor, $registration->fresh(), 10, (string) Str::uuid());

        $victory = new BattleResult();
        $victory->result = 'victory';
        $victory->exp = 20;
        $victory->gold = 0;
        $victory->jobExp = 1;
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->times(11)->andReturn($victory);
        $this->app->instance(BattleService::class, $battleService);

        $batchService->execute($visitor, $completed);
        $stale->update(['created_at' => now()->subMinutes(2)]);

        $next = $batchService->reserve($visitor, $registration->fresh(), 1, (string) Str::uuid());
        $execution = $batchService->execute($visitor, $next);

        $this->assertSame('recovered', $stale->fresh()->status);
        $this->assertSame(0, (int) $stale->fresh()->reserved_count);
        $this->assertSame(11, (int) $completed->first_exploration_index);
        $this->assertSame(20, (int) $completed->last_exploration_index);
        $this->assertSame(21, (int) $next->first_exploration_index);
        $this->assertSame(21, (int) $next->last_exploration_index);
        $this->assertArrayNotHasKey('error', $execution['battle_result']);
        $this->assertSame(range(11, 21), MapExplorationResult::query()
            ->where('map_id', $registration->map_id)
            ->orderBy('global_exploration_index')
            ->pluck('global_exploration_index')
            ->map(fn ($index): int => (int) $index)
            ->all());
        $this->assertSame($remainingBefore - 11, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(11, (int) $registration->fresh()->consumed_explorations);
    }

    public function test_existing_global_index_is_rejected_before_batch_execution(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図番号重複試験地', 'map-batch-overlap-test');
        $batchService = app(MapExplorationBatchService::class);
        $registration->update(['entry_fee_per_exploration' => 1000]);
        $remainingBefore = (int) $registration->remaining_explorations;
        $moneyBefore = (int) $visitor->money;
        $goldTransactionsBefore = DB::table('gold_transactions')->where('character_id', $visitor->id)->count();

        try {
            DB::transaction(function () use ($visitor, $registration, $batchService): void {
                $batch = $batchService->reserve($visitor, $registration, 1, (string) Str::uuid());
                MapExplorationResult::create([
                    'batch_id' => $batch->id,
                    'map_id' => $batch->map_id,
                    'registration_id' => $batch->registration_id,
                    'character_id' => $visitor->id,
                    'global_exploration_index' => $batch->first_exploration_index,
                    'encounter_seed_hash' => str_repeat('a', 64),
                    'reward_seed_hash' => str_repeat('b', 64),
                    'monster_variants_json' => [],
                    'battle_result' => 'victory',
                    'drops_json' => [],
                ]);
                $batchService->execute($visitor, $batch);
            });
            $this->fail('重複する探索番号を持つバッチが実行されました。');
        } catch (\RuntimeException $e) {
            $this->assertSame('地図探索の開始処理が重なりました。探索回数・料金・探索力は消費されていません。もう一度お試しください。', $e->getMessage());
        }

        $this->assertSame($remainingBefore, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(0, (int) $registration->fresh()->consumed_explorations);
        $this->assertSame(0, DB::table('map_exploration_batches')->count());
        $this->assertSame(0, MapExplorationResult::query()->count());
        $this->assertSame($moneyBefore, (int) $visitor->fresh()->money);
        $this->assertSame($goldTransactionsBefore, DB::table('gold_transactions')->where('character_id', $visitor->id)->count());
    }

    /** @return array{Character, \App\Models\TownMapRegistration} */
    private function createPublishedMapAndVisitor(string $areaName, string $areaSlug): array
    {
        config()->set('exploration_maps.reward_profiles.ancient_fragment.weight', 0);
        $city = City::findOrFail(1);
        $area = Area::create(['name' => $areaName, 'slug' => $areaSlug, 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => $areaName . '魔物', 'area_id' => $area->id, 'level' => 45, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        Enemy::create(['name' => $areaName . '古代魔物', 'area_id' => $area->id, 'level' => 160, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $owner = Character::create(['user_id' => User::factory()->create()->id, 'name' => $areaName . '地図主', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $map = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, (string) Str::uuid());
        $registration = app(MapPublicationService::class)->publish($owner, app(MapSurveyService::class)->start($owner, $map, $city), 0);
        $visitor = Character::create(['user_id' => User::factory()->create()->id, 'name' => $areaName . '探索者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);

        return [$visitor, $registration];
    }

    private function enableStaminaMode(): void
    {
        $this->app->instance(GameSettingService::class, new class
        {
            public function getString(string $key, string $default = ''): string
            {
                return $key === 'exploration.mode' ? ExplorationStaminaService::MODE_STAMINA : $default;
            }

            public function getInt(string $key, int $default = 0): int
            {
                return $default;
            }

            public function getBool(string $key, bool $default = false): bool
            {
                return $default;
            }

            public function getFloat(string $key, float $default = 0): float
            {
                return $default;
            }
        });
    }
}
