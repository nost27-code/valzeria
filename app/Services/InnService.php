<?php

namespace App\Services;

use App\Models\Character;
use App\Services\CharacterStatusService;

class InnService
{
    /**
     * 宿屋でHPを全回復する
     */
    public function rest(Character $character): array
    {
        $statusService = new CharacterStatusService();
        $finalStats = $statusService->getFinalStats($character);
        
        $maxHp = $finalStats['max_hp'] ?? $character->hp_base;
        $maxMp = $finalStats['max_mp'] ?? 0;

        // 回復の必要があるか判定
        $needsHpRecovery = $character->current_hp < $maxHp;
        $needsMpRecovery = $maxMp > 0 && $character->current_mp < $maxMp;

        // すでにHPとSPが満タン（または回復対象外）なら宿泊不要
        if (!$needsHpRecovery && !$needsMpRecovery) {
            return ['success' => false, 'message' => 'HPとSPは既に全回復しています。'];
        }

        $cooldownSeconds = app(CooldownSettingService::class)->innSeconds();

        $character->current_hp = $maxHp;
        $character->current_mp = $maxMp;
        $character->exploration_cooldown_until = $cooldownSeconds > 0 ? now()->addSeconds($cooldownSeconds) : null;
        $character->save();
        app(ExplorationStateService::class)->reset($character);

        return ['success' => true, 'cooldown_seconds' => $cooldownSeconds];
    }
}
