<?php

namespace App\Services;

use App\Models\Character;
use App\Models\TowerMerchantPurchase;
use App\Models\TowerRun;
use App\Models\TowerRunEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TowerMerchantService
{
    public const PENDING_EVENT = 'merchant';

    public function __construct(private readonly GoldService $goldService)
    {
    }

    /**
     * @return array<int,array{key:string,name:string,price:int,effect_type:string,effect_value:int,recover_amount:int,description:string,purchased:bool}>
     */
    public function products(TowerRun $run): array
    {
        $hpRate = max(1, (int) config('star_tree_tower.star_tree.merchant_hp_item_recover_rate', 25));
        $mpRate = max(1, (int) config('star_tree_tower.star_tree.merchant_mp_item_recover_rate', 25));
        $wardRate = max(1, min(80, (int) config('star_tree_tower.star_tree.merchant_ward_damage_reduction_rate', 20)));
        $purchasedItemKeys = $run->last_merchant_floor
            ? TowerMerchantPurchase::query()
                ->where('tower_run_id', $run->id)
                ->where('floor', (int) $run->last_merchant_floor)
                ->pluck('item_key')
                ->all()
            : [];

        return [
            [
                'key' => $this->merchantItemConfig('hp', 'key', 'star_leaf_herb'),
                'name' => $this->merchantItemConfig('hp', 'name', '星葉の薬草'),
                'price' => max(1, (int) config('star_tree_tower.star_tree.merchant_hp_item_price', 500)),
                'effect_type' => 'hp_recover_rate',
                'effect_value' => $hpRate,
                'recover_amount' => max(1, (int) floor((int) $run->tower_max_hp * $hpRate / 100)),
                'description' => $this->merchantDescription(
                    $this->merchantItemConfig('hp', 'description_template', 'HPを最大HPの{rate}%回復'),
                    $hpRate
                ),
                'purchased' => in_array($this->merchantItemConfig('hp', 'key', 'star_leaf_herb'), $purchasedItemKeys, true),
            ],
            [
                'key' => $this->merchantItemConfig('sp', 'key', 'moon_dew_vial'),
                'name' => $this->merchantItemConfig('sp', 'name', '月露の小瓶'),
                'price' => max(1, (int) config('star_tree_tower.star_tree.merchant_mp_item_price', 800)),
                'effect_type' => 'mp_recover_rate',
                'effect_value' => $mpRate,
                'recover_amount' => max(1, (int) floor((int) $run->tower_max_mp * $mpRate / 100)),
                'description' => $this->merchantDescription(
                    $this->merchantItemConfig('sp', 'description_template', 'SPを最大SPの{rate}%回復'),
                    $mpRate
                ),
                'purchased' => in_array($this->merchantItemConfig('sp', 'key', 'moon_dew_vial'), $purchasedItemKeys, true),
            ],
            [
                'key' => $this->merchantItemConfig('ward', 'key', 'kodama_ward'),
                'name' => $this->merchantItemConfig('ward', 'name', '木霊の護符'),
                'price' => max(1, (int) config('star_tree_tower.star_tree.merchant_ward_item_price', 1200)),
                'effect_type' => 'damage_reduction_next',
                'effect_value' => $wardRate,
                'recover_amount' => 0,
                'description' => $this->merchantDescription(
                    $this->merchantItemConfig('ward', 'description_template', '次の戦闘だけ被ダメージを{rate}%軽減'),
                    $wardRate
                ),
                'purchased' => in_array($this->merchantItemConfig('ward', 'key', 'kodama_ward'), $purchasedItemKeys, true),
            ],
        ];
    }

    public function maybeReserveAfterVictory(Character $character, TowerRun $run): ?TowerRunEvent
    {
        if ($run->status !== StarTreeTowerService::STATUS_RUNNING) {
            return null;
        }

        $clearedFloor = (int) $run->cleared_floor;
        if ($clearedFloor < 2 || $run->pending_event) {
            return null;
        }
        if ($this->isRewardFloor($clearedFloor)) {
            return null;
        }

        $lastMerchantFloor = $run->last_merchant_floor;
        $cooldownFloors = max(0, (int) config('star_tree_tower.star_tree.merchant_cooldown_floors', 5));
        if ($lastMerchantFloor !== null && ($clearedFloor - (int) $lastMerchantFloor) <= $cooldownFloors) {
            return null;
        }

        $rate = max(0, min(100, (int) config('star_tree_tower.star_tree.merchant_rate', 5)));
        if ($rate <= 0 || random_int(1, 100) > $rate) {
            return null;
        }

        return DB::transaction(function () use ($character, $run, $clearedFloor): TowerRunEvent {
            $lockedRun = TowerRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== StarTreeTowerService::STATUS_RUNNING || $lockedRun->pending_event) {
                throw new RuntimeException($this->displayText('merchant_name', '星灯の行商人').'の出現状態が更新されています。');
            }

            $lockedRun->forceFill([
                'pending_event' => self::PENDING_EVENT,
                'last_merchant_floor' => $clearedFloor,
                'merchant_encounter_count' => (int) $lockedRun->merchant_encounter_count + 1,
                'last_event_type' => 'merchant',
            ])->save();

            $run->setRawAttributes($lockedRun->getAttributes(), true);

            return TowerRunEvent::query()->create([
                'tower_run_id' => $lockedRun->id,
                'character_id' => $character->id,
                'floor' => $clearedFloor,
                'event_type' => 'merchant',
                'result' => 'appeared',
                'hp_after' => $lockedRun->tower_current_hp,
                'mp_after' => $lockedRun->tower_current_mp,
                'message' => $this->displayText('merchant_appeared_message', '星灯の行商人が、枝の上に腰かけていた。'),
            ]);
        });
    }

    private function isRewardFloor(int $floor): bool
    {
        $rewardFloors = collect(array_keys((array) config('star_tree_tower_rewards.weapon_rewards', [])))
            ->map(fn ($rewardFloor): int => (int) $rewardFloor)
            ->push((int) config('star_tree_tower_rewards.card_background.floor', 0))
            ->push((int) config('star_tree_tower_rewards.card_frame.floor', 0))
            ->filter(fn (int $rewardFloor): bool => $rewardFloor > 0)
            ->unique()
            ->all();

        return in_array($floor, $rewardFloors, true);
    }

    public function buy(Character $character, TowerRun $run, string $itemKey): TowerRunEvent
    {
        return DB::transaction(function () use ($character, $run, $itemKey): TowerRunEvent {
            $lockedRun = TowerRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();

            $this->assertCanUseMerchant($lockedCharacter, $lockedRun);

            $product = collect($this->products($lockedRun))->firstWhere('key', $itemKey);
            if (!$product) {
                throw new InvalidArgumentException('この商品は購入できません。');
            }

            $alreadyBought = TowerMerchantPurchase::query()
                ->where('tower_run_id', $lockedRun->id)
                ->where('floor', (int) $lockedRun->last_merchant_floor)
                ->where('item_key', $product['key'])
                ->exists();
            if ($alreadyBought) {
                throw new RuntimeException('この商品はすでに購入済みです。');
            }

            $this->goldService->spend(
                $lockedCharacter,
                (int) $product['price'],
                'tower_merchant',
                $this->displayText('merchant_name', '星灯の行商人').": {$product['name']}",
                TowerRun::class,
                (int) $lockedRun->id,
                [
                    'tower_key' => $lockedRun->tower_key,
                    'floor' => (int) $lockedRun->last_merchant_floor,
                    'item_key' => $product['key'],
                ]
            );

            $lockedRun->gold_spent = (int) $lockedRun->gold_spent + (int) $product['price'];
            $lockedRun->last_event_type = 'merchant_purchase';
            $lockedRun->save();

            TowerMerchantPurchase::query()->create([
                'tower_run_id' => $lockedRun->id,
                'character_id' => $lockedCharacter->id,
                'floor' => (int) $lockedRun->last_merchant_floor,
                'item_key' => $product['key'],
                'item_name' => $product['name'],
                'price' => (int) $product['price'],
                'effect_type' => $product['effect_type'],
                'effect_value' => (int) $product['effect_value'],
            ]);

            $character->setRawAttributes($lockedCharacter->getAttributes(), true);
            $run->setRawAttributes($lockedRun->getAttributes(), true);

            $purchaseMessage = $product['effect_type'] === 'damage_reduction_next'
                ? "{$product['name']}を購入しました。使うと次の戦闘で発動します。"
                : "{$product['name']}を購入しました。塔内状況から使用できます。";

            return TowerRunEvent::query()->create([
                'tower_run_id' => $lockedRun->id,
                'character_id' => $lockedCharacter->id,
                'floor' => (int) $lockedRun->last_merchant_floor,
                'event_type' => 'merchant_purchase',
                'result' => 'purchased',
                'hp_after' => $lockedRun->tower_current_hp,
                'mp_after' => $lockedRun->tower_current_mp,
                'gold_delta' => -((int) $product['price']),
                'message' => $purchaseMessage,
                'metadata' => [
                    'item_key' => $product['key'],
                    'item_name' => $product['name'],
                ],
            ]);
        });
    }

    /**
     * @return array<int,array{purchase_id:int,key:string,name:string,description:string,count:int,effect_type:string,usable:bool,armed:bool}>
     */
    public function availableRecoveryItems(?TowerRun $run): array
    {
        if (!$run || $run->status !== StarTreeTowerService::STATUS_RUNNING) {
            return [];
        }

        $products = collect($this->products($run))->keyBy('key');
        $purchasesByKey = $run->merchantPurchases()
            ->whereNull('used_at')
            ->orderBy('id')
            ->get()
            ->groupBy('item_key');

        return $products
            ->map(function (array $product, string $key) use ($purchasesByKey): ?array {
                $purchases = $purchasesByKey->get($key);
                if (!$purchases || $purchases->isEmpty()) {
                    return null;
                }
                $displayPurchase = (string) $product['effect_type'] === 'damage_reduction_next'
                    ? ($purchases->first(fn (TowerMerchantPurchase $purchase): bool => $purchase->activated_at === null) ?? $purchases->first())
                    : $purchases->first();

                return [
                    'purchase_id' => (int) $displayPurchase->id,
                    'key' => $key,
                    'name' => (string) $product['name'],
                    'description' => $this->shortDescription($product),
                    'count' => $purchases->count(),
                    'effect_type' => (string) $product['effect_type'],
                    'usable' => $this->isManuallyUsable($product, $displayPurchase),
                    'armed' => $this->isArmedWard($product, $displayPurchase),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function usePurchasedItem(Character $character, TowerRun $run, TowerMerchantPurchase $purchase): TowerRunEvent
    {
        return DB::transaction(function () use ($character, $run, $purchase): TowerRunEvent {
            $lockedRun = TowerRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedPurchase = TowerMerchantPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedRun->character_id !== (int) $character->id) {
                throw new InvalidArgumentException('この塔挑戦は操作できません。');
            }

            if ($lockedRun->status !== StarTreeTowerService::STATUS_RUNNING) {
                throw new RuntimeException('進行中の'.$this->displayText('name', '星樹の塔').'がありません。');
            }

            if (
                (int) $lockedPurchase->tower_run_id !== (int) $lockedRun->id
                || (int) $lockedPurchase->character_id !== (int) $character->id
                || $lockedPurchase->used_at !== null
            ) {
                throw new RuntimeException('この塔内アイテムは使用できません。');
            }

            $product = collect($this->products($lockedRun))->firstWhere('key', $lockedPurchase->item_key);
            if (!$product) {
                throw new InvalidArgumentException('この塔内アイテムは使用できません。');
            }

            $recoverAmount = 0;

            if ($product['effect_type'] === 'hp_recover_rate') {
                $recoverAmount = max(1, (int) floor((int) $lockedRun->tower_max_hp * (int) $product['effect_value'] / 100));
                if ((int) $lockedRun->tower_current_hp >= (int) $lockedRun->tower_max_hp) {
                    throw new RuntimeException('HPはすでに最大です。');
                }

                $lockedRun->tower_current_hp = min(
                    (int) $lockedRun->tower_max_hp,
                    (int) $lockedRun->tower_current_hp + $recoverAmount
                );
            } elseif ($product['effect_type'] === 'mp_recover_rate') {
                $recoverAmount = max(1, (int) floor((int) $lockedRun->tower_max_mp * (int) $product['effect_value'] / 100));
                if ((int) $lockedRun->tower_current_mp >= (int) $lockedRun->tower_max_mp) {
                    throw new RuntimeException('SPはすでに最大です。');
                }

                $lockedRun->tower_current_mp = min(
                    (int) $lockedRun->tower_max_mp,
                    (int) $lockedRun->tower_current_mp + $recoverAmount
                );
            } elseif ($product['effect_type'] === 'damage_reduction_next') {
                if ($lockedPurchase->activated_at !== null) {
                    throw new RuntimeException('木霊の護符はすでに次の戦闘に備えています。');
                }

                $lockedPurchase->activated_at = now();
            } else {
                throw new RuntimeException('この塔内アイテムは手動では使用できません。');
            }

            if ($product['effect_type'] !== 'damage_reduction_next') {
                $lockedPurchase->used_at = now();
            }
            $lockedPurchase->save();

            $lockedRun->last_event_type = 'merchant_item_use';
            $lockedRun->save();

            $run->setRawAttributes($lockedRun->getAttributes(), true);

            return TowerRunEvent::query()->create([
                'tower_run_id' => $lockedRun->id,
                'character_id' => $character->id,
                'floor' => (int) $lockedRun->current_floor,
                'event_type' => 'merchant_item_use',
                'result' => 'used',
                'hp_after' => $lockedRun->tower_current_hp,
                'mp_after' => $lockedRun->tower_current_mp,
                'message' => $product['effect_type'] === 'damage_reduction_next'
                    ? "{$product['name']}を使いました。次の戦闘で発動します。"
                    : "{$product['name']}を使いました。",
                'metadata' => [
                    'item_key' => $product['key'],
                    'item_name' => $product['name'],
                    'recover_amount' => $recoverAmount,
                    'armed' => $product['effect_type'] === 'damage_reduction_next',
                ],
            ]);
        });
    }

    public function skip(Character $character, TowerRun $run): TowerRunEvent
    {
        return DB::transaction(function () use ($character, $run): TowerRunEvent {
            $lockedRun = TowerRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->assertCanUseMerchant($character, $lockedRun);

            $lockedRun->forceFill([
                'pending_event' => null,
                'last_event_type' => 'merchant_skip',
            ])->save();

            $run->setRawAttributes($lockedRun->getAttributes(), true);

            return TowerRunEvent::query()->create([
                'tower_run_id' => $lockedRun->id,
                'character_id' => $character->id,
                'floor' => (int) $lockedRun->last_merchant_floor,
                'event_type' => 'merchant',
                'result' => 'skipped',
                'hp_after' => $lockedRun->tower_current_hp,
                'mp_after' => $lockedRun->tower_current_mp,
                'message' => $this->displayText('merchant_skipped_message', '星灯の行商人を見送りました。'),
            ]);
        });
    }

    private function assertCanUseMerchant(Character $character, TowerRun $run): void
    {
        if ((int) $run->character_id !== (int) $character->id) {
            throw new InvalidArgumentException('この塔挑戦は操作できません。');
        }

        if ($run->status !== StarTreeTowerService::STATUS_RUNNING || $run->pending_event !== self::PENDING_EVENT) {
            throw new RuntimeException($this->displayText('merchant_none_message', '星灯の行商人はいません。'));
        }
    }

    private function displayText(string $key, string $default): string
    {
        return (string) config("star_tree_tower.star_tree.display.{$key}", $default);
    }

    private function merchantItemConfig(string $item, string $key, string $default): string
    {
        return (string) config("star_tree_tower.star_tree.merchant_items.{$item}.{$key}", $default);
    }

    private function merchantDescription(string $template, int $rate): string
    {
        return str_replace('{rate}', (string) $rate, $template);
    }

    /**
     * @param array{effect_type:string,effect_value:int,description:string} $product
     */
    private function shortDescription(array $product): string
    {
        return match ($product['effect_type']) {
            'hp_recover_rate' => "HP{$product['effect_value']}%",
            'mp_recover_rate' => "SP{$product['effect_value']}%",
            'damage_reduction_next' => "次戦闘の被ダメ-{$product['effect_value']}%",
            default => $product['description'],
        };
    }

    /**
     * @param array{effect_type:string} $product
     */
    private function isManuallyUsable(array $product, TowerMerchantPurchase $purchase): bool
    {
        if (in_array((string) $product['effect_type'], ['hp_recover_rate', 'mp_recover_rate'], true)) {
            return true;
        }

        return (string) $product['effect_type'] === 'damage_reduction_next'
            && $purchase->activated_at === null;
    }

    /**
     * @param array{effect_type:string} $product
     */
    private function isArmedWard(array $product, TowerMerchantPurchase $purchase): bool
    {
        return (string) $product['effect_type'] === 'damage_reduction_next'
            && $purchase->activated_at !== null;
    }
}
