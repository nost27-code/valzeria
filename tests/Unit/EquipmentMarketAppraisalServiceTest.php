<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\EquipmentMarketAppraisalService;
use Tests\TestCase;

class EquipmentMarketAppraisalServiceTest extends TestCase
{
    public function test_it_uses_the_new_shared_trait_appraisal_values(): void
    {
        foreach ([1 => 5000, 2 => 15000, 3 => 50000, 4 => 150000, 5 => 500000] as $level => $expected) {
            $appraisal = $this->appraisal('SS', $level);

            $this->assertSame($expected, $appraisal['trait_appraisal_price']);
            $this->assertSame(600000 + $expected, $appraisal['appraisal_price']);
        }

        $slayerOnly = $this->appraisal('SS', null, 3);
        $this->assertSame(50000, $slayerOnly['trait_appraisal_price']);
    }

    public function test_it_applies_the_second_trait_at_sixty_percent(): void
    {
        $engravingPrimary = $this->appraisal('SS', 5, 3);
        $slayerPrimary = $this->appraisal('SS', 3, 5);
        $sameLevelTraits = $this->appraisal('SS', 5, 5);

        $this->assertSame(530000, $engravingPrimary['trait_appraisal_price']);
        $this->assertSame(530000, $slayerPrimary['trait_appraisal_price']);
        $this->assertSame(800000, $sameLevelTraits['trait_appraisal_price']);
        $this->assertSame([], $sameLevelTraits['trait_breakdown']);
        $this->assertSame(2, $sameLevelTraits['trait_count']);
    }

    public function test_it_matches_the_specification_examples_and_only_multiplies_the_body(): void
    {
        $aDoubleThree = $this->appraisal('A', 3, 3);
        $sDoubleFour = $this->appraisal('S', 4, 4);
        $ssDoubleFive = $this->appraisal('SS', 5, 5);
        $excellentDoubleFive = $this->appraisal('SS', 5, 5, 'excellent');
        $enhancedSingleFive = $this->appraisal('SS', 5, null, 'excellent', 3);

        $this->assertSame(100000, $aDoubleThree['body_appraisal_price']);
        $this->assertSame(80000, $aDoubleThree['trait_appraisal_price']);
        $this->assertSame(180000, $aDoubleThree['appraisal_price']);
        $this->assertSame(90000, $aDoubleThree['minimum_price']);
        $this->assertSame(450000, $aDoubleThree['maximum_price']);

        $this->assertSame(490000, $sDoubleFour['appraisal_price']);
        $this->assertSame(1400000, $ssDoubleFive['appraisal_price']);
        $this->assertSame(700000, $ssDoubleFive['minimum_price']);
        $this->assertSame(3500000, $ssDoubleFive['maximum_price']);

        $this->assertSame(810000, $excellentDoubleFive['body_appraisal_price']);
        $this->assertSame(800000, $excellentDoubleFive['trait_appraisal_price']);
        $this->assertSame(1610000, $excellentDoubleFive['appraisal_price']);
        $this->assertSame(4025000, $excellentDoubleFive['maximum_price']);

        $this->assertSame(891000, $enhancedSingleFive['body_appraisal_price']);
        $this->assertSame(500000, $enhancedSingleFive['trait_appraisal_price']);
        $this->assertSame(1391000, $enhancedSingleFive['appraisal_price']);
    }

    public function test_weapon_category_does_not_change_trait_appraisal(): void
    {
        $sword = $this->appraisal('SS', 5, null, 'normal', 0, 'sword');
        $staff = $this->appraisal('SS', 5, null, 'normal', 0, 'staff');

        $this->assertSame(500000, $sword['trait_appraisal_price']);
        $this->assertSame($sword['trait_appraisal_price'], $staff['trait_appraisal_price']);
        $this->assertSame(2, $sword['appraisal_version']);
    }

    public function test_it_calculates_sale_fee_with_integer_math(): void
    {
        $service = new EquipmentMarketAppraisalService();

        $this->assertSame(60000, $service->fee(600000));
        $this->assertSame(900, $service->sellerProceeds(999));
    }

    private function appraisal(
        string $rank,
        ?int $prefixLevel = null,
        ?int $suffixLevel = null,
        string $quality = 'normal',
        int $enhanceLevel = 0,
        string $weaponCategory = 'sword',
    ): array {
        $item = new Item(['weapon_rank' => $rank, 'weapon_category' => $weaponCategory]);
        $attributes = ['affix_quality' => $quality, 'enhance_level' => $enhanceLevel];
        if ($prefixLevel !== null) {
            $attributes['affix_prefix_id'] = 1;
            $attributes['affix_prefix_level'] = $prefixLevel;
        }
        if ($suffixLevel !== null) {
            $attributes['affix_suffix_id'] = 2;
            $attributes['affix_suffix_level'] = $suffixLevel;
        }

        $characterItem = new CharacterItem($attributes);
        $characterItem->setRelation('item', $item);

        return (new EquipmentMarketAppraisalService())->appraisal($characterItem);
    }
}
