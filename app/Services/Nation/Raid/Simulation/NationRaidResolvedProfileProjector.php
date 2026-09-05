<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use LogicException;

/** bridgeの完全解決結果から、7日進行simulationに必要なcompact正本だけを抽出する。 */
final readonly class NationRaidResolvedProfileProjector
{
    public const MODEL_VERSION = 'turn-by-turn-live-defense-compact-v1';

    /** @return array<string, mixed> */
    public function project(
        NationRaidResolvedProfileContext $context,
        NationRaidTurnByTurnBridgeResult $bridge,
    ): array {
        $battle = $bridge->battleResult;
        if ($battle->stage !== $context->stage
            || $battle->form !== $context->startingForm
            || $battle->strategy !== $context->strategy
            || $battle->seed !== $context->sortieSeed
            || $battle->battleType !== NationRaidRules::BATTLE_TYPE
            || $battle->bossSpeciesKey !== NationRaidRules::BOSS_SPECIES_KEY
        ) {
            throw new LogicException('Resolved raid bridge result does not match its context.');
        }
        if ($bridge->knownGaps !== []) {
            throw new LogicException('Resolved raid bridge result still has known gaps.');
        }

        $metrics = [
            'observations' => 0,
            'ultimate_executed' => 0,
            'cap_binding_hits' => 0,
            'enemy_damage_actions' => 0,
            'counterplay_applied' => 0,
            'guard_consumed_actions' => 0,
            'parry_succeeded_actions' => 0,
            'guts_triggered_actions' => 0,
            'actual_hp_loss' => 0,
            'counter_damage' => 0,
        ];
        foreach ($battle->turns as $turn) {
            $metrics['observations'] += ($turn['pending_kind'] ?? null) === 'observation' ? 1 : 0;
            $metrics['ultimate_executed'] += ($turn['enemy_action_id'] ?? null) === 'ten_lineage_end' ? 1 : 0;
            $metrics['counterplay_applied'] += (($turn['counterplay']['applied'] ?? false) === true) ? 1 : 0;

            $enemyDamage = is_array($turn['enemy_damage'] ?? null) ? $turn['enemy_damage'] : null;
            if ($enemyDamage !== null) {
                $metrics['enemy_damage_actions']++;
                $metrics['cap_binding_hits'] += ($enemyDamage['beforeCap'] ?? 0) > ($enemyDamage['afterCap'] ?? 0) ? 1 : 0;
                $defense = is_array($enemyDamage['playerDefense'] ?? null) ? $enemyDamage['playerDefense'] : [];
                $metrics['guard_consumed_actions'] += ($defense['guard_consumed'] ?? false) === true ? 1 : 0;
                $metrics['parry_succeeded_actions'] += ($defense['parry_succeeded'] ?? false) === true ? 1 : 0;
                $metrics['guts_triggered_actions'] += ($defense['guts_triggered'] ?? false) === true ? 1 : 0;
                $metrics['actual_hp_loss'] += max(0, (int) ($defense['actual_hp_loss'] ?? 0));
            }

            foreach (($turn['player_damage']['sources'] ?? []) as $source) {
                if (is_array($source) && ($source['kind'] ?? null) === NationRaidRules::DAMAGE_COUNTER) {
                    $metrics['counter_damage'] += max(0, (int) ($source['applied_damage'] ?? 0));
                }
            }
        }

        return [
            'context_key' => $context->key(),
            'context' => $context->toArray(),
            'result' => [
                'battle_type' => $battle->battleType,
                'stage' => $battle->stage,
                'form' => $battle->form,
                'boss_species_key' => $battle->bossSpeciesKey,
                'sortie_seed' => $battle->seed,
                'ruleset_hash' => $battle->rulesetHash,
                'strategy' => $battle->strategy,
                'turns_completed' => $battle->turnsCompleted,
                'outcome' => $battle->outcome,
                'player_remaining_hp' => $battle->playerRemainingHp,
                'boss_virtual_remaining_hp' => $battle->bossVirtualRemainingHp,
                'calculated_boss_damage' => $battle->calculatedBossDamage,
                'max_one_action_damage' => $battle->maxOneActionDamage,
                't20_starting_sp' => $battle->t20StartingSp,
                'ultimate_denial_reasons' => $battle->ultimateDenialReasons,
                'reservation_failure_count' => $battle->reservationFailureCount,
                'metrics' => $metrics,
            ],
        ];
    }
}
