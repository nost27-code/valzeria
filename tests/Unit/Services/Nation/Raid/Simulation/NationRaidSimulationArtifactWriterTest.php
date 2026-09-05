<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedContextPlan;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileContext;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationArtifactWriter;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationOperationalMetricsBuilder;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class NationRaidSimulationArtifactWriterTest extends TestCase
{
    public function test_population_report_renders_killer_rate_distribution_as_a_table(): void
    {
        $writer = app(NationRaidSimulationArtifactWriter::class);
        $method = new ReflectionMethod($writer, 'populationReport');
        $method->setAccessible(true);

        $report = $method->invoke($writer, [
            'resolved_context_profile_model' => 'turn-by-turn-live-defense-compact-v1',
            'resolved_context_profile_cache_hash' => str_repeat('a', 64),
            'resolved_context_plan_hash' => str_repeat('b', 64),
            'resolved_context_plan_coverage_complete' => true,
            'resolved_context_profile_authoritative' => true,
            'population_report' => [
                'included_characters' => 5,
                'raid_killer_damage_rate_distribution' => [
                    ['damage_rate' => 0.0, 'characters' => 2],
                    ['damage_rate' => 0.12, 'characters' => 3],
                ],
            ],
        ], [
            'ready' => true,
            'counts' => [],
            'warnings' => [],
            'errors' => [],
        ]);

        $this->assertStringContainsString('## 竜特攻の実効rate分布', $report);
        $this->assertStringContainsString('| +0% | 2 |', $report);
        $this->assertStringContainsString('| +12% | 3 |', $report);
        $this->assertStringNotContainsString('raid_killer_damage_rate_distribution:', $report);
        $this->assertStringContainsString('- resolved context plan complete: `true`', $report);
        $this->assertStringContainsString('- resolved context profile authoritative: `true`', $report);
        $this->assertStringNotContainsString('- balance gate authoritative:', $report);
    }

    public function test_writer_persists_context_metrics_and_a_non_authoritative_candidate_plan(): void
    {
        $writer = app(NationRaidSimulationArtifactWriter::class);
        $label = 'test_context_metrics_'.bin2hex(random_bytes(6));
        $candidate = [
            'schema_version' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            'coverage_complete' => false,
            'contexts' => [[
                'stage' => 1,
                'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
                'strategy' => NationRaidRules::STRATEGY_ASSAULT,
                'dominant_lineage' => null,
            ]],
        ];
        $metrics = [
            'cache_generation_completion_rate' => 1.0,
            'runtime_cache_hit_rate' => 1.0,
            'plan_utilization_rate' => 0.5,
            'reachability' => ['review_candidate_plan' => $candidate],
        ];

        $paths = $writer->write(
            ['population_report' => []],
            ['ready' => true, 'counts' => [], 'warnings' => [], 'errors' => []],
            ['resolved_context_cache_metrics' => $metrics],
            $label,
        );

        try {
            $this->assertFileExists($paths['resolved_context_cache_metrics']);
            $this->assertFileExists($paths['resolved_context_plan_candidate']);
            $this->assertEquals(
                $metrics,
                json_decode((string) file_get_contents($paths['resolved_context_cache_metrics']), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->assertSame(
                $candidate,
                json_decode((string) file_get_contents($paths['resolved_context_plan_candidate']), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->assertFalse($candidate['coverage_complete']);
            $loaded = app(NationRaidResolvedContextPlan::class)->load($paths['resolved_context_plan_candidate']);
            $this->assertFalse($loaded['coverage_complete']);
            $this->assertSame($candidate['contexts'], $loaded['contexts']);
        } finally {
            File::deleteDirectory($paths['directory']);
        }
    }

    public function test_operational_metrics_keep_environment_measurements_outside_deterministic_artifacts(): void
    {
        $writer = app(NationRaidSimulationArtifactWriter::class);
        $builder = app(NationRaidSimulationOperationalMetricsBuilder::class);
        $label = 'test_operational_metrics_'.bin2hex(random_bytes(6));
        $actionProfiles = [['profile_no' => 1, 'actions' => [['turn' => 1, 'damage' => 123]]]];
        $resolvedProfiles = [
            ['profile_no' => 1, 'result' => ['calculated_boss_damage' => 456]],
            ['profile_no' => 2, 'result' => ['calculated_boss_damage' => 789]],
        ];
        $snapshot = [
            'schema_version' => 'nation-raid-phase2-snapshot-v6',
            'ruleset_hash' => str_repeat('1', 64),
            'integration_hash' => str_repeat('2', 64),
            'action_profile_cache_hash' => str_repeat('3', 64),
            'resolved_context_profile_cache_hash' => str_repeat('4', 64),
            'resolved_context_plan_hash' => str_repeat('5', 64),
            'resolved_context_profile_authoritative' => false,
            'resolved_context_profiles_per_context' => 2,
            'resolved_context_plan' => [[
                'stage' => 1,
                'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
                'strategy' => NationRaidRules::STRATEGY_ASSAULT,
                'dominant_lineage' => null,
            ]],
            'population_report' => ['included_characters' => 1],
            'characters' => [[
                'character_key' => 'must-not-leak-character-key',
                'action_profiles' => $actionProfiles,
                'resolved_context_profiles' => $resolvedProfiles,
            ]],
        ];

        $paths = $writer->write(
            $snapshot,
            ['ready' => true, 'counts' => [], 'warnings' => [], 'errors' => []],
            null,
            $label,
        );

        try {
            $metrics = $builder->build(
                $snapshot,
                $paths,
                [
                    'snapshot_load_or_build' => 12.34567,
                    'snapshot_validation' => 0.5,
                    'initial_artifact_write' => 2,
                    'simulation' => null,
                    'final_artifact_write' => null,
                    'measured_total_through_artifact_write' => 15.1,
                ],
                [
                    'outcome' => 'snapshot_only_completed',
                    'snapshot_source' => 'database_read_only',
                    'resolved_context_plan_provided' => true,
                    'snapshot_only' => true,
                    'seed_count' => null,
                    'seed_start' => null,
                    'strategy_mode' => 'mixed_equal',
                    'reference_profile_allowed' => false,
                    'balance_gate_authoritative' => false,
                ],
            );
            $operationalPath = $writer->writeOperationalMetrics($paths['directory'], $metrics);
            $decoded = json_decode((string) file_get_contents($operationalPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(NationRaidSimulationOperationalMetricsBuilder::VERSION, $decoded['schema_version']);
            $this->assertSame(12.346, $decoded['timing_ms']['snapshot_load_or_build']);
            $this->assertSame(1, $decoded['capacity']['characters']);
            $this->assertSame(1, $decoded['capacity']['planned_contexts']);
            $this->assertSame(2, $decoded['capacity']['resolved_context_profile_cache']['profiles']);
            $this->assertSame(
                strlen(json_encode($resolvedProfiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                $decoded['capacity']['resolved_context_profile_cache']['compact_json_bytes'],
            );
            $this->assertGreaterThan(0, $decoded['capacity']['artifact_file_bytes']['snapshot']);
            $this->assertFalse($decoded['measurement_scope']['resolved_cache_generation_time_isolated']);
            $this->assertStringNotContainsString(
                'must-not-leak-character-key',
                json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        } finally {
            File::deleteDirectory($paths['directory']);
        }
    }
}
