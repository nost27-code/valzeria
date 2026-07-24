<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
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
        $city = City::findOrFail(1);
        $area = Area::create(['name' => '地図連続探索試験地', 'slug' => 'map-batch-stop-test', 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => '地図連続探索試験魔物', 'area_id' => $area->id, 'level' => 45, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
        $owner = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図主', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
        $map = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, '00000000-0000-4000-8000-000000000003');
        $registration = app(MapPublicationService::class)->publish($owner, app(MapSurveyService::class)->start($owner, $map, $city), 0);
        $visitor = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図探索者', 'hp_base' => 100, 'current_hp' => 100, 'money' => 10000]);
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
        $this->assertSame(9000, (int) $visitor->fresh()->money);
        $this->assertSame(1000, (int) data_get($result, 'batch_explore.defeat_loss.gold_amount'));
    }

    public function test_batch_stops_at_timeout_and_applies_defeat_loss_only_once(): void
    {
        [$visitor, $registration] = $this->createPublishedMapAndVisitor('地図時間切れ試験地', 'map-batch-timeout-test');
        $batchService = app(MapExplorationBatchService::class);
        $remainingBefore = (int) $registration->remaining_explorations;
        $batch = $batchService->reserve($visitor, $registration, 10, (string) Str::uuid());

        $timeout = new BattleResult();
        $timeout->result = 'timeout';
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($timeout);
        $this->app->instance(BattleService::class, $battleService);

        $execution = $batchService->execute($visitor, $batch);
        $result = $execution['battle_result'];

        $this->assertSame('timeout', $result['result']);
        $this->assertSame(1, (int) data_get($result, 'batch_explore.completed'));
        $this->assertSame('timeout', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame(1, $batch->fresh()->executed_count);
        $this->assertSame(1, $batch->fresh()->reserved_count);
        $this->assertSame($remainingBefore - 1, (int) $registration->fresh()->remaining_explorations);
        $this->assertSame(9000, (int) $visitor->fresh()->money);
        $this->assertSame(1, (int) $visitor->fresh()->losses);
        $this->assertSame(1000, (int) data_get($result, 'batch_explore.defeat_loss.gold_amount'));
    }

    public function test_batch_stops_after_the_sixth_run_defeat_without_repeating_the_loss(): void
    {
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

    /** @return array{Character, \App\Models\TownMapRegistration} */
    private function createPublishedMapAndVisitor(string $areaName, string $areaSlug): array
    {
        $city = City::findOrFail(1);
        $area = Area::create(['name' => $areaName, 'slug' => $areaSlug, 'city_id' => $city->id, 'recommended_level_min' => 20, 'recommended_level_max' => 30]);
        $enemy = Enemy::create(['name' => $areaName . '魔物', 'area_id' => $area->id, 'level' => 45, 'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20, 'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false]);
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
