<?php

namespace App\Services;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterExplorationSupportPref;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerExplorationSupportEffect;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExplorationSupportService
{
    public const BATTLES_PER_ITEM = 30;
    public const CONTENT_KEY = 'exploration_support';

    public const ITEMS = [
        'support_apothecary_charm' => ['name' => '薬屋のお守り', 'description' => '5戦ごとに戦闘後、最大HPの10%を回復する。'],
        'support_guard_incense' => ['name' => '守りの香', 'description' => '敵から受ける直接ダメージを8%軽減する。'],
        'support_first_aid_kit' => ['name' => '冒険者の救急包', 'description' => '火傷・毒・出血を短縮し、継続ダメージを半減する。'],
        'support_special_herbal' => ['name' => '薬屋の特製漢方', 'description' => '瀕死時に最大HPの20%を回復する。1個につき3回まで。'],
    ];

    public function activeFor(Character $character): ?PlayerExplorationSupportEffect
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return PlayerExplorationSupportEffect::query()->with('item')
            ->where('character_id', $character->id)
            ->where('battles_remaining', '>', 0)
            ->first();
    }

    /** 戦闘開始直前の効果を固定する。 */
    public function beginBattle(Character $character): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return DB::transaction(function () use ($character): ?array {
            $effect = PlayerExplorationSupportEffect::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            if (!$effect) {
                return null;
            }

            if ((int) $effect->battles_remaining <= 0) {
                if (!$effect->auto_renew || !$this->consumeOwnedItem($character, (int) $effect->item_id)) {
                    $effect->forceFill(['battles_remaining' => 0])->save();
                    return null;
                }
                $effect->forceFill([
                    'battles_remaining' => self::BATTLES_PER_ITEM,
                    'battles_elapsed_in_period' => 0,
                    'proc_count' => 0,
                    'lock_version' => (int) $effect->lock_version + 1,
                ])->save();
            }

            $key = array_search((int) $effect->item_id, $this->itemIdsByKey(), true);
            if (!$key) {
                return null;
            }

            return [
                'item_key' => $key,
                'item_id' => (int) $effect->item_id,
                'battles_remaining' => (int) $effect->battles_remaining,
                'battles_elapsed_in_period' => (int) $effect->battles_elapsed_in_period,
                'proc_count' => (int) $effect->proc_count,
            ];
        });
    }

    /** battle_logs.id を冪等キーにして、終了後の戦数とお守り回復を確定する。 */
    public function completeBattle(Character $character, BattleLog $battleLog, ?array $snapshot): array
    {
        if (!$this->isEnabled() || !$snapshot) {
            return ['active' => null, 'logs' => []];
        }

        return DB::transaction(function () use ($character, $battleLog, $snapshot): array {
            $effect = PlayerExplorationSupportEffect::query()
                ->where('character_id', $character->id)
                ->lockForUpdate()
                ->first();
            if (!$effect || (int) $effect->item_id !== (int) $snapshot['item_id']) {
                return ['active' => $this->payload($character), 'logs' => []];
            }
            if ((int) ($effect->last_battle_log_id ?? 0) === (int) $battleLog->id) {
                return ['active' => $this->payload($character), 'logs' => []];
            }

            $elapsed = min(self::BATTLES_PER_ITEM, (int) $effect->battles_elapsed_in_period + 1);
            $remaining = max(0, (int) $effect->battles_remaining - 1);
            $logs = [];
            if (($snapshot['item_key'] ?? '') === 'support_apothecary_charm'
                && $battleLog->result !== 'lose'
                && $elapsed % 5 === 0) {
                $stats = app(CharacterStatusService::class)->getFinalStats($character);
                $heal = max(1, (int) floor((int) $stats['max_hp'] * 0.10));
                $character->current_hp = min((int) $stats['max_hp'], (int) $character->current_hp + $heal);
                $character->save();
                $logs[] = "<span class=\"text-emerald-700 font-bold\">【薬屋のお守り】旅の節目に傷が{$heal}回復した！</span>";
            }

            $effect->forceFill([
                'battles_remaining' => $remaining,
                'battles_elapsed_in_period' => $elapsed,
                'last_battle_log_id' => $battleLog->id,
                'lock_version' => (int) $effect->lock_version + 1,
            ])->save();

            return ['active' => $this->payload($character), 'logs' => $logs];
        });
    }

    /**
     * $autoRenew を省略した場合、その品目に保存済みの自動補充プリファレンス（既定はOFF）を使う。
     */
    public function activate(Character $character, string $itemKey, ?bool $autoRenew = null): array
    {
        $this->ensureEnabled();

        if (!isset(self::ITEMS[$itemKey])) {
            throw new RuntimeException('選択できない探索補助品です。');
        }

        return DB::transaction(function () use ($character, $itemKey, $autoRenew): array {
            $itemId = $this->itemIdsByKey()[$itemKey] ?? null;
            if (!$itemId || !$this->consumeOwnedItem($character, $itemId)) {
                throw new RuntimeException('その探索補助品を所持していません。');
            }
            $resolvedAutoRenew = $autoRenew ?? $this->autoRenewPreference($character, $itemId);
            $effect = PlayerExplorationSupportEffect::query()->where('character_id', $character->id)->lockForUpdate()->first();
            PlayerExplorationSupportEffect::updateOrCreate(
                ['character_id' => $character->id],
                [
                    'item_id' => $itemId,
                    'battles_remaining' => self::BATTLES_PER_ITEM,
                    'battles_elapsed_in_period' => 0,
                    'proc_count' => 0,
                    'auto_renew' => $resolvedAutoRenew,
                    'last_battle_log_id' => null,
                    'lock_version' => (int) ($effect?->lock_version ?? 0) + 1,
                ]
            );
            return $this->payload($character);
        });
    }

    /** 品目ごとの自動補充プリファレンスを保存し、それが現在有効中の効果なら即座に反映する。 */
    public function setAutoRenewPreference(Character $character, string $itemKey, bool $autoRenew): void
    {
        $this->ensureEnabled();

        $itemId = $this->itemIdsByKey()[$itemKey] ?? null;
        if (!$itemId) {
            throw new RuntimeException('選択できない探索補助品です。');
        }

        CharacterExplorationSupportPref::updateOrCreate(
            ['character_id' => $character->id, 'item_id' => $itemId],
            ['auto_renew' => $autoRenew]
        );

        PlayerExplorationSupportEffect::where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->update(['auto_renew' => $autoRenew]);
    }

    private function autoRenewPreference(Character $character, int $itemId): bool
    {
        return (bool) (CharacterExplorationSupportPref::query()
            ->where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->value('auto_renew') ?? false);
    }

    public function clear(Character $character): void
    {
        $this->ensureEnabled();

        PlayerExplorationSupportEffect::where('character_id', $character->id)->delete();
    }

    public function reduceDirectDamage(int $damage, ?array $snapshot): int
    {
        if (($snapshot['item_key'] ?? null) !== 'support_guard_incense') {
            return $damage;
        }
        return $damage === 0 ? 0 : max(1, (int) floor($damage * 0.92));
    }

    public function adjustedConditionDuration(int $duration, ?array $snapshot): int
    {
        return ($snapshot['item_key'] ?? null) === 'support_first_aid_kit'
            ? max(1, $duration - 1)
            : max(1, $duration);
    }

    public function adjustedDotDamage(int $damage, ?array $snapshot): int
    {
        if (($snapshot['item_key'] ?? null) !== 'support_first_aid_kit') {
            return $damage;
        }
        return $damage === 0 ? 0 : max(1, (int) floor($damage * 0.50));
    }

    /** 生存した瀕死時だけ漢方を発動する。 */
    public function trySpecialHerbal(Character $character, int &$hp, int $maxHp, ?array &$snapshot): ?int
    {
        if (!is_array($snapshot)
            || ($snapshot['item_key'] ?? null) !== 'support_special_herbal'
            || $hp <= 0 || $maxHp <= 0 || $hp * 100 > $maxHp * 30
            || (int) ($snapshot['proc_count'] ?? 0) >= 3
            || !empty($snapshot['proc_used_this_battle'])) {
            return null;
        }
        $heal = max(1, (int) floor($maxHp * 0.20));
        $hp = min($maxHp, $hp + $heal);
        $snapshot['proc_count'] = (int) $snapshot['proc_count'] + 1;
        $snapshot['proc_used_this_battle'] = true;
        return $heal;
    }

    public function persistBattleProcs(Character $character, ?array $snapshot): void
    {
        if (!$snapshot || ($snapshot['item_key'] ?? '') !== 'support_special_herbal') {
            return;
        }
        PlayerExplorationSupportEffect::where('character_id', $character->id)
            ->where('item_id', (int) $snapshot['item_id'])
            ->update(['proc_count' => min(3, (int) $snapshot['proc_count'])]);
    }

    public function payload(Character $character): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $effect = $this->activeFor($character);
        if (!$effect) return null;
        $key = array_search((int) $effect->item_id, $this->itemIdsByKey(), true);
        if (!$key) return null;
        return [
            'item_key' => $key,
            'name' => self::ITEMS[$key]['name'],
            'description' => self::ITEMS[$key]['description'],
            'remaining' => (int) $effect->battles_remaining,
            'elapsed' => (int) $effect->battles_elapsed_in_period,
            'proc_count' => (int) $effect->proc_count,
            'procs_remaining' => $key === 'support_special_herbal' ? max(0, 3 - (int) $effect->proc_count) : null,
            'auto_renew' => (bool) $effect->auto_renew,
        ];
    }

    /** 装備変更画面の「もちもの」タブや探索画面のもちものモーダル用に、所持品・使用状況・自動補充設定を返す。 */
    public function belongingsFor(Character $character): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $itemIds = $this->itemIdsByKey();
        $ownedCounts = CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('is_equipped', false)
            ->whereIn('item_id', array_values($itemIds))
            ->selectRaw('item_id, count(*) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');
        $prefs = CharacterExplorationSupportPref::query()
            ->where('character_id', $character->id)
            ->whereIn('item_id', array_values($itemIds))
            ->pluck('auto_renew', 'item_id');

        $active = $this->payload($character);

        return collect(self::ITEMS)
            ->map(function (array $definition, string $key) use ($itemIds, $ownedCounts, $prefs, $active): array {
                $itemId = $itemIds[$key] ?? 0;
                $owned = (int) ($ownedCounts[$itemId] ?? 0);
                $isActive = $active !== null && $active['item_key'] === $key;
                return [
                    'item_key' => $key,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'owned' => $owned,
                    'is_active' => $isActive,
                    'auto_renew' => $isActive ? (bool) $active['auto_renew'] : (bool) ($prefs[$itemId] ?? false),
                ];
            })
            ->filter(fn (array $row): bool => $row['owned'] > 0 || $row['is_active'])
            ->values()
            ->all();
    }

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive(self::CONTENT_KEY);
    }

    private function ensureEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('探索補助品は現在公開していません。');
        }
    }

    private function itemIdsByKey(): array
    {
        $names = array_column(self::ITEMS, 'name');
        $items = Item::query()->where('type', 'consumable')->whereIn('name', $names)->pluck('id', 'name');
        $result = [];
        foreach (self::ITEMS as $key => $definition) $result[$key] = (int) ($items[$definition['name']] ?? 0);
        return array_filter($result);
    }

    private function consumeOwnedItem(Character $character, int $itemId): bool
    {
        $owned = CharacterItem::query()->where('character_id', $character->id)->where('item_id', $itemId)
            ->where('is_equipped', false)->lockForUpdate()->first();
        if (!$owned) return false;
        $owned->delete();
        return true;
    }
}
