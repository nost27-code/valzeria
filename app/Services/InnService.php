<?php

namespace App\Services;

use App\Models\Character;
use App\Services\CharacterStatusService;
use Illuminate\Support\Facades\DB;

class InnService
{
    private const MAX_CONSECUTIVE_RESCUES = 2;

    public function fee(Character $character): int
    {
        return $this->quote($character)['fee'];
    }

    /** @return array{fee: int, pricing_rule: string} */
    public function quote(Character $character): array
    {
        $level = max(1, (int) $character->level);
        $beginnerFee = max(1, (int) config('inn.beginner.fee', 10));
        $beginnerLevelMax = max(0, (int) config('inn.beginner.level_max', 20));

        if ($level <= $beginnerLevelMax) {
            return ['fee' => $beginnerFee, 'pricing_rule' => 'beginner_level'];
        }

        if ($this->isWithinBeginnerPeriod($character)) {
            return ['fee' => $beginnerFee, 'pricing_rule' => 'beginner_period'];
        }

        $feePerLevel = max(1, (int) config('inn.fee_per_level', 10));

        return ['fee' => $level * $feePerLevel, 'pricing_rule' => 'regular'];
    }

    /**
     * 宿屋でHP/SPを全回復する
     */
    public function rest(Character $character): array
    {
        $result = DB::transaction(function () use ($character): array {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();

            CharacterStatusService::clearRequestCache((int) $lockedCharacter->id);

            return $this->restLocked($lockedCharacter);
        }, 3);

        $character->refresh();
        CharacterStatusService::clearRequestCache((int) $character->id);

        return $result;
    }

    private function restLocked(Character $character): array
    {
        $statusService = new CharacterStatusService();
        $finalStats = $statusService->getFinalStats($character);

        $maxHp = $finalStats['max_hp'] ?? $character->hp_base;
        $maxMp = $finalStats['max_mp'] ?? 0;

        if ($character->current_hp >= $maxHp && ($maxMp === 0 || $character->current_mp >= $maxMp)) {
            return ['success' => false, 'message' => 'HP/SPが満タンです。宿屋で休む必要はありません。'];
        }

        $quote       = $this->quote($character);
        $fee         = $quote['fee'];
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
                'pricing_rule' => $quote['pricing_rule'],
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

    private function isWithinBeginnerPeriod(Character $character): bool
    {
        $periodDays = max(0, (int) config('inn.beginner.period_days', 10));
        $createdAt = $character->created_at;
        if ($periodDays === 0 || $createdAt === null) {
            return false;
        }

        $now = now();

        return $createdAt->lte($now) && $now->lt($createdAt->copy()->addHours($periodDays * 24));
    }
}
