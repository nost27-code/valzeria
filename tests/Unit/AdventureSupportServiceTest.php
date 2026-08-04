<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\AdventureSupportService;
use App\Services\SupportPassService;
use ReflectionMethod;
use Tests\TestCase;

class AdventureSupportServiceTest extends TestCase
{
    public function test_storage_expansion_purchase_limits_match_current_balance(): void
    {
        $items = config('adventure_support.items');

        $this->assertSame(30, $items['material_storage_expand']['purchase_limit']);
        $this->assertSame(50, $items['material_storage_expand']['price']);
        $this->assertSame(500, $items['material_storage_expand']['effect_value']);
        $this->assertSame(10, $items['material_storage_gold_expand']['purchase_limit']);
        $this->assertSame(20, $items['equipment_storage_expand']['purchase_limit']);
    }

    public function test_support_pass_disabled_label_uses_purchase_unavailable_for_kiseki_shortage(): void
    {
        $label = $this->supportPassPurchaseLabel([
            'can_purchase' => false,
            'disabled_reason' => '輝石が不足しています。輝石を購入してから再度お試しください。',
        ]);

        $this->assertSame('購入不可', $label);
    }

    public function test_support_pass_purchase_label_describes_ticket_purchase(): void
    {
        $label = $this->supportPassPurchaseLabel([
            'can_purchase' => true,
            'disabled_reason' => null,
        ]);

        $this->assertSame('利用券を購入', $label);
    }

    private function supportPassPurchaseLabel(array $state): string
    {
        $method = new ReflectionMethod(AdventureSupportService::class, 'purchaseLabel');
        $method->setAccessible(true);

        return $method->invoke(
            new AdventureSupportService(),
            new Character(),
            SupportPassService::PASS_TYPE,
            ['effect_type' => SupportPassService::PASS_TYPE],
            $state
        );
    }
}
