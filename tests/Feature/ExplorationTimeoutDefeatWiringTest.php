<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\ExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExplorationTimeoutDefeatWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_boss_battle_keeps_the_neutral_timeout_presentation(): void
    {
        $area = Area::query()->create([
            'name' => '時間切れ配線試験地',
            'slug' => 'timeout-wiring-test-'.uniqid(),
            'city_id' => 1,
            'recommended_level_min' => 1,
            'recommended_level_max' => 1,
        ]);
        Enemy::query()->create([
            'name' => '時間切れ配線試験ボス',
            'area_id' => $area->id,
            'level' => 1,
            'max_hp' => 100,
            'str' => 10,
            'def' => 10,
            'agi' => 10,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
            'exp_reward' => 1,
            'gold_reward' => 1,
            'job_exp_reward' => 1,
            'appearance_weight' => 1,
            'is_boss' => true,
        ]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '時間切れ配線試験者',
            'hp_base' => 100,
            'current_hp' => 100,
            'current_city_id' => 1,
            'highest_city_id' => 1,
        ]);
        $timeout = new BattleResult;
        $timeout->result = 'timeout';
        $timeout->turnCount = 50;
        $timeout->logs = ['双方が疲弊し、戦闘は終了した。'];

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')
            ->once()
            ->withArgs(static function (Character $actualCharacter, Enemy $enemy, int $goldBonus, array $options) use ($character): bool {
                return $actualCharacter->is($character)
                    && $enemy->is_boss
                    && $goldBonus === 0
                    && ($options['timeout_defeat_display'] ?? null) === false;
            })
            ->andReturn($timeout);
        $this->app->instance(BattleService::class, $battleService);

        $result = app(ExplorationService::class)->explore($character, $area->id, true, null, true);

        $this->assertSame('timeout', $result['result']);
        $this->assertSame(50, $result['turn_count']);
        $this->assertFalse($result['timeout_defeat_display']);
        $this->assertStringNotContainsString('時間切れ敗北', (string) $result['log']);
    }
}
