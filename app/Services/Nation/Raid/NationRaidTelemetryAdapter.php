<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter;
use LogicException;

/** 保存済み出撃を計測契約へ射影する。再戦闘・ログ文字列解析・現在装備の参照はしない。 */
final readonly class NationRaidTelemetryAdapter
{
    public const CONTRACT = 'raid-turn-observation-v1';

    public function __construct(private NationRaidSimulationLineageAdapter $lineages) {}

    public function data(SavedBattle $battle, NationRaidEvent $event, NationRaidParticipation $participation): array
    {
        if ($battle->status !== SavedBattle::STATUS_RESOLVED) {
            throw new LogicException('Only resolved sorties have battle turn observations.');
        }
        $admission = $battle->summary['admission'];
        $player = $admission['player'];
        $engine = $battle->summary['calculation']['engine_result'];
        $observations = array_column($battle->summary['calculation']['player_turn_metrics'] ?? [], null, 'turn');
        $rules = $event->ruleset_snapshot;
        $virtualHp = (int) $rules['fixed']['virtual_max_hp'];
        $flags = ['player_hit_critical_counts_unavailable'];
        $loadout = [];
        $slotsByIdentity = [];
        foreach ($battle->job_art_slots_snapshot as $slot) {
            $identity = $slot['exact_identity'];
            $parts = $identity === null ? [] : explode(':', $identity, 3);
            $loadout[] = [
                'slot_no' => $slot['slot'], 'skill_id' => $slot['skill_id'],
                'job_id' => isset($parts[0]) ? (int) $parts[0] : null,
                'rank' => isset($parts[1]) ? (int) $parts[1] : null,
                'name' => $slot['name'], 'lineage' => $slot['canonical_lineage'],
            ];
            if ($identity !== null) {
                $slotsByIdentity[$identity] = $slot;
            }
        }
        $spByTurn = [];
        foreach ($engine['spTrace'] as $change) {
            $spByTurn[$change['turn']][] = $change;
        }
        $turns = $sources = $counterplay = [];
        $healing = $damageTaken = 0;
        foreach ($battle->turn_log as $turn) {
            $number = $turn['turn'];
            $observed = $observations[$number] ?? null;
            if ($observed === null) {
                $flags[] = 'player_turn_observation_missing';
            }
            $turnSources = [];
            foreach ($turn['player_damage']['sources'] as $source) {
                $key = match ($source['kind']) {
                    'direct' => match ($observed['action_type'] ?? null) {
                        'normal' => 'normal', 'job_art' => 'job_art_direct', default => 'direct_unclassified',
                    },
                    'simultaneous' => 'other', 'dot' => 'dot', 'counter' => 'counter',
                    'eclipse_backlash' => 'eclipse_backlash',
                    default => throw new LogicException('Unmapped raid damage source.'),
                };
                $turnSources[$key] = ($turnSources[$key] ?? 0) + $source['applied_damage'];
                $sources[$key] = ($sources[$key] ?? 0) + $source['applied_damage'];
            }
            $enemy = $turn['enemy_damage'];
            $response = $turn['counterplay'];
            $kind = $turn['pending_kind'];
            $actionId = $turn['enemy_action_id'];
            $telegraphed = in_array($kind, ['adaptive', 'ultimate'], true);
            $this->count($counterplay, 'telegraphs_seen', $telegraphed);
            $this->count($counterplay, 'responses_selected', $response !== null);
            $this->count($counterplay, 'responses_applied', (bool) ($response['applied'] ?? false));
            $this->count($counterplay, 'ultimate_casts', $actionId === 'ten_lineage_end');
            $this->count($counterplay, 'ultimate_fallbacks', $number === 20 && $actionId !== 'ten_lineage_end' && $actionId !== null);
            $this->count($counterplay, 'adaptive_casts', $kind === 'adaptive' && $actionId !== null);
            $this->count($counterplay, 'adaptive_delays', $kind === 'adaptive' && (bool) ($response['delay'] ?? false));
            if ($response['applied'] ?? false) {
                $key = match ($response['effect']) {
                    'counter_intercept' => 'guards_20', 'ultimate_guard' => 'guards_35', 'fortress_guard' => 'guards_50',
                    'hunt_cancel' => 'hunt_delays', 'readiness_delay' => 'command_delays',
                    'field_suppression' => 'effect_suppressions', 'break_preparation' => 'preparations_destroyed',
                    default => null,
                };
                if ($key !== null) {
                    $this->count($counterplay, $key, true);
                }
            }
            $trace = $spByTurn[$number] ?? [];
            if ($trace === []) {
                $flags[] = 'boss_sp_trace_missing';
            }
            $spBefore = $trace[0]['before'] ?? null;
            $bossSp = $trace === [] ? null : $trace[array_key_last($trace)]['after'];
            $hpBefore = $virtualHp;
            $virtualHp = max(0, $virtualHp - $turn['player_damage']['total_damage']);
            $healing += $observed['healing'] ?? 0;
            $damageTaken += ($enemy['finalDamage'] ?? 0) + $turn['player_self_damage'];
            $identity = $observed['exact_identity'] ?? null;
            $turns[] = [
                'turn' => $number, 'boss_phase' => $battle->target_form,
                'player_hp_before' => $observed['player_hp_before'] ?? null,
                'player_hp_after' => $turn['player_hp_after'],
                'player_sp_before' => $observed['player_sp_before'] ?? null,
                'player_sp_after' => $turn['player_sp_after'],
                'boss_sp_before' => $spBefore, 'boss_sp_after' => $bossSp,
                // turn内は出撃独立の仮想HP。共有HPは下のevent_snapshot/segmentsへ分離。
                'boss_hp_before' => $hpBefore, 'boss_hp_after' => $virtualHp,
                'player_self_damage' => $turn['player_self_damage'],
                'player_action' => [
                    'action_type' => $observed['action_type'] ?? 'unknown',
                    'action_key' => $identity ?? ($observed['action_type'] ?? 'unknown'),
                    'skill_id' => $observed['skill_id'] ?? null, 'skill_name' => $observed['skill_name'] ?? '',
                    'lineage' => $slotsByIdentity[$identity]['canonical_lineage'] ?? null,
                    'damage_total' => $turn['player_damage']['total_damage'], 'damage_by_source' => $turnSources,
                    'healing' => $observed['healing'] ?? null, 'sp_spent' => $observed['sp_spent'] ?? null,
                ],
                'boss_action' => [
                    'action_key' => $actionId ?? 'none', 'telegraphed' => $telegraphed,
                    'response' => $response['effect'] ?? 'none', 'response_applied' => $response['applied'] ?? false,
                    'outcome' => $actionId === null ? 'delayed' : ($kind === 'observation' ? 'observation' : 'resolved'),
                    'observation_reason' => $turn['observation_reason'],
                    'hit_count' => count(array_filter($enemy['hits'] ?? [], fn ($hit) => ($hit['outcome'] ?? null) === 'hit')),
                    'damage_before_cap' => $enemy['beforeCap'] ?? null, 'damage_after_cap' => $enemy['afterCap'] ?? null,
                    'damage_cap' => $enemy['cap'] ?? null, 'damage_final' => $enemy['finalDamage'] ?? 0,
                    'actual_hp_loss' => $enemy['playerDefense']['actual_hp_loss'] ?? null,
                    'status_keys' => $enemy['appliedEffects'] ?? [],
                ],
            ];
        }
        $denials = $engine['ultimateDenialReasons'];
        foreach (['aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay', 'turn_20_delay'] as $reason) {
            $this->count($counterplay, $reason, in_array($reason, $denials, true));
        }
        $this->count($counterplay, 'sp_denials', in_array('insufficient_sp', $denials, true));
        $this->count($counterplay, 'denial_overlap', count(array_intersect($denials, ['aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay'])) > 1);
        if (count($observations) !== $battle->turn_count) {
            $flags[] = 'player_turn_observation_missing';
        }
        if (array_sum($sources) !== $battle->calculated_damage_total) {
            $flags[] = 'damage_source_total_mismatch';
        }
        $canonicalByRaid = array_flip($this->lineages->mappings());
        $coordination = $battle->summary['display']['coordination'] ?? [];
        $parameters = $battle->target_parameter_snapshot ?? [];

        return [
            'event_key' => $event->event_key, 'battle_token' => $battle->battle_token,
            'result_status' => $battle->status, 'ruleset_version' => $event->ruleset_version,
            'raid_day' => $battle->raid_day, 'day_sortie_no' => $battle->day_sortie_no, 'event_sortie_no' => $battle->event_sortie_no,
            'boss_cycle_no' => $battle->target_cycle_no, 'boss_phase' => $battle->target_form,
            'character_id' => $battle->character_id, 'nation_id' => $battle->nation_id,
            'is_nation_eligible' => $participation->is_nation_eligible,
            'nation_active_count' => $participation->reference_active_count,
            'player_level' => $player['actor']['level'], 'player_job_id' => $player['actor']['current_job_id'],
            'adaptive_lineage' => $canonicalByRaid[$battle->dominant_lineage] ?? null,
            'end_reason' => $battle->end_reason, 'turn_count' => $battle->turn_count,
            'boss_hp_before' => $admission['encounter']['current_hp'],
            'boss_hp_after' => $battle->summary['display']['boss_remaining_hp'],
            'calculated_damage_total' => $battle->calculated_damage_total, 'applied_damage_total' => $battle->applied_damage_total,
            'max_action_damage' => $battle->max_action_damage, 'damage_taken_total' => $damageTaken, 'healing_total' => $healing,
            'player_hp_ratio_end' => $engine['playerRemainingHp'] / $player['abilities']['max_hp'],
            'battle_started_at' => $battle->started_at, 'battle_resolved_at' => $battle->resolved_at,
            'loadout_snapshot' => $loadout, 'loadout_lineages' => array_values(array_filter(array_column($loadout, 'lineage'))),
            'damage_by_source' => $sources, 'counterplay_metrics' => $counterplay, 'turns' => $turns,
            'player_snapshot' => $player['abilities'] + ['level' => $player['actor']['level'], 'job_id' => $player['actor']['current_job_id']],
            'event_snapshot' => [
                'measurement_contract' => self::CONTRACT, 'ruleset_hash' => $admission['ruleset_hash'],
                'boss_name' => $event->boss_name, 'boss_max_hp' => $admission['encounter']['max_hp'],
                'boss_attack' => $parameters['stage']['attack'] ?? null,
                'boss_magic' => $parameters['stage']['magic'] ?? null,
                'boss_defense' => $parameters['boss']['defense'] ?? null,
                'boss_spirit' => $parameters['boss']['spirit'] ?? null,
                'boss_agility' => $parameters['boss']['agility'] ?? null,
                'boss_luck' => $parameters['boss']['luck'] ?? null,
                'turn_stages' => $rules['turn_bands'], 'phase_config' => $rules['forms'],
                'max_turns' => $rules['fixed']['max_turns'], 'stamina_cost' => $admission['stamina_cost'],
                'stage_no' => $battle->target_stage_no, 'echo_no' => $battle->target_echo_no, 'cycle_kind' => $battle->target_cycle_kind,
                'strategy' => $battle->strategy, 'boss_species_key' => $battle->boss_species_key,
                'killer_raw_rate' => $battle->killer_raw_rate, 'killer_effective_rate' => $battle->killer_effective_rate,
                'killer_rate_cap' => $rules['fixed']['boss_killer_damage_rate_cap'],
                'killer_rate_multiplier' => $rules['fixed']['boss_killer_damage_rate_multiplier'],
                'armor_resistance_rate' => $player['raid_resistance_rate'],
                'coordination_unique_count' => $coordination['unique_count'] ?? 0,
                'coordination_rate' => $coordination['bonus_rate'] ?? 0,
                'coordination_damage' => $battle->coordination_damage_total, 'nation_damage' => $battle->nation_damage_total,
                'turn_hp_basis' => 'per_sortie_virtual_hp', 't20_starting_sp' => $engine['t20StartingSp'] < 0 ? null : $engine['t20StartingSp'],
                'ultimate_denial_reasons' => $denials, 'reservation_failures' => $engine['reservationFailureCount'],
                'settlement_cycle_after' => $battle->summary['display']['shared_hp_after']['cycle_no'],
                'settlement_hp_after' => $battle->summary['display']['shared_hp_after']['hp'],
            ],
            'quality_flags' => array_values(array_unique($flags)),
        ];
    }

    private function count(array &$metrics, string $key, bool $occurred): void
    {
        $metrics[$key] = ($metrics[$key] ?? 0) + (int) $occurred;
    }
}
