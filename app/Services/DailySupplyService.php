<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterItemDailySupply;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

class DailySupplyService
{
    private const DAILY_TARGET = 10;

    public function supplyItems(): array
    {
        return [
            '薬草' => ['effect' => 'HPを30%回復', 'sort' => 10],
            '回復薬' => ['effect' => 'HPを60%回復', 'sort' => 20],
            '魔力水' => ['effect' => 'SPを30%回復', 'sort' => 30],
        ];
    }

    public function targetCount(): int
    {
        return self::DAILY_TARGET;
    }

    public function statusFor(Character $character): array
    {
        $today = now()->toDateString();

        return collect($this->supplyItems())
            ->map(function (array $config, string $name) use ($character, $today) {
                $item = Item::where('type', 'consumable')->where('name', $name)->first();
                $ownedCount = $item ? $this->ownedCount($character, $item) : 0;
                $todaySupply = $item
                    ? CharacterItemDailySupply::where('character_id', $character->id)
                        ->where('item_id', $item->id)
                        ->whereDate('claimed_on', $today)
                        ->first()
                    : null;
                $stockedCount = $item ? $this->stockedCount($character, $item) : 0;
                $dailyRemaining = $item ? $this->dailyRemaining($todaySupply) : 0;
                $spaceCount = max(0, self::DAILY_TARGET - $ownedCount);
                $claimableCount = min($spaceCount, $stockedCount + $dailyRemaining);
                $depotCount = $stockedCount + $dailyRemaining;

                $canClaim = $item && ($claimableCount > 0 || $dailyRemaining > 0);

                return [
                    'item' => $item,
                    'name' => $name,
                    'effect' => $config['effect'],
                    'owned_count' => $ownedCount,
                    'target_count' => self::DAILY_TARGET,
                    'claimable_count' => $claimableCount,
                    'stocked_count' => $stockedCount,
                    'depot_count' => $depotCount,
                    'daily_remaining' => $dailyRemaining,
                    'claimed_today' => (bool) $todaySupply && $dailyRemaining <= 0,
                    'supplied_count' => (int) ($todaySupply?->supplied_count ?? 0),
                    'stocked_today' => (int) ($todaySupply?->stocked_count ?? 0),
                    'can_claim' => $canClaim,
                    'sort' => $config['sort'],
                ];
            })
            ->sortBy('sort')
            ->values()
            ->all();
    }

    public function claim(Character $character, Item $item): array
    {
        if (!$this->isSupplyItem($item)) {
            return ['success' => false, 'message' => 'このアイテムは配布対象ではありません。'];
        }

        return DB::transaction(function () use ($character, $item) {
            $today = now()->toDateString();
            $todaySupply = CharacterItemDailySupply::where('character_id', $character->id)
                ->where('item_id', $item->id)
                ->whereDate('claimed_on', $today)
                ->lockForUpdate()
                ->first();

            if (!$todaySupply) {
                $todaySupply = CharacterItemDailySupply::create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'claimed_on' => $today,
                    'supplied_count' => 0,
                    'stocked_count' => 0,
                ]);
            }

            $ownedCount = $this->ownedCount($character, $item);
            $spaceCount = max(0, self::DAILY_TARGET - $ownedCount);
            $dailyRemaining = $this->dailyRemaining($todaySupply);
            $stockedBefore = $this->stockedCount($character, $item);

            if ($spaceCount <= 0) {
                if ($dailyRemaining > 0) {
                    $todaySupply->increment('stocked_count', $dailyRemaining);

                    return [
                        'success' => true,
                        'message' => "{$item->name}はすでに10個あります。本日の{$dailyRemaining}個は補給所にストックしました。",
                    ];
                }

                return [
                    'success' => false,
                    'message' => $stockedBefore > 0
                        ? "{$item->name}は所持上限です。補給所ストック{$stockedBefore}個は、所持数が減ってから受け取れます。"
                        : "{$item->name}は本日すでに受け取り済みです。",
                ];
            }

            $fromDaily = min($spaceCount, $dailyRemaining);
            if ($fromDaily > 0) {
                $todaySupply->increment('supplied_count', $fromDaily);
                $spaceCount -= $fromDaily;
            }

            $dailyToStock = max(0, $dailyRemaining - $fromDaily);
            if ($dailyToStock > 0) {
                $todaySupply->increment('stocked_count', $dailyToStock);
            }

            $fromStock = min($spaceCount, $stockedBefore);
            if ($fromStock > 0) {
                $this->consumeStock($character, $item, $fromStock);
                $spaceCount -= $fromStock;
            }

            $supplyCount = $fromStock + $fromDaily;
            for ($i = 0; $i < $supplyCount; $i++) {
                CharacterItem::create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'is_equipped' => false,
                    'is_stored' => false,
                    'acquired_from' => 'daily_supply',
                ]);
            }

            if ($supplyCount <= 0 && $dailyToStock <= 0) {
                return ['success' => false, 'message' => "{$item->name}は本日すでに受け取り済みです。"];
            }

            $parts = [];
            if ($supplyCount > 0) {
                $parts[] = "{$item->name}を{$supplyCount}個受け取りました";
            }
            if ($dailyToStock > 0) {
                $parts[] = "本日の残り{$dailyToStock}個を補給所にストックしました";
            }

            return ['success' => true, 'message' => implode('。', $parts) . '。'];
        });
    }

    public function claimAll(Character $character): array
    {
        $messages = [];
        $success = false;

        foreach ($this->statusFor($character) as $entry) {
            if (!$entry['item'] || !$entry['can_claim']) {
                continue;
            }

            $result = $this->claim($character, $entry['item']);
            if ($result['success'] ?? false) {
                $success = true;
                $messages[] = $result['message'];
            }
        }

        if (!$success) {
            return ['success' => false, 'message' => '本日受け取れる回復アイテムはありません。'];
        }

        return ['success' => true, 'message' => implode("\n", $messages)];
    }

    private function isSupplyItem(Item $item): bool
    {
        return $item->type === 'consumable' && array_key_exists($item->name, $this->supplyItems());
    }

    private function ownedCount(Character $character, Item $item): int
    {
        return CharacterItem::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->where('is_equipped', false)
            ->count();
    }

    private function stockedCount(Character $character, Item $item): int
    {
        return (int) CharacterItemDailySupply::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->sum('stocked_count');
    }

    private function dailyRemaining(?CharacterItemDailySupply $supply): int
    {
        if (!$supply) {
            return self::DAILY_TARGET;
        }

        return max(0, self::DAILY_TARGET - (int) $supply->supplied_count - (int) $supply->stocked_count);
    }

    private function consumeStock(Character $character, Item $item, int $count): void
    {
        $remaining = $count;
        $stockRows = CharacterItemDailySupply::where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->where('stocked_count', '>', 0)
            ->orderBy('claimed_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($stockRows as $stockRow) {
            if ($remaining <= 0) {
                break;
            }

            $used = min($remaining, (int) $stockRow->stocked_count);
            $stockRow->decrement('stocked_count', $used);
            $stockRow->increment('supplied_count', $used);
            $remaining -= $used;
        }
    }
}
