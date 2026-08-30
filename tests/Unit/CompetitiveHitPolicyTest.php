<?php

namespace Tests\Unit;

use App\Services\Battle\CompetitiveHitPolicy;
use Tests\TestCase;

class CompetitiveHitPolicyTest extends TestCase
{
    public function test_player_pvp_and_champ_share_hit_bounds_but_keep_separate_vital_caps(): void
    {
        $policy = app(CompetitiveHitPolicy::class);

        $this->assertSame([
            'agi_factor' => 0.08,
            'min_rate' => 84,
            'normal_max_rate' => 97,
            'aim_max_rate' => 99,
            'vital_hit_max_rate' => 12.0,
        ], $policy->rulesFor('pvp'));
        $this->assertSame([
            'agi_factor' => 0.15,
            'min_rate' => 84,
            'normal_max_rate' => 97,
            'aim_max_rate' => 99,
            'vital_hit_max_rate' => 20.0,
        ], $policy->rulesFor('champ'));
        $this->assertFalse($policy->supports('arena_npc'));
    }

    public function test_accuracy_overflow_and_card_bonus_stack_only_up_to_each_route_cap(): void
    {
        $policy = app(CompetitiveHitPolicy::class);

        $this->assertSame(12.0, $policy->vitalHitChance('pvp', 5.0, 10.0));
        $this->assertSame(15.0, $policy->vitalHitChance('champ', 5.0, 10.0));
        $this->assertSame(20.0, $policy->vitalHitChance('champ', 10.0, 15.0));
        $this->assertSame(0.0, $policy->vitalHitChance('pvp', -5.0, -10.0));
    }

    public function test_fifty_percent_vital_damage_is_limited_to_six_and_ten_percent_expected_uplift(): void
    {
        $policy = app(CompetitiveHitPolicy::class);
        $criticalExtraMultiplier = 1.50 - 1.0;

        $pvpUplift = $policy->rulesFor('pvp')['vital_hit_max_rate'] / 100 * $criticalExtraMultiplier;
        $champUplift = $policy->rulesFor('champ')['vital_hit_max_rate'] / 100 * $criticalExtraMultiplier;

        $this->assertEqualsWithDelta(0.06, $pvpUplift, 0.000001);
        $this->assertEqualsWithDelta(0.10, $champUplift, 0.000001);
    }
}
