<?php

namespace App\Services;

use App\Models\Character;
use App\Services\Battle\BattleResult;
use App\Services\Battle\PvPBattleExecutionContext;
use InvalidArgumentException;

class TrainingGroundPvpBattleService
{
    public function __construct(private readonly PvPBattleService $pvpBattleService) {}

    /**
     * 順位・戦績・キャラクター状態・対戦ログ・実績計測を更新しない対人模擬戦。
     *
     * @return array{
     *     context:string,
     *     context_label:string,
     *     opponent_id:int,
     *     opponent_name:string,
     *     attacker_won:bool,
     *     attacker_hp:int,
     *     attacker_max_hp:int,
     *     defender_hp:int,
     *     defender_max_hp:int,
     *     max_turns:int,
     *     result:BattleResult
     * }
     */
    public function practice(Character $attacker, Character $defender): array
    {
        if (! $attacker->exists || ! $defender->exists || $attacker->is($defender)) {
            throw new InvalidArgumentException('対人模擬戦には別のキャラクターを指定してください。');
        }

        $resolution = $this->pvpBattleService->resolveBattle(
            $attacker,
            $defender,
            PvPBattleExecutionContext::trainingGround(),
        );

        return [
            'context' => 'pvp',
            'context_label' => '対人模擬戦',
            'opponent_id' => (int) $defender->id,
            'opponent_name' => (string) $defender->name,
            'attacker_won' => $resolution->attackerWon,
            'attacker_hp' => $resolution->attackerHp,
            'attacker_max_hp' => $resolution->attackerMaxHp,
            'defender_hp' => $resolution->defenderHp,
            'defender_max_hp' => $resolution->defenderMaxHp,
            'max_turns' => $resolution->turnCount,
            // resolveBattle() が生成した発生順を保ち、ログの並べ替えや差し込みは行わない。
            'result' => $resolution->result,
        ];
    }
}
