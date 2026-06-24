<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\DailyKisekiDropLog;
use App\Models\Enemy;
use App\Models\KisekiTransaction;
use Illuminate\Support\Facades\DB;

class KisekiDropService
{
    private const FREE_KISEKI_DROP_RATE_PER_MILLION = 300;
    private const FREE_KISEKI_DROP_AMOUNT = 1;
    private const DAILY_FREE_KISEKI_DROP_LIMIT = 3;
    private const KISEKI_DROP_MIN_ENEMY_LEVEL_DIFF = 20;

    public function tryDropFromBattle(Character $character, Enemy $enemy, Area $area, string $battleType): ?array
    {
        if (!$this->isEligibleBattleType($battleType)) {
            return null;
        }

        $enemyLevel = (int) ($enemy->level ?? 0);
        $characterLevel = (int) ($character->level ?? 1);
        if ($enemyLevel < ($characterLevel - self::KISEKI_DROP_MIN_ENEMY_LEVEL_DIFF)) {
            return null;
        }

        $today = now('Asia/Tokyo')->toDateString();
        $amount = self::FREE_KISEKI_DROP_AMOUNT;
        $dailyLimit = max(0, min(20, app(GameSettingService::class)->getInt('kiseki.daily_free_drop_limit', self::DAILY_FREE_KISEKI_DROP_LIMIT)));
        $dropRatePerMillion = max(0, min(100000, app(GameSettingService::class)->getInt('kiseki.free_drop_rate_per_million', self::FREE_KISEKI_DROP_RATE_PER_MILLION)));

        return DB::transaction(function () use ($character, $enemy, $area, $today, $enemyLevel, $characterLevel, $amount, $dailyLimit, $dropRatePerMillion) {
            DB::table('daily_kiseki_drop_logs')->insertOrIgnore([
                'character_id' => $character->id,
                'drop_date' => $today,
                'dropped_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dailyLog = DailyKisekiDropLog::query()
                ->where('character_id', $character->id)
                ->where('drop_date', $today)
                ->lockForUpdate()
                ->first();

            if (!$dailyLog || (int) $dailyLog->dropped_count >= $dailyLimit) {
                return null;
            }

            if ($dropRatePerMillion <= 0 || random_int(1, 1_000_000) > $dropRatePerMillion) {
                return null;
            }

            $lockedCharacter = Character::query()->lockForUpdate()->findOrFail($character->id);
            $freeTotal = (int) ($lockedCharacter->free_kiseki ?? 0) + $amount;
            $paidTotal = (int) ($lockedCharacter->paid_kiseki ?? 0);
            $lockedCharacter->free_kiseki = $freeTotal;
            $lockedCharacter->kiseki = $freeTotal + $paidTotal;
            $lockedCharacter->save();

            $dailyLog->dropped_count = (int) $dailyLog->dropped_count + $amount;
            $dailyLog->save();

            $transaction = KisekiTransaction::create([
                'character_id' => $lockedCharacter->id,
                'kiseki_type' => 'free',
                'amount' => $amount,
                'transaction_type' => 'battle_drop',
                'source_type' => 'battle',
                'source_id' => null,
                'area_id' => $area->id,
                'enemy_id' => $enemy->id,
                'enemy_level' => $enemyLevel,
                'character_level' => $characterLevel,
                'daily_dropped_count' => (int) $dailyLog->dropped_count,
                'description' => '戦闘勝利時の輝石ドロップ',
            ]);

            $character->setAttribute('free_kiseki', $freeTotal);
            $character->setAttribute('paid_kiseki', $paidTotal);
            $character->setAttribute('kiseki', $freeTotal + $paidTotal);

            return [
                'amount' => $amount,
                'kiseki_type' => 'free',
                'daily_dropped_count' => (int) $dailyLog->dropped_count,
                'daily_limit' => $dailyLimit,
                'transaction_id' => $transaction->id,
            ];
        });
    }

    public function attachBattleLog(int $transactionId, int $battleLogId): void
    {
        KisekiTransaction::whereKey($transactionId)
            ->where('transaction_type', 'battle_drop')
            ->update(['source_id' => $battleLogId]);
    }

    private function isEligibleBattleType(string $battleType): bool
    {
        return $battleType === 'normal';
    }
}
