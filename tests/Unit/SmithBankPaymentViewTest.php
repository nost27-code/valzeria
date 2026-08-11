<?php

namespace Tests\Unit;

use Tests\TestCase;

class SmithBankPaymentViewTest extends TestCase
{
    public function test_every_evolution_action_provides_the_numeric_gold_cost_for_bank_confirmation(): void
    {
        $source = file_get_contents(resource_path('views/smith/_evolution_detail.blade.php'));

        $this->assertIsString($source);
        $this->assertSame(
            3,
            substr_count($source, 'goldCostAmount: {{ (int) ($candidate[\'gold_cost\'] ?? 0) }}')
        );
    }

    public function test_evolution_confirmation_uses_the_numeric_cost_for_bank_payment(): void
    {
        $source = file_get_contents(resource_path('views/smith/index.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            'name="use_bank" :value="bankPaymentFor(selected?.goldCostAmount) > 0 ? 1 : 0"',
            $source
        );
        $this->assertStringContainsString(
            'x-show="bankPaymentFor(selected?.goldCostAmount) > 0"',
            $source
        );
        $this->assertStringContainsString(
            '銀行預金から <span x-text="new Intl.NumberFormat(\'ja-JP\').format(bankPaymentFor(selected?.goldCostAmount)) + \'G\'"',
            $source
        );
    }
}
