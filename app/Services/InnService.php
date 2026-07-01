<?php

namespace App\Services;

use App\Models\Character;
use App\Services\CharacterStatusService;

class InnService
{
    private const MAX_CONSECUTIVE_RESCUES = 2;

    public function fee(Character $character): int
    {
        return max(10, $character->level * 10);
    }

    /**
     * 宿屋でHPを全回復する
     */
    public function rest(Character $character): array
    {
        $statusService = new CharacterStatusService();
        $finalStats = $statusService->getFinalStats($character);

        $maxHp = $finalStats['max_hp'] ?? $character->hp_base;
        $maxMp = $finalStats['max_mp'] ?? 0;

        if ($character->current_hp >= $maxHp && ($maxMp === 0 || $character->current_mp >= $maxMp)) {
            return ['success' => false, 'message' => 'HP/SPが満タンです。宿屋で休む必要はありません。'];
        }

        $fee         = $this->fee($character);
        $handGold    = (int) ($character->money ?? 0);
        $totalWealth = $handGold + (int) ($character->bank_gold ?? 0);

        // 手持ちが足りず、かつ全財産でも払えない → 救済（手持ち全額支払い）
        if ($handGold < $fee && $totalWealth < $fee) {
            $rescueStreak = (int) ($character->inn_rescue_streak ?? 0);
            if ($rescueStreak >= self::MAX_CONSECUTIVE_RESCUES) {
                return [
                    'success' => false,
                    'message' => '宿代が足りません。素材の売却や補給所の回復薬を確認してから出直してください。',
                    'fee' => $fee,
                    'paid' => 0,
                    'rescued' => false,
                    'rescue_refused' => true,
                    'rescue_streak' => $rescueStreak,
                ];
            }

            $paid    = $handGold;
            $rescued = true;
        // 手持ちが足りないが全財産なら払える → 拒否
        } elseif ($handGold < $fee) {
            return [
                'success' => false,
                'message' => "所持金が足りません。宿泊料金は {$fee}G です。銀行からGoldを引き出してから宿屋に泊まってください。",
                'fee'     => $fee,
            ];
        // 手持ちで払える
        } else {
            $paid    = $fee;
            $rescued = false;
        }

        if ($paid > 0) {
            app(GoldService::class)->spend($character, $paid, 'inn', '宿屋で休んだ', null, null, [
                'fee' => $fee,
                'paid' => $paid,
                'rescued' => $rescued,
                'level' => (int) $character->level,
            ]);
        }

        $cooldownSeconds = 0;

        $character->current_hp = $maxHp;
        $character->current_mp = $maxMp;
        $character->exploration_cooldown_until = null;
        $character->inn_rescue_streak = $rescued
            ? min(self::MAX_CONSECUTIVE_RESCUES, (int) ($character->inn_rescue_streak ?? 0) + 1)
            : 0;
        $character->save();
        app(ExplorationStateService::class)->reset($character);

        return [
            'success'          => true,
            'cooldown_seconds' => $cooldownSeconds,
            'fee'              => $fee,
            'paid'             => $paid,
            'rescued'          => $rescued,
            'rescue_streak'    => (int) ($character->inn_rescue_streak ?? 0),
        ];
    }
}
