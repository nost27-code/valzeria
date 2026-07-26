<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\CharacterService;
use App\Services\ExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BeginnerBattleProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_new_character_starts_with_one_thousand_gold(): void
    {
        $user = User::factory()->create();
        $job = JobClass::query()->firstOrFail();

        $character = app(CharacterService::class)->createCharacter($user, [
            'name' => '旅立ちの冒険者',
            'gender' => '未設定',
            'job_id' => $job->id,
            'icon_path' => null,
        ]);

        $this->assertSame(1000, (int) $character->money);
        $this->assertDatabaseHas('characters', [
            'id' => $character->id,
            'money' => 1000,
        ]);
    }

    public function test_the_one_hundredth_battle_defeat_does_not_lose_gold(): void
    {
        [$character, $area] = $this->createBattleFixture(99);
        $this->mockDefeat();

        $result = app(ExplorationService::class)->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue((bool) data_get($result, 'beginner_protection.active'));
        $this->assertSame(100, (int) data_get($result, 'beginner_protection.battle_number'));
        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertSame(0, (int) data_get($result, 'gold_loss.amount'));
        $this->assertStringContainsString('【駆け出しの加護】不思議な加護があなたを包んだ！', (string) $result['log']);
        $this->assertDatabaseMissing('gold_transactions', [
            'character_id' => $character->id,
            'type' => 'exploration_defeat_gold_loss',
        ]);
    }

    public function test_the_one_hundred_and_first_battle_uses_the_existing_defeat_loss(): void
    {
        [$character, $area] = $this->createBattleFixture(40, 60);
        $this->mockDefeat();

        $result = app(ExplorationService::class)->explore($character, $area->id, false, null, true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse((bool) data_get($result, 'beginner_protection.active'));
        $this->assertSame(101, (int) data_get($result, 'beginner_protection.battle_number'));
        $this->assertSame(900, (int) $character->fresh()->money);
        $this->assertSame(100, (int) data_get($result, 'gold_loss.amount'));
        $this->assertStringNotContainsString('【駆け出しの加護】', (string) $result['log']);
        $this->assertDatabaseHas('gold_transactions', [
            'character_id' => $character->id,
            'type' => 'exploration_defeat_gold_loss',
            'amount' => -100,
            'balance_after' => 900,
        ]);
    }

    /** @return array{Character, Area} */
    private function createBattleFixture(int $wins, int $losses = 0): array
    {
        config()->set('battle.beginner_defeat_protection.battle_limit', 100);

        $city = City::query()->findOrFail(1);
        $area = Area::query()->create([
            'name' => "駆け出し加護試験地{$wins}-{$losses}",
            'slug' => "beginner-protection-{$wins}-{$losses}",
            'city_id' => $city->id,
            'recommended_level_min' => 1,
            'recommended_level_max' => 1,
        ]);
        Enemy::query()->create([
            'name' => "駆け出し加護試験魔物{$wins}-{$losses}",
            'area_id' => $area->id,
            'level' => 1,
            'max_hp' => 100,
            'str' => 20,
            'def' => 10,
            'agi' => 10,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
            'exp_reward' => 10,
            'gold_reward' => 1,
            'job_exp_reward' => 1,
            'appearance_weight' => 1,
            'is_boss' => false,
        ]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => "加護試験者{$wins}-{$losses}",
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 1000,
            'wins' => $wins,
            'losses' => $losses,
            'current_city_id' => $city->id,
            'highest_city_id' => $city->id,
        ]);

        return [$character, $area];
    }

    private function mockDefeat(): void
    {
        $defeat = new BattleResult();
        $defeat->result = 'defeat';
        $defeat->logs = ['試験戦闘で敗北した。'];
        $defeat->playerHpBefore = 100;
        $defeat->playerHpAfter = 0;
        $defeat->damageTaken = 100;

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')->once()->andReturn($defeat);
        $this->app->instance(BattleService::class, $battleService);
    }
}
