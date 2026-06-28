<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\CharacterShopLimit;
use App\Models\Item;
use App\Models\KisekiTransaction;
use App\Models\ShopPurchaseLog;
use Illuminate\Support\Facades\DB;

class AdventureSupportService
{
    public const RESCUE_INSURANCE = 'rescue_insurance';
    public const EMERGENCY_RESCUE_REQUEST = 'emergency_rescue_request';

    public function catalogFor(Character $character): array
    {
        $items = config('adventure_support.items', []);
        $character->refresh();

        return collect($items)
            ->map(function (array $item, string $key) use ($character) {
                $state = $this->availability($character, $key, $item);

                return [
                    'key' => $key,
                    ...$item,
                    ...$state,
                ];
            })
            ->groupBy('category')
            ->all();
    }

    public function countsFor(Character $character): array
    {
        return [
            self::RESCUE_INSURANCE => $this->consumableQuantity($character, self::RESCUE_INSURANCE),
            self::EMERGENCY_RESCUE_REQUEST => $this->consumableQuantity($character, self::EMERGENCY_RESCUE_REQUEST),
        ];
    }

    public function purchase(Character $character, string $itemKey): array
    {
        $items = config('adventure_support.items', []);
        if (!isset($items[$itemKey])) {
            return ['success' => false, 'message' => '無効な商品です。'];
        }

        $item = $items[$itemKey];

        return DB::transaction(function () use ($character, $itemKey, $item) {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $availability = $this->availability($lockedCharacter, $itemKey, $item, true);
            if (!$availability['can_purchase']) {
                return ['success' => false, 'message' => $availability['disabled_reason']];
            }

            $spent = $this->spendKiseki($lockedCharacter, (int) $item['price']);
            $message = $this->applyPurchaseEffect($lockedCharacter, $itemKey, $item);

            ShopPurchaseLog::create([
                'character_id' => $lockedCharacter->id,
                'shop_item_key' => $itemKey,
                'item_name' => $item['name'],
                'quantity' => 1,
                'total_kiseki_cost' => (int) $item['price'],
                'free_kiseki_spent' => $spent['free_spent'],
                'paid_kiseki_spent' => $spent['paid_spent'],
            ]);

            KisekiTransaction::create([
                'character_id' => $lockedCharacter->id,
                'kiseki_type' => $spent['paid_spent'] > 0 && $spent['free_spent'] > 0
                    ? 'mixed'
                    : ($spent['free_spent'] > 0 ? 'free' : 'paid'),
                'amount' => -1 * (int) $item['price'],
                'transaction_type' => 'shop_purchase',
                'source_type' => 'adventure_support',
                'description' => "{$item['name']}購入（{$itemKey}）",
            ]);

            return ['success' => true, 'message' => $message];
        });
    }

    public function useRescueInsurance(Character $character): array
    {
        return DB::transaction(function () use ($character) {
            $state = CharacterExplorationState::firstOrCreate(
                ['character_id' => $character->id],
                ['started_at' => null]
            );
            $state->refresh();

            if ($state->area_id && $state->started_at) {
                return ['success' => false, 'message' => '探索中は救助保険証を使用できません。街で準備してから探索してください。'];
            }

            if ((bool) ($state->rescue_insurance_enabled ?? false)) {
                return ['success' => false, 'message' => '救助保険証はすでに次の探索に適用されています。'];
            }

            $row = CharacterConsumableItem::where('character_id', $character->id)
                ->where('item_key', self::RESCUE_INSURANCE)
                ->lockForUpdate()
                ->first();

            if (!$row || (int) $row->quantity <= 0) {
                return ['success' => false, 'message' => '救助保険証を所持していません。'];
            }

            $row->decrement('quantity');
            $state->forceFill(['rescue_insurance_enabled' => true])->save();

            return [
                'success' => true,
                'message' => '救助保険証を使用しました。この探索で全滅した場合、入手品ロストが25%に抑えられます。',
            ];
        });
    }

    public function consumeEmergencyRescueIfAvailable(Character $character): bool
    {
        return DB::transaction(function () use ($character) {
            if ($this->usedToday($character, self::EMERGENCY_RESCUE_REQUEST)) {
                return false;
            }

            $row = CharacterConsumableItem::where('character_id', $character->id)
                ->where('item_key', self::EMERGENCY_RESCUE_REQUEST)
                ->lockForUpdate()
                ->first();

            if (!$row || (int) $row->quantity <= 0) {
                return false;
            }

            $row->decrement('quantity');
            $this->incrementLimit($character, self::EMERGENCY_RESCUE_REQUEST, 'used_count', today('Asia/Tokyo')->toDateString());

            return true;
        });
    }

    public function insuranceEnabled(Character $character): bool
    {
        $state = CharacterExplorationState::where('character_id', $character->id)->first();

        return (bool) ($state?->rescue_insurance_enabled ?? false);
    }

    private function applyPurchaseEffect(Character $character, string $itemKey, array $item): string
    {
        return match ($itemKey) {
            'material_storage_expand' => $this->expandStorage($character, 'material_storage_limit', $itemKey, $item),
            'equipment_storage_expand' => $this->expandStorage($character, 'equipment_storage_limit', $itemKey, $item),
            'adventurer_supply_box' => $this->grantSupplyBox($character),
            self::RESCUE_INSURANCE,
            self::EMERGENCY_RESCUE_REQUEST => $this->grantConsumable($character, $itemKey, $item['name']),
            default => "{$item['name']}を購入しました。",
        };
    }

