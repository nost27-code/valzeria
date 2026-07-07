<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\GameSettingService;
use App\Services\SupportPassService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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

            $activeBlueGoldUser = new User([
                'support_pass_expires_at' => Carbon::parse('2026-07-06 12:00:00'),
                'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD,
            ]);

            $expiredUser = new User([
                'support_pass_expires_at' => Carbon::parse('2026-07-04 12:00:00'),
                'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD,
            ]);

            $this->assertSame(SupportPassService::CARD_SKIN_SUPPORT_PASS, $service->displayedCardSkin($activeUser));
            $this->assertSame(SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD, $service->displayedCardSkin($activeBlueGoldUser));
            $this->assertSame(SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD, $service->selectedCardSkin($expiredUser));
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
            'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD,
        ]);

        $this->assertFalse($service->isActive($user));
        $this->assertSame(SupportPassService::CARD_SKIN_DEFAULT, $service->displayedCardSkin($user));
    }

    public function test_support_pass_enabled_setting_overrides_config_default(): void
    {
        config(['support_pass.enabled' => false]);
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

        try {
            $service = new SupportPassService();

            $this->assertFalse($service->enabled());

            $service->setEnabled(true);
            $this->assertTrue($service->enabled());

            $service->setEnabled(false);
            $this->assertFalse($service->enabled());
            $this->assertDatabaseHas('game_settings', [
                'setting_key' => SupportPassService::ENABLED_SETTING_KEY,
                'value' => '0',
                'value_type' => 'boolean',
            ]);
        } finally {
            app(GameSettingService::class)->flush();
            Schema::dropIfExists('game_settings');
        }
    }

    public function test_remaining_days_uses_full_days_without_rounding_up(): void
    {
        config(['support_pass.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 00:37:00'));

        try {
            $service = new SupportPassService();
            $schemaReady = new ReflectionProperty($service, 'schemaReadyCache');
            $schemaReady->setValue($service, true);

            $user = new User([
                'support_pass_expires_at' => Carbon::parse('2026-08-04 02:53:00'),
                'selected_card_skin' => SupportPassService::CARD_SKIN_SUPPORT_PASS,
            ]);

            $status = $service->statusForCharacter(new \App\Models\Character(['user_id' => 1])->setRelation('user', $user));

            $this->assertTrue($status['active']);
            $this->assertSame(29, $status['remaining_days']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
