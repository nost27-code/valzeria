<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Services\SecretRealmService;
use ReflectionMethod;
use Tests\TestCase;

class SecretRealmServiceTest extends TestCase
{
    public function test_area_reward_tier_limits_early_secret_realm_lord_rewards(): void
    {
        $tier = $this->areaRewardTier(new Area([
            'city_id' => 1,
            'recommended_level_min' => 1,
            'recommended_level_max' => 15,
        ]));

        $this->assertSame(60, $tier['min_lord_level']);
        $this->assertSame(1, $tier['lord_shards_min']);
        $this->assertSame(1, $tier['lord_shards_max']);
    }

    public function test_area_reward_tier_raises_late_secret_realm_lord_baseline(): void
    {
        $tier = $this->areaRewardTier(new Area([
            'city_id' => 10,
            'recommended_level_min' => 127,
            'recommended_level_max' => 141,
        ]));

        $this->assertSame(150, $tier['min_lord_level']);
        $this->assertSame(2, $tier['lord_shards_min']);
        $this->assertSame(3, $tier['lord_shards_max']);
    }

    private function areaRewardTier(Area $area): array
    {
        $method = new ReflectionMethod(SecretRealmService::class, 'areaRewardTier');

        return $method->invoke(new SecretRealmService(), $area);
    }
}
