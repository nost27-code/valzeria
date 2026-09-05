<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidCoordinationTimingModel;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedContextPlan;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileCacheHasher;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileContext;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileProjector;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationProfileCacheHasher;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationRunner;
use DomainException;
use Tests\TestCase;

class NationRaidSimulationRunnerTest extends TestCase
{
    public function test_same_snapshot_and_seed_produce_same_scenarios(): void
    {
        $runner = app(NationRaidSimulationRunner::class);
        $snapshot = $this->snapshot(authoritative: true);

        $first = $runner->run($snapshot, seeds: 3, seedStart: 41, participationRates: [1.0], allowReferenceProfile: true);
        $second = $runner->run($snapshot, seeds: 3, seedStart: 41, participationRates: [1.0], allowReferenceProfile: true);

        $this->assertSame($first['snapshot_hash'], $second['snapshot_hash']);
        $this->assertSame(NationRaidRules::STRATEGY_BOSS_SET, $first['strategy_mode']);
        $this->assertSame('nation-raid-phase2-simulation-v10', $first['artifact_version']);
        $this->assertSame(app(\App\Services\Nation\Raid\NationRaidLineageVoteResolver::class)->contractHash(), $first['lineage_voting_model']['hash']);
        $this->assertSame(NationRaidRules::BOSS_SPECIES_KEY, $first['boss_species_key']);
        $this->assertSame([
            'observed_characters' => 1,
            'matched_characters' => 1,
            'unmatched_characters' => 0,
            'unavailable_characters' => 0,
            'match_rate' => 1.0,
            'average_damage_rate' => 0.60,
            'max_damage_rate' => 0.60,
            'max_raw_combined_damage_rate' => 0.60,
            'cap_binding_characters' => 0,
            'damage_rate_distribution' => [
                ['damage_rate' => 0.60, 'characters' => 1],
            ],
        ], $first['raid_killer_population']);
        $this->assertSame($first['scenarios'], $second['scenarios']);
        $this->assertCount(3, $first['scenarios']);
        $this->assertSame(35, $first['scenarios'][0]['resolved_slots_per_run']);
        $this->assertFalse($first['balance_gate_authoritative']);
        $this->assertTrue($first['coordination_model']['modeled']);
        $this->assertTrue($first['coordination_model']['authoritative_for_balance_gate']);
        $this->assertSame(180, $first['coordination_model']['window_minutes']);
        $this->assertSame([], $first['coordination_model']['known_gaps']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['coordination_model']['hash']);
        $this->assertSame(70.0, $first['player_snapshot_model']['enemy_hit_chance']['minimum']);
        $this->assertSame(98.0, $first['player_snapshot_model']['enemy_hit_chance']['maximum']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['player_snapshot_model']['hash']);
        $contextMetrics = $first['resolved_context_cache_metrics'];
        $this->assertSame('reference_reachability', $contextMetrics['mode']);
        $this->assertNull($contextMetrics['cache_generation_completion_rate']);
        $this->assertNull($contextMetrics['runtime_cache_hit_rate']);
        $this->assertNull($contextMetrics['plan_utilization_rate']);
        $this->assertFalse($contextMetrics['cache_operational_gate_met']);
        $this->assertGreaterThan(0, $contextMetrics['reachability']['context_requests']);
        $this->assertNotEmpty($contextMetrics['reachability']['contexts']);
        $this->assertFalse($contextMetrics['reachability']['review_candidate_plan']['coverage_complete']);
        $this->assertSame(
            $contextMetrics['reachability']['unique_contexts'],
            count($contextMetrics['reachability']['review_candidate_plan']['contexts']),
        );
        $this->assertSame($contextMetrics, $second['resolved_context_cache_metrics']);
        foreach ($contextMetrics['reachability']['contexts'] as $context) {
            $this->assertSame(NationRaidRules::STRATEGY_BOSS_SET, $context['strategy']);
        }
    }

    public function test_reference_profile_requires_explicit_opt_in(): void
    {
        $this->expectException(DomainException::class);

        app(NationRaidSimulationRunner::class)->run(
            $this->snapshot(authoritative: false),
            seeds: 1,
            participationRates: [1.0],
        );
    }

    public function test_reference_player_snapshot_model_requires_opt_in_even_with_authoritative_action_profile(): void
    {
        $this->expectException(DomainException::class);

        app(NationRaidSimulationRunner::class)->run(
            $this->snapshot(authoritative: true),
            seeds: 1,
            participationRates: [1.0],
        );
    }

