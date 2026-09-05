<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidCoordinationTimingModel;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedContextPlan;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileCacheHasher;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileContext;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileProjector;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationProfileCacheHasher;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationSnapshotValidator;
use PHPUnit\Framework\TestCase;

class NationRaidSimulationSnapshotValidatorTest extends TestCase
{
    public function test_complete_anonymous_snapshot_is_ready(): void
    {
        $result = (new NationRaidSimulationSnapshotValidator)->validate($this->snapshot());

        $this->assertTrue($result['ready']);
        $this->assertSame(1, $result['counts']['valid_characters']);
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_or_non_numeric_final_defense_is_rejected_without_zero_substitution(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['characters'][0]['abilities']['defense'] = '0';

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);

        $this->assertFalse($result['ready']);
        $this->assertContains(
            'non_integer_ability:defense',
            array_column($result['errors'], 'reason'),
        );
        $this->assertSame('0', $snapshot['characters'][0]['abilities']['defense']);
    }

    public function test_direct_identifier_keys_are_rejected_recursively(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['characters'][0]['profile']['email'] = 'leak@example.test';

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);

        $this->assertFalse($result['ready']);
        $this->assertContains('direct_identifier_key_present:email', array_column($result['errors'], 'reason'));
    }

    public function test_active_window_profile_count_and_turn_sequence_are_exact_contracts(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['active_window']['days'] = 6;
        $snapshot['action_profiles_per_character'] = 2;
        $snapshot['characters'][0]['action_profiles'][0]['actions'][19]['turn'] = 19;

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);
        $reasons = array_column($result['errors'], 'reason');

        $this->assertFalse($result['ready']);
        $this->assertContains('invalid_active_window', $reasons);
        $this->assertContains('action_profile_count_mismatch', $reasons);
        $this->assertContains('invalid_action_turn_sequence', $reasons);
    }

    public function test_coordination_timing_samples_and_contract_are_required(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['characters'][0]['activity']['minute_of_day_samples'] = [1_440, 600, 700];
        $snapshot['coordination_timing_model_hash'] = str_repeat('0', 64);

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);
        $reasons = array_column($result['errors'], 'reason');

        $this->assertFalse($result['ready']);
        $this->assertContains('invalid_activity_minute_samples', $reasons);
        $this->assertContains('coordination_timing_model_hash_mismatch', $reasons);
    }

    public function test_action_profile_cache_hash_detects_a_changed_damage_payload(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['characters'][0]['action_profiles'][0]['actions'][0]['damage_sources'][0]['damage']++;

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);
        $reasons = array_column($result['errors'], 'reason');

        $this->assertFalse($result['ready']);
        $this->assertContains('action_profile_hash_mismatch', $reasons);
        $this->assertContains('action_profile_character_cache_hash_mismatch', $reasons);
    }

    public function test_resolved_context_profile_and_character_hashes_detect_a_changed_result(): void
    {
        $snapshot = $this->withResolvedContext($this->snapshot());
        $snapshot['characters'][0]['resolved_context_profiles'][0]['result']['calculated_boss_damage']++;

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);
        $reasons = array_column($result['errors'], 'reason');

        $this->assertFalse($result['ready']);
        $this->assertContains('resolved_profile_hash_mismatch', $reasons);
        $this->assertContains('resolved_context_profile_character_cache_hash_mismatch', $reasons);
    }

    public function test_resolved_context_root_hash_detects_a_resealed_character_cache_change(): void
    {
        $snapshot = $this->withResolvedContext($this->snapshot());
        $snapshot['characters'][0]['resolved_context_profiles'][0]['result']['calculated_boss_damage']++;
        $hasher = new NationRaidResolvedProfileCacheHasher;
        $profiles = $hasher->sealProfiles($snapshot['characters'][0]['resolved_context_profiles']);
        $snapshot['characters'][0]['resolved_context_profiles'] = $profiles;
        $snapshot['characters'][0]['resolved_context_profile_cache_hash'] = $hasher->characterCacheHash($profiles);

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);
        $reasons = array_column($result['errors'], 'reason');

        $this->assertFalse($result['ready']);
        $this->assertContains('resolved_context_profile_root_cache_hash_mismatch', $reasons);
    }

    public function test_authoritative_resolved_context_plan_rejects_missing_profile_coverage(): void
    {
        $snapshot = $this->withResolvedContext($this->snapshot());
        $planService = new NationRaidResolvedContextPlan;
        $snapshot['resolved_context_plan'][] = [
            'stage' => 2,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ];
        $snapshot['resolved_context_plan'] = $planService->normalize($snapshot['resolved_context_plan']);
        $snapshot['resolved_context_plan_hash'] = $planService->hash($snapshot['resolved_context_plan'], true);

        $result = (new NationRaidSimulationSnapshotValidator)->validate($snapshot);

        $this->assertFalse($result['ready']);
        $this->assertContains('resolved_context_plan_coverage_missing', array_column($result['errors'], 'reason'));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $actions = [];
        foreach (range(1, 20) as $turn) {
            $actions[] = [
                'turn' => $turn,
                'damage_sources' => [['kind' => 'direct', 'damage' => 1_000, 'hit_count' => 1, 'defense_ignore_50_damage' => 1_100]],
                'selected_counterplay_identity' => null,
                'boss_debuff_keys_applied' => [],
                'counterplay_hit' => true,
                'hunting_mark_count' => 0,
                'break_mark_count' => 0,
            ];
        }

        $hasher = new NationRaidSimulationProfileCacheHasher;
        $resolvedHasher = new NationRaidResolvedProfileCacheHasher;
        $resolvedPlan = new NationRaidResolvedContextPlan;
        $profiles = $hasher->sealProfiles([['profile_no' => 1, 'actions' => $actions]]);
        $character = [
            'participant_key' => 'nrp2_'.str_repeat('1', 32),
            'character_key' => 'nrc2_'.str_repeat('2', 32),
            'nation_key' => null,
            'abilities' => ['max_hp' => 10_000, 'max_sp' => 100, 'attack' => 500, 'defense' => 300, 'magic' => 400, 'spirit' => 250, 'agility' => 100, 'luck' => 50],
            'job' => ['current_job_key' => 'test_job', 'mastered_job_count' => 1, 'counterplay_enabled' => false],
            'raid_killer' => [
                'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'matched' => false,
                'damage_rate' => 0.0,
                'damage_rate_cap' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP,
                'effects' => [],
            ],
            'raid_resistance' => [
                'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'matched' => false,
                'damage_reduction_rate' => 0.0,
                'damage_reduction_rate_cap' => NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
            ],
            'boss_set_exact_identities' => [null, null, null, null, null],
            'lineage_votes' => [],
            'activity' => ['battles_7d' => 3, 'daily_battle_counts' => [1, 1, 1, 0, 0, 0, 0], 'minute_of_day_samples' => [600, 700, 800], 'observed_damage_samples' => 3, 'observed_damage_total' => 3_000, 'observed_damage_max' => 1_000],
            'participation_cluster' => ['days' => 7, 'daily_slot_cap' => 5, 'event_slot_cap' => 35],
            'action_profiles' => $profiles,
            'action_profile_cache_hash' => $hasher->characterCacheHash($profiles),
            'resolved_context_profiles' => [],
            'resolved_context_profile_cache_hash' => $resolvedHasher->characterCacheHash([]),
        ];
        $rulesetHash = str_repeat('a', 64);
        $integrationHash = str_repeat('b', 64);
        $model = 'test-v2';

        $coordinationTiming = new NationRaidCoordinationTimingModel;

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
            'action_profile_authoritative' => true,
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
            'population_report' => ['included_characters' => 1, 'extraction_error_characters' => 0, 'coordination_timing_samples' => 3],
            'characters' => [$character],
        ];
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function withResolvedContext(array $snapshot): array
    {
        $context = NationRaidResolvedProfileContext::forProfile(
            characterKey: $snapshot['characters'][0]['character_key'],
            stage: 1,
            startingForm: NationRaidRules::FORM_SEALED_SCALE,
            strategy: NationRaidRules::STRATEGY_ASSAULT,
            dominantLineage: null,
            profileNo: 1,
        );
        $profile = [
            'context_key' => $context->key(),
            'context' => $context->toArray(),
            'result' => [
                'battle_type' => NationRaidRules::BATTLE_TYPE,
                'stage' => 1,
                'form' => NationRaidRules::FORM_SEALED_SCALE,
                'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'sortie_seed' => $context->sortieSeed,
                'ruleset_hash' => $snapshot['ruleset_hash'],
                'strategy' => NationRaidRules::STRATEGY_ASSAULT,
                'turns_completed' => NationRaidRules::MAX_TURNS,
                'outcome' => 'survived',
                'player_remaining_hp' => 9_000,
                'boss_virtual_remaining_hp' => 98_766,
                'calculated_boss_damage' => 1_234,
                'max_one_action_damage' => 1_234,
                't20_starting_sp' => 90,
                'ultimate_denial_reasons' => [],
                'reservation_failure_count' => 0,
                'metrics' => [
                    'observations' => 3,
                    'ultimate_executed' => 1,
                    'cap_binding_hits' => 0,
                    'enemy_damage_actions' => 17,
                    'counterplay_applied' => 0,
                    'guard_consumed_actions' => 0,
                    'parry_succeeded_actions' => 0,
                    'guts_triggered_actions' => 0,
                    'actual_hp_loss' => 1_000,
                    'counter_damage' => 0,
                ],
            ],
        ];
        $hasher = new NationRaidResolvedProfileCacheHasher;
        $profiles = $hasher->sealProfiles([$profile]);
        $snapshot['characters'][0]['resolved_context_profiles'] = $profiles;
        $snapshot['characters'][0]['resolved_context_profile_cache_hash'] = $hasher->characterCacheHash($profiles);
        $planService = new NationRaidResolvedContextPlan;
        $snapshot['resolved_context_plan'] = $planService->normalize([[
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ]]);
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
