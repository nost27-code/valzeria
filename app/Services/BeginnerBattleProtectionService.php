<?php

namespace App\Services;

use App\Models\Character;

class BeginnerBattleProtectionService
{
    /**
     * 現在終了した戦闘が、駆け出し向け敗北ロスト保護の対象かを返す。
     *
     * wins / losses はこの判定後に加算されるため、累計99戦なら100戦目として保護する。
     *
     * @return array{active: bool, battle_number: int, battle_limit: int, message: ?string, support_label: ?string}
     */
    public function forDefeat(Character $character): array
    {
        $battleLimit = max(0, (int) config('battle.beginner_defeat_protection.battle_limit', 100));
        $completedBattles = max(0, (int) ($character->wins ?? 0))
            + max(0, (int) ($character->losses ?? 0));
        $battleNumber = $completedBattles + 1;
        $active = $battleLimit > 0 && $battleNumber <= $battleLimit;

        return [
            'active' => $active,
            'battle_number' => $battleNumber,
            'battle_limit' => $battleLimit,
            'message' => $active
                ? "【駆け出しの加護】不思議な加護があなたを包んだ！ {$battleLimit}戦目までは敗北しても、Gold・戦利品・ヴァルモンの卵を失わない。"
                : null,
            'support_label' => $active
                ? '不思議な加護に守られ、Gold・戦利品・ヴァルモンの卵は失われなかった！'
                : null,
        ];
    }

    /** @return array{total_lost: int, loss_percent: int, material_lost: int, item_lost: int, materials: array, items: array} */
    public function protectedMaterialPenalty(): array
    {
        return [
            'total_lost' => 0,
            'loss_percent' => 0,
            'material_lost' => 0,
            'item_lost' => 0,
            'materials' => [],
            'items' => [],
        ];
    }

    /** @return array{fee: int, amount: int, rate: float, rate_label: string} */
    public function protectedGoldLoss(): array
    {
        return [
            'fee' => 0,
            'amount' => 0,
            'rate' => 0.0,
            'rate_label' => '0%',
        ];
    }
}
