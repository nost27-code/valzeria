<?php

namespace Tests\Unit;

use App\Models\GameSetting;
use App\Services\ExplorationMapDropService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationMapDropServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_drop_rates_do_not_change_when_the_removed_bonus_setting_is_enabled(): void
    {
        GameSetting::where('setting_key', 'exploration_maps.launch_bonus_enabled')->update(['value' => '1']);
        $service = app(ExplorationMapDropService::class);

        $this->assertSame(20, $service->dropRateBasisPoints('normal'));
        $this->assertSame(20, $service->dropRateBasisPoints('elite'));
        $this->assertSame(20, $service->dropRateBasisPoints('boss'));
        $this->assertSame(20, $service->dropRateBasisPoints('map_normal'));
        $this->assertSame(20, $service->dropRateBasisPoints('map_elite'));
    }
}
