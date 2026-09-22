<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterExplorationState;
use App\Models\User;
use App\Services\DropService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ExplorationDangerRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_danger_reward_bonus_stops_increasing_at_one_hundred_percent(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '危険度境界試験',
        ]);
        $state = CharacterExplorationState::query()->create([
            'character_id' => $character->id,
            'danger_rate' => 0,
        ]);
        $baseRates = [
            'material' => 60,
            'weapon' => 0.75,
            'armor' => 0.75,
            'accessory' => 0.25,
        ];

        $this->assertSame($baseRates, $this->ratesAt($character, $state, 74, $baseRates));
        $this->assertSame([
            'material' => 65,
            'weapon' => 1.25,
            'armor' => 1.25,
            'accessory' => 0.25,
        ], $this->ratesAt($character, $state, 75, $baseRates));

        $atOneHundred = $this->ratesAt($character, $state, 100, $baseRates);
        $this->assertSame([
            'material' => 70,
            'weapon' => 1.75,
            'armor' => 1.75,
            'accessory' => 0.75,
        ], $atOneHundred);
        $this->assertSame($atOneHundred, $this->ratesAt($character, $state, 2000, $baseRates));
    }

    private function ratesAt(
        Character $character,
        CharacterExplorationState $state,
        int $dangerRate,
        array $baseRates
    ): array {
        $state->forceFill(['danger_rate' => $dangerRate])->save();

        $method = new ReflectionMethod(DropService::class, 'withDangerRewardBonus');
        $method->setAccessible(true);

        return $method->invoke(app(DropService::class), $baseRates, $character);
    }
}
