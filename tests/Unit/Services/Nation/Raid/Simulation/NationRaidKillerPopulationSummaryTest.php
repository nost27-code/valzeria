<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\Simulation\NationRaidKillerPopulationSummary;
use Tests\TestCase;

class NationRaidKillerPopulationSummaryTest extends TestCase
{
    public function test_it_reports_raid_boosted_distribution_and_pre_cap_values(): void
    {
        $characters = [
            $this->character(0.0, []),
            $this->character(0.24, [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.12],
            ]),
            $this->character(1.0, [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.12],
                ['source' => 'affix', 'species_key' => 'dragon', 'damage_rate' => 0.405],
            ]),
            $this->character(1.0, [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.25],
                ['source' => 'affix', 'species_key' => 'dragon', 'damage_rate' => 0.40],
            ]),
            ['character_key' => 'unavailable'],
        ];

        $summary = app(NationRaidKillerPopulationSummary::class)->summarize($characters);

        $this->assertSame(4, $summary['observed_characters']);
        $this->assertSame(3, $summary['matched_characters']);
        $this->assertSame(1, $summary['unmatched_characters']);
        $this->assertSame(1, $summary['unavailable_characters']);
        $this->assertSame(0.75, $summary['match_rate']);
        $this->assertSame(0.56, $summary['average_damage_rate']);
        $this->assertSame(1.0, $summary['max_damage_rate']);
        $this->assertSame(1.3, $summary['max_raw_combined_damage_rate']);
        $this->assertSame(2, $summary['cap_binding_characters']);
        $this->assertSame([
            ['damage_rate' => 0.0, 'characters' => 1],
            ['damage_rate' => 0.24, 'characters' => 1],
            ['damage_rate' => 1.0, 'characters' => 2],
        ], $summary['damage_rate_distribution']);
    }

    /** @param list<array{source:string,species_key:string,damage_rate:float}> $effects */
    private function character(float $rate, array $effects): array
    {
        return [
            'raid_killer' => [
                'damage_rate' => $rate,
                'effects' => $effects,
            ],
        ];
    }
}
