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

    public function test_support_pass_disabled_label_keeps_extension_limit_message(): void
    {
        $label = $this->supportPassPurchaseLabel([
            'can_purchase' => false,
            'disabled_reason' => '冒険者支援パスは最大90日先まで延長できます。現在はこれ以上延長できません。',
        ]);

        $this->assertSame('これ以上延長できません', $label);
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
