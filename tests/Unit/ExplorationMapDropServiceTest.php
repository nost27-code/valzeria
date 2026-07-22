<?php

namespace Tests\Unit;

use App\Models\GameSetting;
use App\Services\ExplorationMapDropService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapDropServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_bonus_triples_map_drop_rates_until_the_end_time(): void
    {
        GameSetting::where('setting_key', 'exploration_maps.launch_bonus_enabled')->update(['value' => '1']);
        $service = app(ExplorationMapDropService::class);

        $this->assertSame(60, $service->dropRateBasisPoints('normal', CarbonImmutable::parse('2026-07-29 23:59:59', 'Asia/Tokyo')));
        $this->assertSame(600, $service->dropRateBasisPoints('map_elite', CarbonImmutable::parse('2026-07-29 23:59:59', 'Asia/Tokyo')));
        $this->assertSame(20, $service->dropRateBasisPoints('normal', CarbonImmutable::parse('2026-07-30 00:00:00', 'Asia/Tokyo')));
    }

    public function test_launch_bonus_can_be_stopped_immediately_from_game_settings(): void
    {
        GameSetting::where('setting_key', 'exploration_maps.launch_bonus_enabled')->update(['value' => '0']);
        app(\App\Services\GameSettingService::class)->flush();

        $this->assertSame(20, app(ExplorationMapDropService::class)->dropRateBasisPoints('normal', CarbonImmutable::parse('2026-07-25 12:00:00', 'Asia/Tokyo')));
    }
}
