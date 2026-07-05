<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\SupportPassService;
use Illuminate\Support\Carbon;
use ReflectionProperty;
use Tests\TestCase;

class SupportPassServiceTest extends TestCase
{
    public function test_displayed_card_skin_uses_support_pass_only_while_active(): void
    {
        config(['support_pass.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-05 12:00:00'));

        try {
            $service = new SupportPassService();
            $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
            $schemaReady->setValue($service, true);

            $activeUser = new User([
                'support_pass_expires_at' => Carbon::parse('2026-07-06 12:00:00'),
                'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS,
            ]);

            $expiredUser = new User([
                'support_pass_expires_at' => Carbon::parse('2026-07-04 12:00:00'),
                'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS,
            ]);

            $this->assertSame(SupportPassService::CARD_SKIN_SUPPORT_PASS, $service->displayedCardSkin($activeUser));
            $this->assertSame(SupportPassService::CARD_SKIN_SUPPORT_PASS, $service->selectedCardSkin($expiredUser));
            $this->assertSame(SupportPassService::CARD_SKIN_DEFAULT, $service->displayedCardSkin($expiredUser));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_support_pass_is_inactive_when_feature_is_disabled(): void
    {
        config(['support_pass.enabled' => false]);

        $service = new SupportPassService();
        $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
        $schemaReady->setValue($service, true);

        $user = new User([
            'support_pass_expires_at' => Carbon::now()->addDay(),
            'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS,
        ]);

        $this->assertFalse($service->isActive($user));
        $this->assertSame(SupportPassService::CARD_SKIN_DEFAULT, $service->displayedCardSkin($user));
    }
}