    private function expandStorage(Character $character, string $column, string $itemKey, array $item): string
    {
        $character->{$column} = (int) ($character->{$column} ?? 200) + (int) $item['effect_value'];
        $character->kiseki = (int) ($character->paid_kiseki ?? 0) + (int) ($character->free_kiseki ?? 0);
        $character->save();
        $this->incrementLimit($character, $itemKey, 'purchased_count');

        return $column === 'material_storage_limit'
            ? '素材倉庫を拡張しました。素材倉庫の保管枠が+50されました。'
            : '装備倉庫を拡張しました。装備倉庫の保管枠が+50されました。';
    }

    private function grantSupplyBox(Character $character): string
    {
        foreach (['薬草', '回復薬', '魔力水'] as $name) {
            $item = Item::where('type', 'consumable')->where('name', $name)->first();
            if (!$item) {
                continue;
            }

            for ($i = 0; $i < 10; $i++) {
                CharacterItem::create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'is_equipped' => false,
                    'is_stored' => false,
                    'acquired_from' => 'adventure_support_box',
                ]);
            }
        }

        $this->incrementLimit($character, 'adventurer_supply_box', 'purchased_count', today('Asia/Tokyo')->toDateString());

        return '冒険者補給箱を受け取りました。薬草・回復薬・魔力水が各10個補充されました。';
    }

    private function grantConsumable(Character $character, string $itemKey, string $name): string
    {
        CharacterConsumableItem::updateOrCreate(
            ['character_id' => $character->id, 'item_key' => $itemKey],
            ['updated_at' => now()]
        )->increment('quantity');

        return "{$name}を購入しました。所持数が+1されました。";
    }

    private function spendKiseki(Character $character, int $amount): array
    {
        $free = (int) ($character->free_kiseki ?? 0);
        $paid = (int) ($character->paid_kiseki ?? 0);
        if (($free + $paid) < $amount) {
            throw new \RuntimeException('輝石が不足しています。');
        }

        $freeSpent = min($free, $amount);
        $paidSpent = $amount - $freeSpent;

        $character->free_kiseki = $free - $freeSpent;
        $character->paid_kiseki = $paid - $paidSpent;
        $character->kiseki = (int) $character->free_kiseki + (int) $character->paid_kiseki;
        $character->save();

        return ['free_spent' => $freeSpent, 'paid_spent' => $paidSpent];
    }

    private function availability(Character $character, string $key, array $item, bool $locked = false): array
    {
        $total = (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0);
        $disabledReason = null;

        if ((bool) ($item['sale_suspended'] ?? false)) {
            $disabledReason = "{$item['name']}は現在販売休止中です。";
        } elseif ($total < (int) $item['price']) {
            $disabledReason = '輝石が不足しています。輝石を購入してから再度お試しください。';
        } elseif (($item['purchase_limit'] ?? null) && $this->purchasedCount($character, $key, null, $locked) >= (int) $item['purchase_limit']) {
            $disabledReason = "{$item['name']}はこれ以上購入できません。";
        } elseif (($item['daily_purchase_limit'] ?? null) && $this->purchasedCount($character, $key, today('Asia/Tokyo')->toDateString(), $locked) >= (int) $item['daily_purchase_limit']) {
            $disabledReason = "{$item['name']}は本日すでに購入済みです。";
        } elseif ($key === 'adventurer_supply_box' && $this->isExploring($character)) {
            $disabledReason = '探索中は冒険者補給箱を購入できません。街に戻ってから購入してください。';
        }

        return [
            'can_purchase' => $disabledReason === null,
            'disabled_reason' => $disabledReason,
            'purchased_count' => $this->purchasedCount($character, $key, null, $locked),
            'daily_purchased_count' => $this->purchasedCount($character, $key, today('Asia/Tokyo')->toDateString(), $locked),
            'used_today' => $this->usedToday($character, $key, $locked),
        ];
    }

    private function isExploring(Character $character): bool
    {
        $state = CharacterExplorationState::where('character_id', $character->id)->first();

        return (bool) ($state && $state->area_id && $state->started_at);
    }

    private function consumableQuantity(Character $character, string $key): int
    {
        return (int) (CharacterConsumableItem::where('character_id', $character->id)
            ->where('item_key', $key)
            ->value('quantity') ?? 0);
    }

    private function purchasedCount(Character $character, string $key, ?string $date, bool $locked = false): int
    {
        $query = CharacterShopLimit::where('character_id', $character->id)
            ->where('shop_item_key', $key);

        $date === null ? $query->whereNull('limit_date') : $query->whereDate('limit_date', $date);
        if ($locked) {
            $query->lockForUpdate();
        }

        return (int) ($query->first()?->purchased_count ?? 0);
    }

    private function usedToday(Character $character, string $key, bool $locked = false): bool
    {
        $query = CharacterShopLimit::where('character_id', $character->id)
            ->where('shop_item_key', $key)
            ->whereDate('limit_date', today('Asia/Tokyo')->toDateString());

        if ($locked) {
            $query->lockForUpdate();
        }

        return (int) ($query->first()?->used_count ?? 0) > 0;
    }

    private function incrementLimit(Character $character, string $key, string $column, ?string $date = null): void
    {
        if ($key === '') {
            return;
        }

        $limit = CharacterShopLimit::firstOrCreate([
            'character_id' => $character->id,
            'shop_item_key' => $key,
            'limit_date' => $date,
        ]);
        $limit->increment($column);
    }
}
