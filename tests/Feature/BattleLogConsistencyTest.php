<?php

namespace Tests\Feature;

use App\Livewire\CityHeader;
use App\Models\Area;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleLogService;
use App\Services\BattleService;
use App\Services\ExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class BattleLogConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_results_are_normalized_and_adventure_record_totals_remain_consistent(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '戦闘記録整合テスト',
        ]);
        $area = $this->createArea('戦闘記録の草原', 'battle-log-consistency');
        $enemy = $this->createEnemy($area);
        $service = app(BattleLogService::class);

        $event = $service->addLog(
            $character,
            $area->id,
            $enemy->id,
            'normal',
            'event',
            0,
            0,
            0,
            0,
            '宝箱を発見した',
            null,
            null,
            0,
            ['turn_count' => 0],
            false,
        );

        $this->assertSame(BattleLog::RESULT_EVENT, $event->result);
        $this->assertDatabaseMissing('player_lifecycle_events', [
            'character_id' => $character->id,
            'event_name' => 'first_battle',
        ]);

        $victory = $service->addLog($character, $area->id, $enemy->id, 'normal', 'victory', 1, 0, 1, 0, '通常探索の勝利', null, null, 0, ['turn_count' => 2]);
        $subAreaVictory = $service->addLog($character, $area->id, $enemy->id, 'sub_area', 'victory', 1, 0, 1, 0, '亜域の勝利', null, null, 0, ['turn_count' => 2]);
        $defeat = $service->addLog($character, $area->id, $enemy->id, 'normal', 'defeat', 0, 0, 0, 0, '敗北', null, null, 0, ['turn_count' => 3]);
        $timeout = $service->addLog($character, $area->id, $enemy->id, 'normal', 'timeout', 0, 0, 0, 0, '時間切れ', null, null, 0, ['turn_count' => 20]);

        $this->assertSame(BattleLog::RESULT_WIN, $victory->result);
        $this->assertSame(BattleLog::RESULT_WIN, $subAreaVictory->result);
        $this->assertSame(BattleLog::RESULT_LOSE, $defeat->result);
        $this->assertSame(BattleLog::RESULT_LOSE, $timeout->result);
        $this->assertDatabaseHas('player_lifecycle_events', [
            'character_id' => $character->id,
            'event_name' => 'first_battle',
        ]);

        $this->createLegacyLog($character, $area, $enemy, 'victory', null, 'boss');
        $this->createLegacyLog($character, $area, $enemy, 'defeat', null);
        $this->createLegacyLog($character, $area, $enemy, 'timeout', 20);
        $this->createLegacyLog($character, $area, $enemy, 'win', 0);

        $recordsMethod = new ReflectionMethod(CityHeader::class, 'adventureRecords');
        $recordsMethod->setAccessible(true);
        $records = collect($recordsMethod->invoke(new CityHeader(), $character))->keyBy('label');

        $this->assertSame('7', $records->get('戦闘回数')['value']);
        $this->assertSame('3', $records->get('勝利数')['value']);
        $this->assertSame('4', $records->get('敗北数')['value']);
        $this->assertSame('42', $records->get('勝率')['value']);
        $this->assertSame('1', $records->get('ボス討伐数')['value']);
        $this->assertSame(
            (int) $records->get('戦闘回数')['value'],
            (int) $records->get('勝利数')['value'] + (int) $records->get('敗北数')['value'],
        );
    }

    public function test_non_combat_exploration_event_does_not_increment_or_record_a_battle_win(): void
    {
        $character = $this->createExplorationCharacter('非戦闘イベント整合テスト');
        $area = $this->createArea('非戦闘イベントの草原', 'non-combat-event-consistency');
        $this->createEnemy($area);

        $result = app(ForcedTreasureExplorationService::class)
            ->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('victory', $result['result']);
        $this->assertSame(12, (int) $character->fresh()->wins);
        $this->assertDatabaseHas('battle_logs', [
            'character_id' => $character->id,
            'result' => BattleLog::RESULT_EVENT,
            'turn_count' => 0,
        ]);
        $this->assertSame(0, BattleLog::query()
            ->where('character_id', $character->id)
            ->actualBattles()
            ->count());
        $this->assertDatabaseMissing('player_lifecycle_events', [
            'character_id' => $character->id,
            'event_name' => 'first_battle',
        ]);
    }

    public function test_normal_exploration_victory_increments_and_records_one_canonical_win(): void
    {
        $character = $this->createExplorationCharacter('通常探索整合テスト');
        $area = $this->createArea('通常探索の草原', 'normal-battle-consistency');
        $this->createEnemy($area);

        $victory = new BattleResult();
        $victory->result = 'victory';
        $victory->logs = ['通常探索で勝利した。'];
        $victory->exp = 10;
        $victory->playerHpBefore = 100;
        $victory->playerHpAfter = 90;
        $victory->turnCount = 2;

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($victory);
        $this->app->instance(BattleService::class, $battleService);

        $result = app(ExplorationService::class)
            ->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('victory', $result['result']);
        $this->assertSame(13, (int) $character->fresh()->wins);
        $this->assertSame(10, (int) $character->fresh()->exp);
        $this->assertDatabaseHas('battle_logs', [
            'character_id' => $character->id,
            'result' => BattleLog::RESULT_WIN,
            'turn_count' => 2,
        ]);
        $this->assertSame(1, BattleLog::query()
            ->where('character_id', $character->id)
            ->actualBattles()
            ->count());
    }

    private function createExplorationCharacter(string $name): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'level' => 1,
            'exp' => 0,
            'hp_base' => 100,
            'current_hp' => 100,
            'wins' => 12,
            'losses' => 3,
            'current_city_id' => 1,
            'highest_city_id' => 1,
        ]);
    }

    private function createArea(string $name, string $slug): Area
    {
        return Area::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_published' => true,
            'city_id' => 1,
            'unlock_order' => 1,
            'sort_order' => 1,
        ]);
    }

    private function createEnemy(Area $area): Enemy
    {
        return Enemy::query()->create([
            'area_id' => $area->id,
            'name' => '戦闘記録テスト敵',
            'level' => 10,
            'enemy_level' => 10,
            'max_hp' => 120,
            'str' => 20,
            'def' => 18,
            'agi' => 15,
            'mag' => 12,
            'spr' => 14,
            'luk' => 8,
            'exp_reward' => 30,
            'gold_reward' => 5,
            'job_exp_reward' => 2,
            'appearance_weight' => 10,
            'is_boss' => false,
            'sort_order' => 1,
            'role' => '通常敵',
            'type_name' => '標準型',
            'element' => '無',
            'action_pattern' => '様子を見ながら攻撃する。',
            'species_key' => 'beast',
        ]);
    }

    private function createLegacyLog(
        Character $character,
        Area $area,
        Enemy $enemy,
        string $result,
        ?int $turnCount,
        string $battleType = 'normal',
    ): void {
        BattleLog::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'enemy_id' => $enemy->id,
            'battle_type' => $battleType,
            'result' => $result,
            'exp_gained' => 0,
            'gold_gained' => 0,
            'job_exp_gained' => 0,
            'level_up_count' => 0,
            'log_text' => '旧形式の戦闘記録',
            'turn_count' => $turnCount,
        ]);
    }
}

class ForcedTreasureExplorationService extends ExplorationService
{
    protected function rollSpecialEvent(Character $character, Area $area, Enemy $baseEnemy, $state): ?array
    {
        return [
            'type' => 'treasure',
            'enemy' => $baseEnemy,
            'source_enemy' => $baseEnemy,
        ];
    }
}
