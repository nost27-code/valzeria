<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\EquipmentMarketAppraisalService;
use Tests\TestCase;

class EquipmentMarketAppraisalServiceTest extends TestCase
{
    public function test_it_calculates_the_specified_price_range_with_affix_tiers_quality_and_enhancement(): void
    {
        $item = new Item(['weapon_rank' => 'SS']);
        $characterItem = new CharacterItem([
            'affix_prefix_id' => 1, 'affix_prefix_level' => 5,
            'affix_suffix_id' => 2, 'affix_suffix_level' => 5,
            'affix_quality' => 'excellent', 'enhance_level' => 0,
        ]);
        $characterItem->setRelation('item', $item);

        $appraisal = (new EquipmentMarketAppraisalService())->appraisal($characterItem);

        $this->assertSame(5913000, $appraisal['appraisal_price']);
        $this->assertSame(2956500, $appraisal['minimum_price']);
        $this->assertSame(14782500, $appraisal['maximum_price']);
    }

    public function test_it_calculates_sale_fee_with_integer_math(): void
    {
        $service = new EquipmentMarketAppraisalService();
        $this->assertSame(60000, $service->fee(600000));
        $this->assertSame(900, $service->sellerProceeds(999));
    }
}
