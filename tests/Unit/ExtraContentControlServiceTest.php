<?php

namespace Tests\Unit;

use App\Services\ExtraContentControlService;
use App\Services\GameSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExtraContentControlServiceTest extends TestCase
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
        config(['extra_content.contents.star_tree_tower.default_enabled' => false]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(GameSettingService::class)->flush();
        Schema::dropIfExists('game_settings');

        parent::tearDown();
    }

    public function test_content_defaults_to_config_enabled_state(): void
    {
        $service = app(ExtraContentControlService::class);

        $this->assertFalse($service->isEnabled('star_tree_tower'));
        $this->assertFalse($service->isActive('star_tree_tower'));

        config(['extra_content.contents.star_tree_tower.default_enabled' => true]);

        $this->assertTrue($service->isEnabled('star_tree_tower'));
        $this->assertTrue($service->isActive('star_tree_tower'));
    }

    public function test_admin_enabled_setting_overrides_config_default(): void
    {
        config(['extra_content.contents.star_tree_tower.default_enabled' => true]);
        $service = app(ExtraContentControlService::class);

        $service->setEnabled('star_tree_tower', false);

        $this->assertFalse($service->isEnabled('star_tree_tower'));
        $this->assertFalse($service->isActive('star_tree_tower'));
        $this->assertDatabaseHas('game_settings', [
            'setting_key' => ExtraContentControlService::ENABLED_KEY_PREFIX . 'star_tree_tower',
            'value' => '0',
            'value_type' => 'boolean',
        ]);
    }

    public function test_active_only_during_configured_period(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00', 'Asia/Tokyo'));

        $service = app(ExtraContentControlService::class);
        $service->setEnabled('star_tree_tower', true);
        $service->setPeriod('star_tree_tower', '2026-07-07T00:00', '2026-07-08T00:00');

        $this->assertTrue($service->isActive('star_tree_tower'));
        $this->assertSame('開催中', $service->periodFor('star_tree_tower')['status_label']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 00:01:00', 'Asia/Tokyo'));

        $this->assertFalse($service->isActive('star_tree_tower'));
        $this->assertSame('終了済み', $service->periodFor('star_tree_tower')['status_label']);
    }
}
