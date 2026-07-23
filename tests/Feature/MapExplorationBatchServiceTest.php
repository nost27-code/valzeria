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
    }
}
