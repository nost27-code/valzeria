<?php

namespace Tests\Unit;

use App\Services\JobArtService;
use Tests\TestCase;

class JobArtV2OffProductionContractTest extends TestCase
{
    public function test_every_job_art_v2_feature_switch_defaults_to_off(): void
    {
        $config = require base_path('config/battle.php');

        foreach ([
            'presets',
            'pvp_set',
            'loadout_v2',
            'loadout_card_details',
            'dynamic_single',
            'normalized_sp',
            'hit_resolution',
            'damage_application',
            'resources',
            'fields',
            'penetration',
            'penetration_stance',
            'c_design_prototype',
            'ultimate_counterplay',
        ] as $flag) {
            $this->assertFalse($config['job_art_v2'][$flag], $flag . ' must default to OFF');
        }
    }

    public function test_default_runtime_keeps_the_legacy_slot_and_cost_limits(): void
    {
        config(['battle.job_art_v2.loadout_v2' => false]);

        $service = app(JobArtService::class);

        $this->assertSame(3, $service->maxSlots());
        $this->assertSame(5, $service->maxCost());
    }
}
