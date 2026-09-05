<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\Nation\Raid\NationRaidBattleEngine;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidPlayerActionSnapshot;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTelemetryAdapter;
use Tests\TestCase;

class NationRaidTelemetryAdapterTest extends TestCase
{
    public function test_frozen_turns_keep_sp_reservation_virtual_hp_camel_case_cap_and_no_identity_data(): void
    {
        [$battle, $event, $participant] = $this->fixture();
        $data = app(NationRaidTelemetryAdapter::class)->data($battle, $event, $participant);

        $this->assertSame(80, $data['turns'][4]['boss_sp_after']); // T5予約を含む境界。engineのturn行は予約前。
        $this->assertSame(90, $data['turns'][19]['boss_sp_before']);
        $this->assertSame(1, $data['counterplay_metrics']['ultimate_casts']);
        $this->assertSame('guard', $data['adaptive_lineage']);
        $this->assertSame(100_000, $data['turns'][0]['boss_hp_before']);
        $this->assertSame(100_000 - $battle->turn_log[0]['player_damage']['total_damage'], $data['turns'][0]['boss_hp_after']);
        $this->assertSame(4_500_000, $data['boss_hp_before']);
        $this->assertSame(11_111, $data['turns'][0]['boss_action']['damage_before_cap']);
        $this->assertSame(999, $data['turns'][0]['boss_action']['damage_after_cap']);
        $this->assertSame(8, $data['healing_total']);
        $this->assertSame(3, $data['turns'][0]['player_action']['sp_spent']);
        $this->assertSame($battle->calculated_damage_total, array_sum($data['damage_by_source']));
        $this->assertSame(1200, $data['event_snapshot']['coordination_damage']);
        $this->assertSame(2, $data['nation_active_count']);
        $this->assertStringNotContainsString('private-person', json_encode($data));
        $this->assertArrayNotHasKey('account_id', $data);
    }

    public function test_legacy_missing_observation_stays_unknown_and_never_guesses_normal_attack_or_zero_healing(): void
    {
        [$battle, $event, $participant] = $this->fixture();
        $summary = $battle->summary;
        unset($summary['calculation']['player_turn_metrics']);
        $summary['calculation']['engine_result']['spTrace'] = [];
        $battle->summary = $summary;
        $data = app(NationRaidTelemetryAdapter::class)->data($battle, $event, $participant);
        $this->assertContains('player_turn_observation_missing', $data['quality_flags']);
        $this->assertNull($data['turns'][0]['player_hp_before']);
        $this->assertNull($data['turns'][0]['player_action']['healing']);
        $this->assertContains('boss_sp_trace_missing', $data['quality_flags']);
        $this->assertNull($data['turns'][0]['boss_sp_before']);
        $this->assertNull($data['turns'][0]['boss_sp_after']);
        $this->assertSame(['direct_unclassified' => $battle->calculated_damage_total], $data['damage_by_source']);
    }

    public function test_three_denial_paths_remain_separate_and_overlap_is_not_double_counted(): void
    {
        [$battle, $event, $participant] = $this->fixture();
        $summary = $battle->summary;
        $summary['calculation']['engine_result']['ultimateDenialReasons'] = [
            'aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay', 'insufficient_sp',
        ];
        $battle->summary = $summary;
        $metrics = app(NationRaidTelemetryAdapter::class)->data($battle, $event, $participant)['counterplay_metrics'];
        foreach (['aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay', 'sp_denials', 'denial_overlap'] as $key) {
            $this->assertSame(1, $metrics[$key]);
        }
        $this->assertSame(0, $metrics['turn_20_delay']);
    }

    private function fixture(): array
    {
        $rules = app(NationRaidRules::class);
        $result = app(NationRaidBattleEngine::class)->resolve(new NationRaidBattleInput(
            stage: 13, cycleCurrentHp: 4_500_000, cycleMaxHp: 5_000_000, sourceCycleId: 'fixture',
            dominantLineage: 'guardian', seed: 8124, strategy: 'assault',
            player: new NationRaidPlayerSnapshot(maxHp: 1_000_000, defense: 1000, spirit: 1000,
                actions: array_map(fn ($turn) => new NationRaidPlayerActionSnapshot(
                    turn: $turn, damageSources: [['kind' => 'direct', 'damage' => 1000, 'hit_count' => 1]],
                ), range(1, 20)),
            ),
        ));
        $turns = $result->turns;
        // 測定への写像用fixture。camelCaseをsnake_caseと取り違えると必ず落ちる。
        $turns[0]['enemy_damage']['beforeCap'] = 11_111;
        $turns[0]['enemy_damage']['afterCap'] = 999;
        $observations = array_map(fn ($turn) => [
            'turn' => $turn['turn'], 'action_type' => 'normal', 'skill_id' => null, 'exact_identity' => null,
            'player_hp_before' => 1_000_000, 'player_sp_before' => 100,
            'healing' => $turn['turn'] === 1 ? 8 : 0, 'sp_spent' => 3,
        ], $turns);
        $event = new NationRaidEvent(['event_key' => 'telemetry-fixture', 'boss_name' => 'ヴァルグレイド',
            'ruleset_version' => 'v4', 'ruleset_snapshot' => $rules->rulesetSnapshot()]);
        $participant = new NationRaidParticipation(['is_nation_eligible' => true, 'reference_active_count' => 2]);
        $battle = new SavedBattle([
            'status' => 'resolved', 'battle_token' => 'fixture-token', 'target_cycle_no' => 13,
            'target_stage_no' => 13, 'target_cycle_kind' => 'main', 'target_form' => $result->form,
            'strategy' => 'assault', 'boss_species_key' => 'dragon', 'dominant_lineage' => 'guardian',
            'character_id' => 2, 'nation_id' => 1, 'account_id' => 123, 'raid_day' => 2, 'day_sortie_no' => 1, 'event_sortie_no' => 6,
            'turn_count' => 20, 'end_reason' => $result->outcome, 'calculated_damage_total' => $result->calculatedBossDamage,
            'applied_damage_total' => $result->calculatedBossDamage, 'coordination_damage_total' => 1200, 'nation_damage_total' => $result->calculatedBossDamage + 1200,
            'max_action_damage' => $result->maxOneActionDamage, 'job_art_slots_snapshot' => [], 'turn_log' => $turns,
            'killer_raw_rate' => 0.12, 'killer_effective_rate' => 0.24,
            'summary' => [
                'admission' => ['ruleset_hash' => $rules->rulesetHash(), 'stamina_cost' => 10,
                    'encounter' => ['current_hp' => 4_500_000, 'max_hp' => 5_000_000],
                    'player' => ['actor' => ['name' => 'private-person', 'level' => 30, 'current_job_id' => 1],
                        'abilities' => ['max_hp' => 1_000_000, 'defense' => 1000, 'spirit' => 1000], 'raid_resistance_rate' => 0.1]],
                'calculation' => ['engine_result' => $result->toArray(), 'player_turn_metrics' => $observations],
                'display' => ['boss_remaining_hp' => 4_478_800, 'shared_hp_after' => ['cycle_no' => 13, 'hp' => 4_478_800],
                    'coordination' => ['unique_count' => 3, 'bonus_rate' => 0.06, 'names' => ['private-person']]],
            ],
        ]);

        return [$battle, $event, $participant];
    }
}
