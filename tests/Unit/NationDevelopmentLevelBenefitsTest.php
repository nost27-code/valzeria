<?php

namespace Tests\Unit;

use App\Services\Nation\NationDevelopmentLevelService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NationDevelopmentLevelBenefitsTest extends TestCase
{
    #[DataProvider('benefitBoundaryProvider')]
    public function test_level_benefit_boundaries_follow_the_approved_table(
        int $level,
        int $capacity,
        int $facilityCap,
        int $goalSlots,
        int $wantedSlots,
        int $showcaseSlots,
        int $presetSlots,
    ): void {
        $benefits = app(NationDevelopmentLevelService::class)->benefitsForLevel($level);

        $this->assertSame($capacity, $benefits['member_capacity']);
        $this->assertSame($facilityCap, $benefits['facility_level_cap']);
        $this->assertSame($goalSlots, $benefits['goal_slots']);
        $this->assertSame($wantedSlots, $benefits['wanted_material_slots']);
        $this->assertSame($showcaseSlots, $benefits['showcase_slots']);
        $this->assertSame($presetSlots, $benefits['war_preset_slots']);
    }

    public static function benefitBoundaryProvider(): array
    {
        return [
            'Lv1' => [1, 20, 5, 1, 1, 0, 0],
            'Lv5' => [5, 22, 5, 2, 1, 0, 0],
            'Lv10' => [10, 24, 5, 2, 2, 1, 0],
            'Lv15' => [15, 26, 6, 3, 2, 1, 0],
            'Lv20' => [20, 28, 7, 3, 2, 1, 1],
            'Lv25' => [25, 30, 8, 3, 3, 2, 1],
            'Lv30' => [30, 32, 9, 3, 3, 2, 2],
            'Lv35' => [35, 34, 10, 3, 3, 2, 2],
            'Lv40' => [40, 36, 10, 3, 5, 3, 2],
            'Lv45' => [45, 38, 10, 3, 5, 3, 2],
            'Lv50' => [50, 40, 10, 3, 5, 3, 3],
        ];
    }

    public function test_experience_resolves_capacity_and_next_unlock_without_float_math(): void
    {
        $service = app(NationDevelopmentLevelService::class);

        $this->assertSame(20, $service->memberCapacityForExperience(4_999));
        $this->assertSame(22, $service->memberCapacityForExperience(5_000));
        $this->assertSame(40, $service->memberCapacityForExperience(612_500));
        $this->assertSame(15, $service->nextBenefitAfterLevel(14)['level']);
        $this->assertNull($service->nextBenefitAfterLevel(50));
    }
}
