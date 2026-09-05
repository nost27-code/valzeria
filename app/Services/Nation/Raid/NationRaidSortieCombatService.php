<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\JobClass;
use App\Models\NationRaidBattleResult as SavedBattle;
use App\Models\Skill;
use App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnActionProfileBridge;

/** 予約後の個人準備transactionで保存したsnapshotだけで戦う。共有HPのlockは保持しない。 */
class NationRaidSortieCombatService
{
    public function resolve(SavedBattle $sortie): array
    {
        $snapshot = $sortie->summary['admission'];
        throw_unless(isset($snapshot['player']) && is_array($snapshot['player']), \DomainException::class, '出撃準備が未確定です。');
        $player = $snapshot['player'];
        $prepared = $player['actor'];
        $prepared['job_arts'] = array_map(function (array $attributes): Skill {
            $skill = new Skill;
            $skill->setRawAttributes($attributes, true);

            return $skill;
        }, $prepared['job_arts']);
        $character = new Character([
            'name' => $prepared['name'], 'level' => $prepared['level'],
            'current_job_id' => $prepared['current_job_id'],
        ]);
        $character->setAttribute('id', $sortie->character_id);
        $job = $prepared['current_job_id'] === null ? null : new JobClass([
            'name' => $player['character']['job_name'], 'key' => $prepared['job_key'],
            'normal_attack_type' => $prepared['normal_attack_type'],
        ]);
        $job?->setAttribute('id', $prepared['current_job_id']);
        $character->setRelation('currentJob', $job);
        $character->setRelation('jobClass', $job);
        $encounter = $snapshot['encounter'];
        $input = new NationRaidBattleInput(
            stage: $encounter['stage'], cycleCurrentHp: $encounter['current_hp'],
            cycleMaxHp: $encounter['max_hp'], sourceCycleId: (string) $snapshot['cycle_id'],
            dominantLineage: $sortie->dominant_lineage, seed: (int) $snapshot['engine_seed'],
            strategy: $sortie->strategy,
            player: new NationRaidPlayerSnapshot(
                maxHp: $player['abilities']['max_hp'], defense: $player['abilities']['defense'],
                spirit: $player['abilities']['spirit'], maxSp: $player['abilities']['max_sp'],
                finalDamageReductionRate: $player['raid_resistance_rate'],
                counterplayEnabled: $player['counterplay_enabled'],
                bossSetExactIdentities: $player['boss_set_exact_identities'],
            ),
        );
        $result = app(NationRaidTurnByTurnActionProfileBridge::class)->resolveProfile($character, $input, $prepared);
        $rules = app(NationRaidRules::class);
        throw_unless(hash_equals($rules->rulesetHash(), $result->battleResult->rulesetHash)
            && $rules->matchesCombatRulesetHash($snapshot['ruleset_hash']), \LogicException::class, 'Raid ruleset changed during combat.');

        return [
            // HPはadmissionの個体値で解決済み。旧回も元のruleset識別子を保持する。
            'engine_result' => [...$result->battleResult->toArray(), 'rulesetHash' => $snapshot['ruleset_hash']],
            'player_battle_logs' => $result->playerBattleLogs,
            'player_turn_metrics' => $result->playerTurnMetrics,
        ];
    }
}
