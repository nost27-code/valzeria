<?php

namespace App\Services;

use App\Models\BattleLog;
use App\Models\Character;

class BattleLogService
{
    /**
     * 個人戦闘ログを記録する
     */
    public function addLog(Character $character, int $areaId, int $enemyId, string $battleType, string $result, int $expGained, int $goldGained, int $levelUpCount, string $logText, ?int $droppedItemId = null, ?int $droppedCharacterItemId = null, int $goldLost = 0): BattleLog
    {
        return BattleLog::create([
            'character_id' => $character->id,
            'area_id' => $areaId,
            'enemy_id' => $enemyId,
            'battle_type' => $battleType,
            'result' => $result,
            'exp_gained' => $expGained,
            'gold_gained' => $goldGained,
            'gold_lost' => max(0, $goldLost),
            'level_up_count' => $levelUpCount,
            'log_text' => $logText,
            'dropped_item_id' => $droppedItemId,
            'dropped_character_item_id' => $droppedCharacterItemId,
        ]);
    }
}
