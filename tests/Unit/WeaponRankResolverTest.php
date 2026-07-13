<?php

namespace Tests\Unit;

use App\Support\WeaponRankResolver;
use PHPUnit\Framework\TestCase;

class WeaponRankResolverTest extends TestCase
{
    public function test_non_special_weapons_return_their_own_rank_uppercased(): void
    {
        $this->assertSame('EPIC', WeaponRankResolver::effectiveRank('epic', null));
        $this->assertSame('SSS', WeaponRankResolver::effectiveRank('SSS', 'なにか'));
        $this->assertNull(WeaponRankResolver::effectiveRank(null, null));
    }

    public function test_special_weapons_resolve_effective_rank_from_display_rank(): void
    {
        $this->assertSame('A', WeaponRankResolver::effectiveRank('SPECIAL', 'A+相当'));
        $this->assertSame('S', WeaponRankResolver::effectiveRank('SPECIAL', 'S+相当'));
        $this->assertSame('SS', WeaponRankResolver::effectiveRank('special', 'SS+相当'));
    }

    public function test_special_weapon_without_parseable_display_rank_is_unresolved(): void
    {
        $this->assertNull(WeaponRankResolver::effectiveRank('SPECIAL', null));
        $this->assertNull(WeaponRankResolver::effectiveRank('SPECIAL', '限定品'));
    }

    public function test_star_tree_tower_floor90_reward_is_treated_as_ss_or_above(): void
    {
        // 星樹の塔F90報酬(config/star_tree_tower_rewards.php: display_rank='SS+相当')
        $rank = WeaponRankResolver::effectiveRank('SPECIAL', 'SS+相当');

        $this->assertSame('SS', $rank);
        $this->assertGreaterThanOrEqual(
            WeaponRankResolver::order('SS'),
            WeaponRankResolver::order($rank),
        );
    }

    public function test_order_reflects_rank_hierarchy(): void
    {
        $this->assertGreaterThan(WeaponRankResolver::order('A'), WeaponRankResolver::order('S'));
        $this->assertGreaterThan(WeaponRankResolver::order('SSS'), WeaponRankResolver::order('EPIC'));
        $this->assertSame(0, WeaponRankResolver::order('G'));
    }
}
