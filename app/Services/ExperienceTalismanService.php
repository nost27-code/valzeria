<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use Illuminate\Support\Facades\DB;

class ExperienceTalismanService
{
    public const ITEM_KEY = 'experience_talisman';

    public const DEFAULT_EXP_BONUS_PERCENT = 25;

    public const DEFAULT_ELIGIBLE_VICTORIES = 50;

    public const MAX_PLAYER_LEVEL = 255;

    public function use(Character $character): array
    {
        return DB::transaction(function () use ($character): array {
            $item = $this->definition();
            $name = (string) ($item['name'] ?? '経験の護符');
            $row = CharacterConsumableItem::query()
                ->where('character_id', $character->id)
                ->where('item_key', self::ITEM_KEY)
                ->lockForUpdate()
                ->first();

            if (! $row || (int) $row->quantity <= 0) {
                return ['success' => false, 'message' => "{$name}を所持していません。"];
            }

            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedCharacter->level >= self::MAX_PLAYER_LEVEL) {
                return ['success' => false, 'message' => "Lv255では{$name}を使用できません。"];
            }

            $remaining = max(0, (int) $lockedCharacter->experience_talisman_wins_remaining)
                + $this->eligibleVictoriesPerItem();

            $row->decrement('quantity');
            $lockedCharacter->forceFill([
                'experience_talisman_wins_remaining' => $remaining,
            ])->save();
            $character->setRawAttributes($lockedCharacter->getAttributes(), true);

            return [
                'success' => true,
                'message' => "{$name}を使用しました。通常探索の勝利時に得られる経験値が{$this->expBonusPercent()}%増加します（残り{$remaining}勝）。",
                'remaining' => $remaining,
                'bonus_percent' => $this->expBonusPercent(),
            ];
        });
    }

    /**
     * 通常探索の勝利報酬が確定する直前に呼び出す。
     *
     * 探索開始後に護符が使用される場合があるため、呼び出し元が保持するCharacterは更新せず、
     * DB上の最新行をロックして残数だけを減らす。これにより、後続の探索報酬保存が
     * 護符使用で加算された残数を古い値で上書きしない。
     */
    public function applyToNormalExplorationVictory(
        Character $character,
        int $rewardExp,
        ?int $baseBattleExp = null
    ): array {
        $rewardExp = max(0, $rewardExp);
        $baseBattleExp ??= $rewardExp;

        if ($rewardExp <= 0 || $baseBattleExp <= 0) {
            return $this->notAppliedResult(
                $rewardExp,
                max(0, (int) $character->experience_talisman_wins_remaining)
            );
        }

        return DB::transaction(function () use ($character, $rewardExp): array {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();
            $remainingBefore = max(0, (int) $lockedCharacter->experience_talisman_wins_remaining);

            if ((int) $lockedCharacter->level >= self::MAX_PLAYER_LEVEL || $remainingBefore <= 0) {
                return $this->notAppliedResult($rewardExp, $remainingBefore);
            }

            $bonusExp = intdiv($rewardExp * $this->expBonusPercent(), 100);
            $remaining = $remainingBefore - 1;
            $lockedCharacter->forceFill([
                'experience_talisman_wins_remaining' => $remaining,
            ])->save();

            return [
                'applied' => true,
                'base_exp' => $rewardExp,
                'bonus_exp' => $bonusExp,
                'total_exp' => $rewardExp + $bonusExp,
                'remaining' => $remaining,
                'bonus_percent' => $this->expBonusPercent(),
            ];
        });
    }

    /**
     * 探索開始時点のCharacterで、報酬との同一transactionを組む必要があるかを判定する。
     * 使用前に始まっていた探索には次の勝利から効果を適用する。
     */
    public function shouldAttemptNormalExplorationVictory(
        Character $character,
        int $rewardExp,
        ?int $baseBattleExp = null
    ): bool {
        $baseBattleExp ??= $rewardExp;

        return $rewardExp > 0
            && $baseBattleExp > 0
            && (int) $character->level < self::MAX_PLAYER_LEVEL
            && (int) $character->experience_talisman_wins_remaining > 0;
    }

    public function statusFor(Character $character): array
    {
        $item = $this->definition();
        $remaining = max(0, (int) $character->experience_talisman_wins_remaining);
        $pausedAtLevelCap = $remaining > 0 && (int) $character->level >= self::MAX_PLAYER_LEVEL;

        return [
            'item_key' => self::ITEM_KEY,
            'name' => (string) ($item['name'] ?? '経験の護符'),
            'icon_image' => $item['icon_image'] ?? null,
            'active' => $remaining > 0,
            'paused_at_level_cap' => $pausedAtLevelCap,
            'remaining' => $remaining,
            'bonus_percent' => $this->expBonusPercent(),
            'eligible_victories_per_item' => $this->eligibleVictoriesPerItem(),
        ];
    }

    private function expBonusPercent(): int
    {
        return max(0, (int) ($this->definition()['effect_value'] ?? self::DEFAULT_EXP_BONUS_PERCENT));
    }

    private function eligibleVictoriesPerItem(): int
    {
        return max(1, (int) ($this->definition()['eligible_victories'] ?? self::DEFAULT_ELIGIBLE_VICTORIES));
    }

    private function notAppliedResult(int $rewardExp, int $remaining): array
    {
        return [
            'applied' => false,
            'base_exp' => $rewardExp,
            'bonus_exp' => 0,
            'total_exp' => $rewardExp,
            'remaining' => max(0, $remaining),
            'bonus_percent' => $this->expBonusPercent(),
        ];
    }

    private function definition(): array
    {
        return (array) config('adventure_support.inventory_items.'.self::ITEM_KEY, []);
    }
}
