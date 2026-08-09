<?php

namespace Tests\Unit;

use App\Services\JobArtService;
use Tests\TestCase;

class JobArtPvpSetContextTest extends TestCase
{
    public function test_pvp_set_flag_defaults_to_off(): void
    {
        $configFile = require base_path('config/battle.php');

        $this->assertFalse($configFile['job_art_v2']['pvp_set']);
    }

    public function test_flag_off_keeps_every_player_combat_path_on_the_boss_set(): void
    {
        config(['battle.job_art_v2.pvp_set' => false]);
        $service = app(JobArtService::class);

        $this->assertSame(['normal', 'boss'], $service->slotContexts());
        $this->assertArrayNotHasKey('pvp', $service->slotContextLabels());
        $this->assertSame('normal', $service->battleSlotContext('pve'));
        $this->assertSame('boss', $service->battleSlotContext('boss'));
        $this->assertSame('boss', $service->battleSlotContext('champ'));
        $this->assertSame('boss', $service->battleSlotContext('pvp'));
        $this->assertSame('boss', $service->battleSlotContext('arena_npc'));
    }

    public function test_flag_on_uses_one_pvp_set_for_all_player_combat_paths(): void
    {
        config(['battle.job_art_v2.pvp_set' => true]);
        $service = app(JobArtService::class);

        $this->assertSame(['normal', 'boss', 'pvp'], $service->slotContexts());
        $this->assertSame('PvPセット', $service->slotContextLabels()['pvp']);
        $this->assertSame('champ', $service->availabilityContextForSlotContext('pvp'));
        $this->assertSame('pvp', $service->battleSlotContext('champ'));
        $this->assertSame('pvp', $service->battleSlotContext('pvp'));
        $this->assertSame('pvp', $service->battleSlotContext('arena_npc'));
        $this->assertSame('normal', $service->battleSlotContext('pve'));
        $this->assertSame('boss', $service->battleSlotContext('boss'));
    }

    public function test_pr2_does_not_change_slot_or_cost_limits(): void
    {
        $this->assertSame(3, JobArtService::MAX_SLOTS);
        $this->assertSame(5, JobArtService::MAX_COST);
    }
}
