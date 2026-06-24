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
                $claimed = $item
                    ? CharacterItemDailySupply::where('character_id', $character->id)
                        ->where('item_id', $item->id)
                        ->whereDate('claimed_on', $today)
                        ->first()
                    : null;

                $canClaim = $item && !$claimed && $ownedCount < self::DAILY_TARGET;

                return [
                    'item' => $item,
                    'name' => $name,
                    'effect' => $config['effect'],
                    'owned_count' => $ownedCount,
                    'target_count' => self::DAILY_TARGET,
                    'claimable_count' => $canClaim ? self::DAILY_TARGET - $ownedCount : 0,
                    'claimed_today' => (bool) $claimed,
                    'supplied_count' => (int) ($claimed?->supplied_count ?? 0),
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
            $claimed = CharacterItemDailySupply::where('character_id', $character->id)
                ->where('item_id', $item->id)
                ->whereDate('claimed_on', $today)
                ->lockForUpdate()
                ->first();

            if ($claimed) {
                return ['success' => false, 'message' => "{$item->name}は本日すでに受け取り済みです。"];
            }

            $ownedCount = $this->ownedCount($character, $item);
            $supplyCount = max(0, self::DAILY_TARGET - $ownedCount);

            CharacterItemDailySupply::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'claimed_on' => $today,
                'supplied_count' => $supplyCount,
            ]);

            for ($i = 0; $i < $supplyCount; $i++) {
                CharacterItem::create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'is_equipped' => false,
                    'is_stored' => false,
                    'acquired_from' => 'daily_supply',
                ]);
            }

            if ($supplyCount <= 0) {
                return ['success' => true, 'message' => "{$item->name}はすでに10個以上あります。本日の無料補充は消費されました。"];
            }

            return ['success' => true, 'message' => "{$item->name}を{$supplyCount}個受け取り、所持数が10個になりました。"];
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
}