    public function test_enemy_action_cap_binding_uses_the_damage_result_camel_case_contract(): void
    {
        $snapshot = $this->snapshot(authoritative: true);
        $snapshot['characters'][0]['abilities']['max_hp'] = 1;

        $result = app(NationRaidSimulationRunner::class)->run(
            $snapshot,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            allowReferenceProfile: true,
        );

        $metrics = $result['scenarios'][0]['battle_metrics'];
        $this->assertGreaterThan(0, $metrics['enemy_damage_actions']);
        $this->assertGreaterThan(0, $metrics['cap_binding_hits']);
        $this->assertLessThanOrEqual($metrics['enemy_damage_actions'], $metrics['cap_binding_hits']);
        $this->assertGreaterThan(0.0, $metrics['cap_binding_rate']);
    }

    public function test_reviewed_context_cache_is_used_as_reference_without_replaying_passive_action_profiles(): void
    {
        $snapshot = $this->withResolvedContext(
            $this->snapshot(authoritative: false),
            NationRaidRules::STRATEGY_ASSAULT,
            1,
        );

        $result = app(NationRaidSimulationRunner::class)->run(
            $snapshot,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            strategyMode: NationRaidRules::STRATEGY_ASSAULT,
            allowReferenceProfile: true,
        );

        $this->assertFalse($result['balance_gate_authoritative']);
        $this->assertTrue($result['player_snapshot_model']['action_profile_authoritative']);
        $this->assertTrue($result['player_snapshot_model']['coordination_model_authoritative']);
        $this->assertSame([], $result['player_snapshot_model']['known_gaps']);
        $this->assertSame(35.0, $result['scenarios'][0]['mean_total_damage']);
        $this->assertSame(35, $result['scenarios'][0]['battle_metrics']['sorties']);
        $this->assertSame(35, $result['scenarios'][0]['battle_metrics']['guard_consumed_actions']);
        $contextMetrics = $result['resolved_context_cache_metrics'];
        $this->assertEqualsWithDelta(1.0, $contextMetrics['cache_generation_completion_rate'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $contextMetrics['runtime_cache_hit_rate'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $contextMetrics['plan_utilization_rate'], 0.000001);
        $this->assertSame(35, $contextMetrics['runtime_cache']['lookup_requests']);
        $this->assertSame(35, $contextMetrics['runtime_cache']['hits']);
        $this->assertSame(0, $contextMetrics['runtime_cache']['misses']);
        $this->assertTrue($contextMetrics['cache_operational_gate_met']);
        $this->assertFalse($contextMetrics['reachability']['review_candidate_plan']['coverage_complete']);
    }

    public function test_reviewed_context_cache_does_not_authorize_the_old_five_sortie_participation_model(): void
    {
        $snapshot = $this->withResolvedContext(
            $this->snapshot(authoritative: false),
            NationRaidRules::STRATEGY_ASSAULT,
            1,
        );

        $this->assertFalse(app(NationRaidSimulationRunner::class)->authoritativeForBalanceGate($snapshot));

        $result = app(NationRaidSimulationRunner::class)->run(
            $snapshot,
            seeds: 1,
            participationRates: [1.0],
            strategyMode: NationRaidRules::STRATEGY_ASSAULT,
            allowReferenceProfile: true,
        );

        $this->assertFalse($result['balance_gate_authoritative']);
        $this->assertFalse($result['participation_model']['authoritative_for_balance_gate']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('explicit opt-in');
        app(NationRaidSimulationRunner::class)->run($snapshot, seeds: 1);
    }

    public function test_authoritative_metrics_keep_unused_reviewed_contexts_visible_without_failing_the_cache_gate(): void
    {
        $snapshot = $this->withResolvedContexts(
            $this->snapshot(authoritative: false),
            [
                ['strategy' => NationRaidRules::STRATEGY_ASSAULT, 'damage' => 1],
                ['strategy' => NationRaidRules::STRATEGY_FORTIFY, 'damage' => 2],
            ],
        );

        $result = app(NationRaidSimulationRunner::class)->run(
            $snapshot,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            strategyMode: NationRaidRules::STRATEGY_ASSAULT,
            allowReferenceProfile: true,
        );

        $metrics = $result['resolved_context_cache_metrics'];
        $this->assertSame(2, $metrics['generation']['expected_profiles']);
        $this->assertSame(2, $metrics['generation']['generated_profiles']);
        $this->assertEqualsWithDelta(1.0, $metrics['cache_generation_completion_rate'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $metrics['runtime_cache_hit_rate'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $metrics['plan_utilization_rate'], 0.000001);
        $this->assertSame(1, $metrics['plan_utilization']['referenced_planned_contexts']);
        $this->assertSame(1, count($metrics['plan_utilization']['unused_context_keys']));
        $this->assertSame(0, $metrics['plan_utilization']['unplanned_referenced_contexts']);
        $this->assertTrue($metrics['cache_operational_gate_met']);
    }

    public function test_authoritative_context_cache_never_falls_back_when_runtime_context_is_missing(): void
    {
        $snapshot = $this->withResolvedContext(
            $this->snapshot(authoritative: false),
            NationRaidRules::STRATEGY_FORTIFY,
            1,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Authoritative raid context cache is missing');

        app(NationRaidSimulationRunner::class)->run(
            $snapshot,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            strategyMode: NationRaidRules::STRATEGY_ASSAULT,
            allowReferenceProfile: true,
        );
    }

    public function test_nation_coordination_adds_only_shared_hp_damage_from_the_second_unique_character(): void
    {
        $affiliated = $this->twoCharacterReferenceSnapshot(true);
        $unaffiliated = $this->twoCharacterReferenceSnapshot(false);
        $runner = app(NationRaidSimulationRunner::class);

        $withCoordination = $runner->run(
            $affiliated,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            allowReferenceProfile: true,
        )['scenarios'][0];
        $withoutCoordination = $runner->run(
            $unaffiliated,
            seeds: 1,
            seedStart: 41,
            participationRates: [1.0],
            allowReferenceProfile: true,
        )['scenarios'][0];

        $this->assertSame($withoutCoordination['mean_personal_damage'], $withCoordination['mean_personal_damage']);
        $this->assertSame(0.0, $withoutCoordination['mean_coordination_damage']);
        $this->assertGreaterThan(0.0, $withCoordination['mean_coordination_damage']);
        $this->assertSame(
            $withCoordination['mean_personal_damage'] + $withCoordination['mean_coordination_damage'],
            $withCoordination['mean_total_damage'],
        );
        $this->assertSame(70, $withCoordination['coordination_metrics']['eligible_sorties']);
        $this->assertSame(0, $withCoordination['coordination_metrics']['unaffiliated_sorties']);
        $this->assertGreaterThan(0, $withCoordination['coordination_metrics']['bonus_sorties']);
        $this->assertSame(2, $withCoordination['coordination_metrics']['max_unique_participants']);
        $this->assertSame(70, $withoutCoordination['coordination_metrics']['unaffiliated_sorties']);
    }

    /** @return array<string, mixed> */
    private function snapshot(bool $authoritative): array
    {
        $actions = [];
        foreach (range(1, 20) as $turn) {
            $actions[] = [
                'turn' => $turn,
                'damage_sources' => [['kind' => 'direct', 'damage' => 30_000, 'hit_count' => 1, 'defense_ignore_50_damage' => 32_000]],
                'selected_counterplay_identity' => null,
                'boss_debuff_keys_applied' => [],
                'counterplay_hit' => true,
                'hunting_mark_count' => 0,
                'break_mark_count' => 0,
            ];
        }

        $hasher = app(NationRaidSimulationProfileCacheHasher::class);
        $resolvedHasher = app(NationRaidResolvedProfileCacheHasher::class);
        $resolvedPlan = app(NationRaidResolvedContextPlan::class);
        $profiles = $hasher->sealProfiles([['profile_no' => 1, 'actions' => $actions]]);
        $character = [
            'participant_key' => 'nrp2_'.str_repeat('1', 32),
            'character_key' => 'nrc2_'.str_repeat('2', 32),
            'nation_key' => null,
            'abilities' => ['max_hp' => 50_000, 'max_sp' => 100, 'attack' => 2_000, 'defense' => 2_000, 'magic' => 2_000, 'spirit' => 2_000, 'agility' => 1_000, 'luck' => 100],
            'job' => ['current_job_key' => 'test_job', 'mastered_job_count' => 1, 'counterplay_enabled' => false],
            'raid_killer' => [
                'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'matched' => true,
                'damage_rate' => 0.60,
                'damage_rate_cap' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP,
                'effects' => [
                    ['source' => 'affix', 'species_key' => NationRaidRules::BOSS_SPECIES_KEY, 'damage_rate' => 0.30],
                ],
            ],
            'raid_resistance' => [
                'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'matched' => false,
                'damage_reduction_rate' => 0.0,
                'damage_reduction_rate_cap' => NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
            ],
            'boss_set_exact_identities' => [null, null, null, null, null],
            'lineage_votes' => [],
            'activity' => ['battles_7d' => 20, 'daily_battle_counts' => [3, 3, 3, 3, 3, 3, 2], 'minute_of_day_samples' => array_fill(0, 20, 1_200), 'observed_damage_samples' => 20, 'observed_damage_total' => 600_000, 'observed_damage_max' => 30_000],
            'participation_cluster' => ['days' => 7, 'daily_slot_cap' => 5, 'event_slot_cap' => 35],
            'action_profiles' => $profiles,
            'action_profile_cache_hash' => $hasher->characterCacheHash($profiles),
            'resolved_context_profiles' => [],
            'resolved_context_profile_cache_hash' => $resolvedHasher->characterCacheHash([]),
        ];
        $rulesetHash = app(NationRaidRules::class)->rulesetHash();
        $integrationHash = str_repeat('b', 64);
        $model = 'test-v2';

        $coordinationTiming = app(NationRaidCoordinationTimingModel::class);

        return [
            'schema_version' => 'nation-raid-phase2-snapshot-v6',
            'extracted_at' => '2026-09-02T09:00:00+09:00',
            'active_window' => ['days' => 7, 'from' => '2026-08-26T09:00:00+09:00', 'to' => '2026-09-02T09:00:00+09:00'],
            'ruleset_hash' => $rulesetHash,
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'raid_killer_contract_hash' => str_repeat('e', 64),
            'coordination_timing_model' => $coordinationTiming->contract(),
            'coordination_timing_model_hash' => $coordinationTiming->contractHash(),
            'integration_hash' => $integrationHash,
            'lineage_adapter_hash' => str_repeat('c', 64),
            'anonymizer_key_id' => str_repeat('d', 16),
            'action_profile_model' => $model,
            'action_profile_authoritative' => $authoritative,
            'action_profile_cache_hash' => $hasher->rootCacheHash($rulesetHash, $integrationHash, $model, [$character]),
            'action_profiles_per_character' => 1,
            'resolved_context_profile_model' => NationRaidResolvedProfileProjector::MODEL_VERSION,
            'resolved_context_profile_authoritative' => false,
            'resolved_context_profile_cache_hash' => $resolvedHasher->rootCacheHash(
                $rulesetHash,
                $integrationHash,
                NationRaidResolvedProfileProjector::MODEL_VERSION,
                NationRaidResolvedProfileContext::contractHash(),
                [$character],
            ),
            'resolved_context_profiles_per_context' => 1,
            'resolved_context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            'resolved_context_plan_schema' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'resolved_context_plan_coverage_complete' => false,
            'resolved_context_plan_hash' => $resolvedPlan->hash([], false),
            'resolved_context_plan' => [],
            'feature_flags' => ['dynamic_single' => true, 'hit_resolution' => true, 'damage_application' => true, 'resources' => true],
            'population_report' => ['included_characters' => 1, 'extraction_error_characters' => 0, 'coordination_timing_samples' => 20],
            'characters' => [$character],
        ];
    }

    /** @return array<string, mixed> */
    private function twoCharacterReferenceSnapshot(bool $affiliated): array
    {
        $snapshot = $this->snapshot(authoritative: false);
        $profileHasher = app(NationRaidSimulationProfileCacheHasher::class);
        foreach ($snapshot['characters'][0]['action_profiles'][0]['actions'] as &$action) {
            $action['damage_sources'] = [[
                'kind' => 'direct',
                'damage' => 1_000,
                'hit_count' => 1,
                'defense_ignore_50_damage' => 1_100,
            ]];
        }
        unset($action);
        $snapshot['characters'][0]['action_profiles'] = $profileHasher->sealProfiles(
            $snapshot['characters'][0]['action_profiles'],
        );
        $snapshot['characters'][0]['action_profile_cache_hash'] = $profileHasher->characterCacheHash(
            $snapshot['characters'][0]['action_profiles'],
        );

        $nationKey = $affiliated ? 'nrn2_'.str_repeat('a', 32) : null;
        $snapshot['characters'][0]['nation_key'] = $nationKey;
        $second = $snapshot['characters'][0];
        $second['participant_key'] = 'nrp2_'.str_repeat('3', 32);
        $second['character_key'] = 'nrc2_'.str_repeat('4', 32);
        $second['nation_key'] = $nationKey;
        $snapshot['characters'][] = $second;
        $snapshot['population_report']['included_characters'] = 2;
        $snapshot['population_report']['coordination_timing_samples'] = 40;
        $snapshot['action_profile_cache_hash'] = $profileHasher->rootCacheHash(
            $snapshot['ruleset_hash'],
            $snapshot['integration_hash'],
            $snapshot['action_profile_model'],
            $snapshot['characters'],
        );
        $resolvedHasher = app(NationRaidResolvedProfileCacheHasher::class);
        $snapshot['resolved_context_profile_cache_hash'] = $resolvedHasher->rootCacheHash(
            $snapshot['ruleset_hash'],
            $snapshot['integration_hash'],
            $snapshot['resolved_context_profile_model'],
            $snapshot['resolved_context_contract_hash'],
            $snapshot['characters'],
        );

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function withResolvedContext(array $snapshot, string $strategy, int $damage): array
    {
        return $this->withResolvedContexts($snapshot, [['strategy' => $strategy, 'damage' => $damage]]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{strategy:string,damage:int}>  $definitions
     * @return array<string, mixed>
     */
    private function withResolvedContexts(array $snapshot, array $definitions): array
    {
        $profiles = [];
        $plan = [];
        foreach ($definitions as $definition) {
            $strategy = $definition['strategy'];
            $damage = $definition['damage'];
            $context = NationRaidResolvedProfileContext::forProfile(
                characterKey: $snapshot['characters'][0]['character_key'],
                stage: 1,
                startingForm: NationRaidRules::FORM_SEALED_SCALE,
                strategy: $strategy,
                dominantLineage: null,
                profileNo: 1,
            );
            $profiles[] = [
                'context_key' => $context->key(),
                'context' => $context->toArray(),
                'result' => [
                    'battle_type' => NationRaidRules::BATTLE_TYPE,
                    'stage' => 1,
                    'form' => NationRaidRules::FORM_SEALED_SCALE,
                    'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                    'sortie_seed' => $context->sortieSeed,
                    'ruleset_hash' => $snapshot['ruleset_hash'],
                    'strategy' => $strategy,
                    'turns_completed' => NationRaidRules::MAX_TURNS,
                    'outcome' => 'survived',
                    'player_remaining_hp' => 49_000,
                    'boss_virtual_remaining_hp' => NationRaidRules::VIRTUAL_MAX_HP - $damage,
                    'calculated_boss_damage' => $damage,
                    'max_one_action_damage' => $damage,
                    't20_starting_sp' => 90,
                    'ultimate_denial_reasons' => [],
                    'reservation_failure_count' => 0,
                    'metrics' => [
                        'observations' => 3,
                        'ultimate_executed' => 1,
                        'cap_binding_hits' => 0,
                        'enemy_damage_actions' => 17,
                        'counterplay_applied' => 0,
                        'guard_consumed_actions' => 1,
                        'parry_succeeded_actions' => 0,
                        'guts_triggered_actions' => 0,
                        'actual_hp_loss' => 1_000,
                        'counter_damage' => 0,
                    ],
                ],
            ];
            $plan[] = [
                'stage' => 1,
                'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
                'strategy' => $strategy,
                'dominant_lineage' => null,
            ];
        }
        $hasher = app(NationRaidResolvedProfileCacheHasher::class);
        $profiles = $hasher->sealProfiles($profiles);
        $snapshot['characters'][0]['resolved_context_profiles'] = $profiles;
        $snapshot['characters'][0]['resolved_context_profile_cache_hash'] = $hasher->characterCacheHash($profiles);
        $planService = app(NationRaidResolvedContextPlan::class);
        $snapshot['resolved_context_plan'] = $planService->normalize($plan);
        $snapshot['resolved_context_plan_coverage_complete'] = true;
        $snapshot['resolved_context_plan_hash'] = $planService->hash($snapshot['resolved_context_plan'], true);
        $snapshot['resolved_context_profile_authoritative'] = true;
        $snapshot['resolved_context_profile_cache_hash'] = $hasher->rootCacheHash(
            $snapshot['ruleset_hash'],
            $snapshot['integration_hash'],
            $snapshot['resolved_context_profile_model'],
            $snapshot['resolved_context_contract_hash'],
            $snapshot['characters'],
        );

        return $snapshot;
    }
}
