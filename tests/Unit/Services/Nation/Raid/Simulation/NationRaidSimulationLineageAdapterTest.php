<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter;
use PHPUnit\Framework\TestCase;

class NationRaidSimulationLineageAdapterTest extends TestCase
{
    public function test_all_ten_canonical_lineages_map_to_phase_one_rule_keys(): void
    {
        $adapter = new NationRaidSimulationLineageAdapter;

        $this->assertCount(10, $adapter->mappings());
        $this->assertSame('guardian', $adapter->toRaid('guard'));
        $this->assertSame('dark', $adapter->toRaid('eclipse'));
        $this->assertSame('field', $adapter->toRaid('field'));

        $rules = new NationRaidRules;
        foreach ($adapter->mappings() as $raidKey) {
            $this->assertNotNull($rules->counterAction($raidKey), $raidKey);
        }
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $adapter->contractHash());
    }
}
