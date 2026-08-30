<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterExplorationState;
use App\Models\Enemy;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\AdventureSupportService;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\ExperienceTalismanService;
use App\Services\ExplorationService;
use App\Services\LevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ExperienceTalismanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_using_experience_talismans_adds_fifty_eligible_victories_each_time(): void
    {
        $character = $this->createCharacter();
        CharacterConsumableItem::query()->create([
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 2,
        ]);

        $item = config('adventure_support.inventory_items.experience_talisman');
        $first = app(AdventureSupportService::class)->useConsumable($character, 'experience_talisman');

        $this->assertSame('経験の護符', $item['name']);
        $this->assertSame(25, $item['effect_value']);
        $this->assertSame(50, $item['eligible_victories']);
        $this->assertFalse($item['is_tradable']);
        $this->assertTrue($first['success']);
        $this->assertStringContainsString('残り50勝', $first['message']);
        $this->assertSame(50, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 1,
        ]);

        $second = app(AdventureSupportService::class)->useConsumable($character->fresh(), 'experience_talisman');

        $this->assertTrue($second['success']);
        $this->assertStringContainsString('残り100勝', $second['message']);
        $this->assertSame(100, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertSame(25, app(ExperienceTalismanService::class)->statusFor($character->fresh())['bonus_percent']);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 0,
        ]);
    }

    public function test_max_level_character_cannot_use_experience_talisman(): void
    {
        $character = $this->createCharacter([
            'level' => 255,
            'experience_talisman_wins_remaining' => 12,
        ]);
        CharacterConsumableItem::query()->create([
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 1,
        ]);

        $result = app(AdventureSupportService::class)->useConsumable($character, 'experience_talisman');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lv255', $result['message']);
        $this->assertSame(12, (int) $character->fresh()->experience_talisman_wins_remaining);
        $status = app(ExperienceTalismanService::class)->statusFor($character->fresh());
        $this->assertTrue($status['active']);
        $this->assertTrue($status['paused_at_level_cap']);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 1,
        ]);
    }

    public function test_experience_talisman_has_no_player_purchase_route(): void
    {
        $character = $this->createCharacter();
        $supportService = app(AdventureSupportService::class);
        $catalogKeys = collect($supportService->catalogFor($character))
            ->flatten(1)
            ->pluck('key')
            ->all();

        $this->assertNotContains('experience_talisman', $catalogKeys);

        $result = $supportService->purchase($character, 'experience_talisman');

        $this->assertFalse($result['success']);
        $this->assertSame('無効な商品です。', $result['message']);
        $this->assertDatabaseMissing('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
        ]);
    }

    public function test_normal_exploration_victory_gains_twenty_five_percent_exp_and_consumes_one_count(): void
    {
        [$character, $area] = $this->createBattleFixture([
            'experience_talisman_wins_remaining' => 50,
        ]);
        $this->mockBattleResult('victory', 100, 40, 2);

        $result = app(NoSpecialEventExperienceTalismanExplorationService::class)
            ->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(125, $result['exp_gained']);
        $this->assertSame(40, $result['gold_gained']);
        $this->assertSame(2, $result['job_exp_gained']);
        $this->assertSame(125, (int) $character->fresh()->exp);
        $this->assertSame(40, (int) $character->fresh()->money);
        $this->assertSame(49, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertSame(25, (int) data_get($result, 'experience_talisman.bonus_exp'));
        $this->assertStringContainsString('【経験の護符】', $result['log']);
        $this->assertDatabaseHas('battle_logs', [
            'character_id' => $character->id,
            'battle_type' => 'normal',
            'exp_gained' => 125,
        ]);
    }

    public function test_defeat_does_not_consume_experience_talisman_count(): void
    {
        [$character, $area] = $this->createBattleFixture([
            'experience_talisman_wins_remaining' => 50,
        ]);
        $this->mockBattleResult('defeat', 0);

        $result = app(NoSpecialEventExperienceTalismanExplorationService::class)
            ->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(0, $result['exp_gained']);
        $this->assertSame(50, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertArrayNotHasKey('experience_talisman', $result);
    }

    public function test_zero_exp_victory_does_not_consume_experience_talisman_count(): void
    {
        $character = $this->createCharacter([
            'experience_talisman_wins_remaining' => 50,
        ]);

        $result = app(ExperienceTalismanService::class)
            ->applyToNormalExplorationVictory($character, 0);

        $this->assertFalse($result['applied']);
        $this->assertSame(0, $result['total_exp']);
        $this->assertSame(50, $result['remaining']);
        $this->assertSame(50, (int) $character->experience_talisman_wins_remaining);
    }

    public function test_depth_reward_minimum_exp_does_not_make_zero_base_exp_eligible(): void
    {
        [$character, $area] = $this->createBattleFixture([
            'experience_talisman_wins_remaining' => 50,
        ]);
        CharacterExplorationState::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'exploration_point' => 300,
            'chain_count' => 1,
            'danger_rate' => 25,
            'depth_tier' => 'deep',
            'started_at' => now(),
        ]);
        $this->mockBattleResult('victory', 0);

        $result = app(NoSpecialEventExperienceTalismanExplorationService::class)
            ->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['exp_gained']);
        $this->assertSame(1, (int) $character->fresh()->exp);
        $this->assertSame(50, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertArrayNotHasKey('experience_talisman', $result);
    }

    public function test_stale_exploration_model_does_not_overwrite_counts_added_by_item_use(): void
    {
        $character = $this->createCharacter([
            'experience_talisman_wins_remaining' => 50,
        ]);
        CharacterConsumableItem::query()->create([
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 1,
        ]);
        $staleExplorationCharacter = $character->fresh();

        $useResult = app(ExperienceTalismanService::class)->use($character->fresh());
        $applyResult = app(ExperienceTalismanService::class)
            ->applyToNormalExplorationVictory($staleExplorationCharacter, 100, 100);

        $this->assertTrue($useResult['success']);
        $this->assertTrue($applyResult['applied']);
        $this->assertSame(99, $applyResult['remaining']);
        $this->assertFalse($staleExplorationCharacter->isDirty('experience_talisman_wins_remaining'));

        // LevelServiceが行う探索報酬保存を模し、古いCharacterの別属性だけを保存する。
        $staleExplorationCharacter->exp = 125;
        $staleExplorationCharacter->save();

        $this->assertSame(99, (int) $character->fresh()->experience_talisman_wins_remaining);
    }

    public function test_reward_failure_rolls_back_experience_talisman_count(): void
    {
        [$character, $area] = $this->createBattleFixture([
            'experience_talisman_wins_remaining' => 50,
        ]);
        $this->mockBattleResult('victory', 100, 40, 2);
        $levelService = Mockery::mock(LevelService::class);
        $levelService->shouldReceive('addRewardAndCheckLevelUp')
            ->once()
            ->andThrow(new \RuntimeException('報酬保存失敗'));
        $this->app->instance(LevelService::class, $levelService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('報酬保存失敗');

        try {
            app(NoSpecialEventExperienceTalismanExplorationService::class)
                ->explore($character, $area->id, false, null, true);
        } finally {
            $this->assertSame(50, (int) $character->fresh()->experience_talisman_wins_remaining);
            $this->assertSame(0, (int) $character->fresh()->exp);
        }
    }

    public function test_exp_bonus_fraction_is_rounded_down(): void
    {
        $character = $this->createCharacter([
            'experience_talisman_wins_remaining' => 1,
        ]);

        $result = app(ExperienceTalismanService::class)
            ->applyToNormalExplorationVictory($character, 101);

        $this->assertTrue($result['applied']);
        $this->assertSame(25, $result['bonus_exp']);
        $this->assertSame(126, $result['total_exp']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_boss_victory_does_not_gain_bonus_or_consume_experience_talisman_count(): void
    {
        [$character, $area] = $this->createBattleFixture([
            'experience_talisman_wins_remaining' => 50,
        ], true);
        $this->mockBattleResult('victory', 100);

        $result = app(NoSpecialEventExperienceTalismanExplorationService::class)
            ->explore($character, $area->id, true, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(100, $result['exp_gained']);
        $this->assertSame(100, (int) $character->fresh()->exp);
        $this->assertSame(50, (int) $character->fresh()->experience_talisman_wins_remaining);
        $this->assertArrayNotHasKey('experience_talisman', $result);
    }

    public function test_inventory_shows_active_experience_talisman_after_the_item_is_consumed(): void
    {
        $user = User::factory()->create();
        $character = $this->createCharacter([
            'user_id' => $user->id,
            'experience_talisman_wins_remaining' => 42,
        ]);
        $valmonMaster = ValmonMaster::query()->create([
            'valmon_key' => 'experience-talisman-inventory',
            'name' => '護符確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('inventory.index'));

        $response->assertOk();
        $response->assertSee('経験の護符');
        $response->assertSee('通常探索の勝利 残り42回');
        $response->assertSee('経験値 +25%');
    }

    public function test_admin_can_grant_experience_talisman_for_reward_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $character = $this->createCharacter();
        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $character->id)
            ->set('grantType', 'support_item')
            ->assertSee('経験の護符')
            ->set('grantTargetId', 'experience_talisman')
            ->set('grantQuantity', 2)
            ->call('grantItem')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'experience_talisman',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('admin_item_grant_logs', [
            'character_id' => $character->id,
            'target_type' => 'support_item',
            'target_id' => 'experience_talisman',
            'target_name' => '経験の護符',
            'quantity' => 2,
        ]);
    }

    private function createCharacter(array $attributes = []): Character
    {
        return Character::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => '経験の護符テスト',
            'level' => 10,
            'exp' => 0,
            'hp_base' => 100,
            'current_hp' => 100,
            'current_mp' => 20,
            'wins' => 0,
            'losses' => 0,
            'current_city_id' => 1,
            'highest_city_id' => 1,
        ], $attributes));
    }

    /** @return array{Character, Area} */
    private function createBattleFixture(array $characterAttributes = [], bool $boss = false): array
    {
        $area = Area::query()->create([
            'name' => $boss ? '護符対象外ボス試験場' : '護符通常探索試験場',
            'slug' => $boss ? 'experience-talisman-boss' : 'experience-talisman-normal',
            'is_published' => true,
            'city_id' => 1,
            'unlock_order' => 1,
            'sort_order' => 1,
        ]);
        Enemy::query()->create([
            'area_id' => $area->id,
            'name' => $boss ? '護符対象外試験ボス' : '護符対象試験敵',
            'level' => 10,
            'enemy_level' => 10,
            'max_hp' => 100,
            'str' => 10,
            'def' => 10,
            'agi' => 10,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
            'exp_reward' => 100,
            'gold_reward' => 0,
            'job_exp_reward' => 0,
            'appearance_weight' => 1,
            'is_boss' => $boss,
            'sort_order' => 1,
            'role' => $boss ? 'ボス' : '通常敵',
            'type_name' => '標準型',
            'element' => '無',
            'action_pattern' => '攻撃する。',
            'species_key' => 'beast',
        ]);

        return [$this->createCharacter($characterAttributes), $area];
    }

    private function mockBattleResult(string $result, int $exp, int $gold = 0, int $jobExp = 0): void
    {
        $battleResult = new BattleResult;
        $battleResult->result = $result;
        $battleResult->logs = [$result === 'victory' ? '試験敵を倒した。' : '試験敵に敗れた。'];
        $battleResult->exp = $exp;
        $battleResult->gold = $gold;
        $battleResult->jobExp = $jobExp;
        $battleResult->playerHpBefore = 100;
        $battleResult->playerHpAfter = $result === 'victory' ? 90 : 0;
        $battleResult->turnCount = 2;

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($battleResult);
        $this->app->instance(BattleService::class, $battleService);
    }
}

class NoSpecialEventExperienceTalismanExplorationService extends ExplorationService
{
    protected function rollSpecialEvent(Character $character, Area $area, Enemy $baseEnemy, $state): ?array
    {
        return null;
    }
}
