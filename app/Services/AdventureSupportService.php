<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterExplorationState;
use App\Models\CharacterItem;
use App\Models\CharacterShopLimit;
use App\Models\City;
use App\Models\Item;
use App\Models\KisekiTransaction;
use App\Models\ShopPurchaseLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdventureSupportService
{
    public const RESCUE_INSURANCE = 'rescue_insurance';
    public const EMERGENCY_RESCUE_REQUEST = 'emergency_rescue_request';

    public function catalogFor(Character $character): array
    {
        $controlService = app(AdventureSupportItemControlService::class);
        $items = collect(config('adventure_support.items', []))
            ->reject(fn (array $item, string $key) => !$controlService->isVisible($key, $item))
            ->reject(fn (array $item) => $this->requiresSupportPass($item)
                && !app(SupportPassService::class)->enabled())
            ->map(fn (array $item, string $key) => $controlService->effectiveItem($key, $item))
            ->all();
        $character->refresh();

        return collect($items)
            ->map(function (array $item, string $key) use ($character) {
                $state = $this->availability($character, $key, $item);

                return [
                    'key' => $key,
                    ...$item,
                    ...$this->currencyMeta($item),
                    ...$state,
                    'purchase_label' => $this->purchaseLabel($character, $key, $item, $state),
                ];
            })
            ->groupBy('category')
            ->all();
    }

    public function countsFor(Character $character): array
    {
        return $this->supportConsumableKeys()
            ->mapWithKeys(fn (string $key) => [$key => $this->consumableQuantity($character, $key)])
            ->all();
    }

    public function departureSetHomeBannerFor(Character $character): ?array
    {
        $itemKey = 'adventurer_departure_set';
        $item = config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            return null;
        }

        $controlService = app(AdventureSupportItemControlService::class);
        if (!$controlService->isVisible($itemKey, $item)
            || !$controlService->isEnabled($itemKey, $item)
            || ($this->requiresSupportPass($item) && !app(SupportPassService::class)->enabled())
            || !$this->departureSetAssetsReady($item)
            || $this->purchasedCount($character, $itemKey, null) >= (int) ($item['purchase_limit'] ?? 1)) {
            return null;
        }

        $bannerUntilCity = City::find((int) ($item['home_banner_until_city_id'] ?? 0));
        $highestCity = $character->highestCity ?? $character->currentCity;
        if (!$bannerUntilCity || !$highestCity || (int) $highestCity->sort_order >= (int) $bannerUntilCity->sort_order) {
            return null;
        }

        return [
            'name' => (string) ($item['name'] ?? '冒険者旅立ちセット'),
            'price' => (int) ($item['price'] ?? 0),
            'icon_image' => $item['icon_image'] ?? null,
        ];
    }

    public function ownedConsumablesFor(Character $character): array
    {
        $items = $this->supportItemDefinitions();

        $itemOrder = array_flip(array_keys($items));

        return CharacterConsumableItem::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->whereIn('item_key', $this->supportConsumableKeys()->all())
            ->get()
            ->map(function (CharacterConsumableItem $row) use ($items) {
                $item = $items[$row->item_key] ?? null;
                if (!$item) {
                    return null;
                }

                return [
                    'key' => $row->item_key,
                    'name' => (string) ($item['name'] ?? $row->item_key),
                    'category' => (string) ($item['category'] ?? '支援アイテム'),
                    'description' => (string) ($item['description'] ?? ''),
                    'icon_image' => $item['icon_image'] ?? null,
                    'effect_type' => $item['effect_type'] ?? null,
                    'effect_value' => (int) ($item['effect_value'] ?? 0),
                    'quantity' => (int) $row->quantity,
                    'can_use' => $this->canUseFromInventory($row->item_key, $item),
                    'use_label' => $this->useLabel($row->item_key, $item),
                    'use_note' => $this->useNote($row->item_key, $item),
                ];
            })
            ->filter()
            ->sortBy(fn (array $entry) => $itemOrder[$entry['key']] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    public function purchase(Character $character, string $itemKey): array
    {
        $items = config('adventure_support.items', []);
        if (!isset($items[$itemKey])) {
            return ['success' => false, 'message' => '無効な商品です。'];
        }

        $item = app(AdventureSupportItemControlService::class)->effectiveItem($itemKey, $items[$itemKey]);

        return DB::transaction(function () use ($character, $itemKey, $item) {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $availability = $this->availability($lockedCharacter, $itemKey, $item, true);
            if (!$availability['can_purchase']) {
                return ['success' => false, 'message' => $availability['disabled_reason']];
            }

            $currency = $this->currency($item);
            $spent = $currency === 'gold'
                ? $this->spendGold($lockedCharacter, (int) $item['price'], $itemKey, (string) $item['name'])
                : $this->spendKiseki($lockedCharacter, (int) $item['price']);
            $message = $this->applyPurchaseEffect($lockedCharacter, $itemKey, $item);

            ShopPurchaseLog::create([
                'character_id' => $lockedCharacter->id,
                'shop_item_key' => $itemKey,
                'item_name' => $item['name'],
                'quantity' => 1,
                'total_kiseki_cost' => $currency === 'kiseki' ? (int) $item['price'] : 0,
                'free_kiseki_spent' => $spent['free_spent'] ?? 0,
                'paid_kiseki_spent' => $spent['paid_spent'] ?? 0,
            ]);

            if ($currency === 'kiseki') {
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
            }

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

    public function useConsumable(Character $character, string $itemKey): array
    {
        $items = $this->supportItemDefinitions();
        if (!isset($items[$itemKey]) || !$this->supportConsumableKeys()->contains($itemKey)) {
            return ['success' => false, 'message' => '使用できないアイテムです。'];
        }

        if ($itemKey === self::RESCUE_INSURANCE) {
            return $this->useRescueInsurance($character);
        }

        if (($items[$itemKey]['effect_type'] ?? null) === 'support_pass_activation') {
            return $this->useSupportPassTicket($character, $itemKey, $items[$itemKey]);
        }

        if (($items[$itemKey]['effect_type'] ?? null) !== 'explore_stamina_recovery') {
            return ['success' => false, 'message' => "{$items[$itemKey]['name']}はここでは使用できません。"];
        }

        return $this->useExploreStaminaRecoveryItem($character, $itemKey, $items[$itemKey]);
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
            'adventurer_departure_set' => $this->grantAdventurerDepartureSet($character, $itemKey, $item),
            'material_storage_expand' => $this->expandStorage($character, 'material_storage_limit', $itemKey, $item),
            'material_storage_gold_expand' => $this->expandStorage($character, 'material_storage_limit', $itemKey, $item),
            'equipment_storage_expand' => $this->expandStorage($character, 'equipment_storage_limit', $itemKey, $item),
            SupportPassService::PASS_TYPE => $this->grantSupportPassTicket($character),
            'adventurer_supply_box' => $this->grantSupplyBox($character),
            self::RESCUE_INSURANCE,
            self::EMERGENCY_RESCUE_REQUEST => $this->grantConsumable($character, $itemKey, $item['name']),
            default => ($item['effect_type'] ?? null) === 'explore_stamina_recovery'
                ? $this->grantConsumable($character, $itemKey, $item['name'], today('Asia/Tokyo')->toDateString())
                : "{$item['name']}を購入しました。",
        };
    }

    private function grantSupportPassTicket(Character $character): string
    {
        $this->grantConsumableQuantity($character, 'support_pass_30d_ticket', 1);

        return '冒険者支援パス30日利用券を購入しました。所持品から使用すると支援パスが有効になります。';
    }

    private function grantAdventurerDepartureSet(Character $character, string $itemKey, array $item): string
    {
        $storageCapacity = app(StorageCapacityService::class);
        $materialBefore = $storageCapacity->materialLimit($character);
        $equipmentBefore = $storageCapacity->equipmentLimit($character);

        foreach ((array) data_get($item, 'grants.consumables', []) as $consumableKey => $quantity) {
            $this->grantConsumableQuantity($character, (string) $consumableKey, (int) $quantity);
        }

        foreach ((array) data_get($item, 'grants.storage_expansions', []) as $storageItemKey => $count) {
            $this->grantStorageExpansion($character, (string) $storageItemKey, (int) $count);
        }

        $now = now();
        foreach ((array) data_get($item, 'grants.profile_assets', []) as $asset) {
            DB::table('character_adventurer_card_assets')->insertOrIgnore([
                'character_id' => $character->id,
                'asset_type' => (string) ($asset['asset_type'] ?? ''),
                'asset_path' => (string) ($asset['asset_path'] ?? ''),
                'source' => 'adventurer_departure_set',
                'obtained_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->incrementLimit($character, $itemKey, 'purchased_count');

        $materialAfter = $storageCapacity->materialLimit($character);
        $equipmentAfter = $storageCapacity->equipmentLimit($character);
        $materialIncrease = $materialAfter - $materialBefore;
        $equipmentIncrease = $equipmentAfter - $equipmentBefore;

        return implode("\n", [
            '冒険者旅立ちセットを受け取りました。',
            '冒険者支援パス30日利用券×1と探索力の薬×3を所持品へ追加しました',
            '素材倉庫 ' . number_format($materialBefore) . ' → ' . number_format($materialAfter) . '（+' . number_format($materialIncrease) . '）へ拡張しました。',
            '装備倉庫 ' . number_format($equipmentBefore) . ' → ' . number_format($equipmentAfter) . '（+' . number_format($equipmentIncrease) . '）へ拡張しました。',
            '限定カードフレームも付与しました。',
        ]);
    }

    private function grantConsumableQuantity(Character $character, string $itemKey, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        CharacterConsumableItem::updateOrCreate(
            ['character_id' => $character->id, 'item_key' => $itemKey],
            ['updated_at' => now()]
        )->increment('quantity', $quantity);
    }

    private function grantStorageExpansion(Character $character, string $storageItemKey, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $storageItem = config("adventure_support.items.{$storageItemKey}");
        if (!is_array($storageItem)) {
            throw new \RuntimeException('倉庫拡張の設定が見つかりません。');
        }

        $column = match ($storageItemKey) {
            'material_storage_expand' => 'material_storage_limit',
            'equipment_storage_expand' => 'equipment_storage_limit',
            default => throw new \RuntimeException('未対応の倉庫拡張です。'),
        };
        $defaultLimit = $column === 'material_storage_limit' ? 500 : 300;
        $currentLimit = max($defaultLimit, (int) ($character->{$column} ?? $defaultLimit));
        $character->{$column} = $currentLimit + ((int) ($storageItem['effect_value'] ?? 0) * $count);
        $character->kiseki = (int) ($character->paid_kiseki ?? 0) + (int) ($character->free_kiseki ?? 0);
        $character->save();
    }

    private function expandStorage(Character $character, string $column, string $itemKey, array $item): string
    {
        $storageCapacity = app(StorageCapacityService::class);
        $displayBefore = $column === 'material_storage_limit'
            ? $storageCapacity->materialLimit($character)
            : $storageCapacity->equipmentLimit($character);
        $defaultLimit = $column === 'material_storage_limit' ? 500 : 300;
        $currentLimit = max($defaultLimit, (int) ($character->{$column} ?? $defaultLimit));
        $newLimit = $currentLimit + (int) $item['effect_value'];
        $character->{$column} = $newLimit;
        $character->kiseki = (int) ($character->paid_kiseki ?? 0) + (int) ($character->free_kiseki ?? 0);
        $character->save();
        $this->incrementLimit($character, $itemKey, 'purchased_count');

        $displayAfter = $column === 'material_storage_limit'
            ? $storageCapacity->materialLimit($character)
            : $storageCapacity->equipmentLimit($character);
        $effectValue = number_format($displayAfter - $displayBefore);
        $before = number_format($displayBefore);
        $after = number_format($displayAfter);

        return $column === 'material_storage_limit'
            ? "素材倉庫を拡張しました。保管枠：{$before} → {$after}（+{$effectValue}）"
            : "装備倉庫を拡張しました。保管枠：{$before} → {$after}（+{$effectValue}）";
    }

    private function useSupportPassTicket(Character $character, string $itemKey, array $item): array
    {
        return DB::transaction(function () use ($character, $itemKey, $item) {
            $row = CharacterConsumableItem::where('character_id', $character->id)
                ->where('item_key', $itemKey)
                ->lockForUpdate()
                ->first();

            if (!$row || (int) $row->quantity <= 0) {
                return ['success' => false, 'message' => "{$item['name']}を所持していません。"];
            }

            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $result = app(SupportPassService::class)->purchaseFor($lockedCharacter, 0, 'ticket');
            if (!($result['success'] ?? false)) {
                return ['success' => false, 'message' => $result['message'] ?? '冒険者支援パスを有効にできませんでした。'];
            }

            $row->decrement('quantity');

            return ['success' => true, 'message' => $result['message']];
        });
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

    private function useExploreStaminaRecoveryItem(Character $character, string $itemKey, array $item): array
    {
        return DB::transaction(function () use ($character, $itemKey, $item) {
            $row = CharacterConsumableItem::where('character_id', $character->id)
                ->where('item_key', $itemKey)
                ->lockForUpdate()
                ->first();

            if (!$row || (int) $row->quantity <= 0) {
                return ['success' => false, 'message' => "{$item['name']}を所持していません。"];
            }

            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $result = app(ExplorationStaminaService::class)->recoverByItem($lockedCharacter, (int) ($item['effect_value'] ?? 0));
            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'message' => $result['message'] ?? '探索力を回復できませんでした。'];
            }

            $row->decrement('quantity');
            $character->setRawAttributes($lockedCharacter->getAttributes(), true);

            return ['success' => true, 'message' => "{$item['name']}を使用しました。{$result['message']}"];
        });
    }

    private function grantConsumable(Character $character, string $itemKey, string $name, ?string $limitDate = null): string
    {
        CharacterConsumableItem::updateOrCreate(
            ['character_id' => $character->id, 'item_key' => $itemKey],
            ['updated_at' => now()]
        )->increment('quantity');

        $this->incrementLimit($character, $itemKey, 'purchased_count', $limitDate);

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

    private function spendGold(Character $character, int $amount, string $itemKey, string $itemName): array
    {
        app(GoldService::class)->spend(
            $character,
            $amount,
            'adventure_support_purchase',
            "{$itemName}購入（{$itemKey}）",
            self::class,
            null,
            ['item_key' => $itemKey]
        );

        return ['gold_spent' => $amount];
    }

    private function availability(Character $character, string $key, array $item, bool $locked = false): array
    {
        $currency = $this->currency($item);
        $total = $currency === 'gold'
            ? (int) ($character->money ?? 0)
            : (int) ($character->free_kiseki ?? 0) + (int) ($character->paid_kiseki ?? 0);
        $purchasedCount = $this->purchasedCount($character, $key, null, $locked);
        $dailyPurchasedCount = $this->purchasedCount($character, $key, today('Asia/Tokyo')->toDateString(), $locked);
        $disabledReason = null;

        if (!app(AdventureSupportItemControlService::class)->isVisible($key, $item)) {
            $disabledReason = "{$item['name']}は現在販売していません。";
        } elseif (!app(AdventureSupportItemControlService::class)->isEnabled($key, $item)) {
            $disabledReason = "{$item['name']}は現在販売休止中です。";
        } elseif (($item['purchase_limit'] ?? null) && $purchasedCount >= (int) $item['purchase_limit']) {
            $disabledReason = $key === 'adventurer_departure_set'
                ? 'このセットは一度限りの購入です。すでに購入済みです。'
                : "{$item['name']}はこれ以上購入できません。";
        } elseif (($item['daily_purchase_limit'] ?? null) && $dailyPurchasedCount >= (int) $item['daily_purchase_limit']) {
            $disabledReason = "{$item['name']}は本日の購入上限に達しています。";
        } elseif ($total < (int) $item['price']) {
            $disabledReason = $currency === 'gold'
                ? 'Goldが不足しています。素材売却や探索でGoldを集めてから再度お試しください。'
                : '輝石が不足しています。輝石を購入してから再度お試しください。';
        } elseif (($item['effect_type'] ?? null) === 'explore_stamina_recovery'
            && app(ExplorationStaminaService::class)->recoverableAmount($character, (int) ($item['effect_value'] ?? 0)) <= 0) {
            $disabledReason = '探索力制が有効な時だけ購入できます。';
        } elseif ($this->requiresSupportPass($item)
            && !app(SupportPassService::class)->enabled()) {
            $disabledReason = '冒険者支援パスは現在販売していません。';
        } elseif (($item['effect_type'] ?? null) === 'adventurer_departure_set'
            && !$this->departureSetAssetsReady($item)) {
            $disabledReason = '限定カードフレームの準備中です。しばらくしてからお試しください。';
        } elseif ($key === 'adventurer_supply_box' && $this->isExploring($character)) {
            $disabledReason = '探索中は冒険者補給箱を購入できません。街に戻ってから購入してください。';
        }

        return [
            'can_purchase' => $disabledReason === null,
            'disabled_reason' => $disabledReason,
            'purchased_count' => $purchasedCount,
            'daily_purchased_count' => $dailyPurchasedCount,
            'used_today' => $this->usedToday($character, $key, $locked),
            'support_pass' => ($item['effect_type'] ?? null) === SupportPassService::PASS_TYPE
                ? app(SupportPassService::class)->statusForCharacter($character)
                : null,
        ];
    }

    private function purchaseLabel(Character $character, string $key, array $item, array $state): string
    {
        if (!empty($item['purchase_button_label'])) {
            return (string) $item['purchase_button_label'];
        }

        if (($item['effect_type'] ?? null) === SupportPassService::PASS_TYPE) {
            if (!($state['can_purchase'] ?? false)) {
                $disabledReason = (string) ($state['disabled_reason'] ?? '');
                if (str_contains($disabledReason, '最大90日先') || str_contains($disabledReason, 'これ以上延長')) {
                    return 'これ以上延長できません';
                }

                return '購入不可';
            }

            return '利用券を購入';
        }

        return '購入する';
    }

    private function requiresSupportPass(array $item): bool
    {
        return (bool) ($item['requires_support_pass'] ?? false)
            || ($item['effect_type'] ?? null) === SupportPassService::PASS_TYPE;
    }

    private function departureSetAssetsReady(array $item): bool
    {
        if (!Schema::hasTable('character_adventurer_card_assets')) {
            return false;
        }

        foreach ((array) data_get($item, 'grants.profile_assets', []) as $asset) {
            $path = (string) ($asset['asset_path'] ?? '');
            if ($path === '' || !is_file(public_path($path))) {
                return false;
            }
        }

        return true;
    }

    private function currency(array $item): string
    {
        return ($item['currency'] ?? 'kiseki') === 'gold' ? 'gold' : 'kiseki';
    }

    private function currencyMeta(array $item): array
    {
        if ($this->currency($item) === 'gold') {
            return [
                'currency' => 'gold',
                'currency_label' => 'Gold',
                'currency_suffix' => 'G',
                'currency_icon_image' => 'images/icon/gold01.webp',
            ];
        }

        return [
            'currency' => 'kiseki',
            'currency_label' => '輝石',
            'currency_suffix' => '',
            'currency_icon_image' => 'images/icon/kiseki.webp',
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

        $query = CharacterShopLimit::where('character_id', $character->id)
            ->where('shop_item_key', $key);

        $date === null ? $query->whereNull('limit_date') : $query->whereDate('limit_date', $date);

        $limit = $query->first();
        if (!$limit) {
            $limit = CharacterShopLimit::create([
                'character_id' => $character->id,
                'shop_item_key' => $key,
                'limit_date' => $date,
            ]);
        }

        $limit->increment($column);
    }

    private function supportConsumableKeys(): \Illuminate\Support\Collection
    {
        return collect($this->supportItemDefinitions())
            ->filter(fn (array $item, string $key) => in_array($key, [self::RESCUE_INSURANCE, self::EMERGENCY_RESCUE_REQUEST], true)
                || in_array(($item['effect_type'] ?? null), ['explore_stamina_recovery', 'support_pass_activation'], true))
            ->keys()
            ->values();
    }

    private function canUseFromInventory(string $key, array $item): bool
    {
        return $key === self::RESCUE_INSURANCE
            || in_array(($item['effect_type'] ?? null), ['explore_stamina_recovery', 'support_pass_activation'], true);
    }

    private function useLabel(string $key, array $item): string
    {
        if ($key === self::RESCUE_INSURANCE) {
            return '探索前に使用';
        }

        return in_array(($item['effect_type'] ?? null), ['explore_stamina_recovery', 'support_pass_activation'], true)
            ? '使用する'
            : '';
    }

    private function useNote(string $key, array $item): string
    {
        if ($key === self::EMERGENCY_RESCUE_REQUEST) {
            return '全滅時に自動使用されます。';
        }

        if (($item['effect_type'] ?? null) === 'explore_stamina_recovery') {
            return '使用すると探索力を回復します。上限超過分も蓄積されます。';
        }

        if (($item['effect_type'] ?? null) === 'support_pass_activation') {
            return '使用すると冒険者支援パスが30日間有効になります。残り期間がある場合は現在の期限から30日延長されます。';
        }

        return '';
    }

    private function supportItemDefinitions(): array
    {
        return array_merge(
            config('adventure_support.items', []),
            config('adventure_support.inventory_items', [])
        );
    }
}
