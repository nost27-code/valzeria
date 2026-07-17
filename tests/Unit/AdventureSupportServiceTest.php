<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\AdventureSupportService;
use App\Services\SupportPassService;
use ReflectionMethod;
use Tests\TestCase;

class AdventureSupportServiceTest extends TestCase
{
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
