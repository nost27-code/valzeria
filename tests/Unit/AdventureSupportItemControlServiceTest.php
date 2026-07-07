<?php

namespace Tests\Unit;

use App\Models\GameSetting;
use App\Services\AdventureSupportItemControlService;
use App\Services\GameSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdventureSupportItemControlServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('game_settings');
        Schema::create('game_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->string('label', 100);
            $table->string('value', 100);
            $table->string('value_type', 20);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        app(GameSettingService::class)->flush();
    }

    protected function tearDown(): void
    {
        app(GameSettingService::class)->flush();
        Schema::dropIfExists('game_settings');

        parent::tearDown();
    }

    public function test_sale_state_defaults_to_config_suspension_flag(): void
    {
        $service = app(AdventureSupportItemControlService::class);

        $this->assertTrue($service->isEnabled('explore_stamina_small_bottle'));
        $this->assertFalse($service->isEnabled('rescue_insurance'));
    }

    public function test_setting_overrides_default_sale_state(): void
    {
        $service = app(AdventureSupportItemControlService::class);

        $service->setEnabled('rescue_insurance', true);
        $service->setEnabled('explore_stamina_small_bottle', false);

        $this->assertTrue($service->isEnabled('rescue_insurance'));
        $this->assertFalse($service->isEnabled('explore_stamina_small_bottle'));
        $this->assertDatabaseHas('game_settings', [
            'setting_key' => 'adventure_support.item_enabled.rescue_insurance',
            'value' => '1',
            'value_type' => 'boolean',
        ]);
        $this->assertSame(2, GameSetting::query()
            ->where('setting_key', 'like', AdventureSupportItemControlService::KEY_PREFIX . '%')
            ->count());
    }

    public function test_visibility_state_defaults_to_visible_and_can_be_overridden(): void
    {
        $service = app(AdventureSupportItemControlService::class);

        $this->assertTrue($service->isVisible('support_pass_30d'));

        $service->setVisible('support_pass_30d', false);
        $this->assertFalse($service->isVisible('support_pass_30d'));
        $this->assertDatabaseHas('game_settings', [
            'setting_key' => 'adventure_support.item_visible.support_pass_30d',
            'value' => '0',
            'value_type' => 'boolean',
        ]);

        $service->setVisible('support_pass_30d', true);
        $this->assertTrue($service->isVisible('support_pass_30d'));
    }

    public function test_campaign_price_is_applied_only_during_campaign_period(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-06 12:00:00', 'Asia/Tokyo'));

        try {
            $service = app(AdventureSupportItemControlService::class);
            $item = config('adventure_support.items.explore_stamina_small_bottle');

            $service->setCampaign(
                'explore_stamina_small_bottle',
                5,
                '2026-07-06T00:00',
                '2026-07-07T00:00'
            );

            $effectiveItem = $service->effectiveItem('explore_stamina_small_bottle', $item);

            $this->assertSame(5, $effectiveItem['price']);
            $this->assertSame(10, $effectiveItem['original_price']);
            $this->assertSame('2026-07-07 00:00', $effectiveItem['sale_ends_at']);
            $this->assertTrue($service->campaignFor('explore_stamina_small_bottle')['active']);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 00:01:00', 'Asia/Tokyo'));

            $expiredItem = $service->effectiveItem('explore_stamina_small_bottle', $item);

            $this->assertSame(10, $expiredItem['price']);
            $this->assertArrayNotHasKey('original_price', $expiredItem);
            $this->assertFalse($service->campaignFor('explore_stamina_small_bottle')['active']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
