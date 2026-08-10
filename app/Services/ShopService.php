<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use App\Models\CharacterItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopService
{
    public function __construct(private readonly BankService $bankService)
    {
    }

    /**
     * ショップでの実購入価格を返す。
     */
    public function priceFor(Character $character, Item $item): int
    {
        if ($item->type !== 'consumable') {
            return (int) $item->price;
        }

        $level = max(1, (int) $character->level);

        return match ($item->name) {
            '薬草' => 30 + ($level * 8),
            '回復薬' => 80 + ($level * 18),
            '魔力水' => 60 + ($level * 15),
            default => (int) $item->price,
        };
    }

    /**
     * 購入可能か判定する
     */
    public function canBuy(Character $character, Item $item, int $quantity = 1): array
    {
        $quantity = max(1, $item->type === 'consumable' ? min(99, $quantity) : 1);

        if ($item->type === 'consumable') {
            return ['success' => false, 'message' => '回復アイテムは購入ではなく、補給所で毎日10個まで受け取れます。'];
        }

        if (!in_array($item->type, ['weapon', 'armor'], true) || !$item->is_shop_item) {
            return ['success' => false, 'message' => 'この装備は現在の装備屋では購入できません。'];
        }

        if (!$item->is_active) {
            return ['success' => false, 'message' => 'この装備は現在販売されていません。'];
        }

        if ((int) ($item->unlock_city_id ?? 0) !== (int) ($character->current_city_id ?? 0)) {
            return ['success' => false, 'message' => 'この装備は現在いる街では購入できません。'];
        }

        $price = $this->priceFor($character, $item) * $quantity;
        if ($price <= 0) {
            return ['success' => false, 'message' => 'この装備は価格が設定されていないため購入できません。'];
        }

        if ($this->bankService->summary($character)['total_gold'] < $price) {
            return ['success' => false, 'message' => 'Goldが不足しています。'];
        }

        return ['success' => true];
    }

    /**
     * 購入処理を実行する
     */
    public function buy(Character $character, Item $item, int $quantity = 1, bool $useBank = false): array
    {
        $quantity = max(1, $item->type === 'consumable' ? min(99, $quantity) : 1);

        try {
            $result = DB::transaction(function () use ($character, $item, $quantity, $useBank): array {
                $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
                $canBuy = $this->canBuy($lockedCharacter, $item, $quantity);
                if (!$canBuy['success']) {
                    return $canBuy;
                }

                $unitPrice = $this->priceFor($lockedCharacter, $item);
                $totalPrice = $unitPrice * $quantity;
                $payment = $this->bankService->spendForPayment(
                    $lockedCharacter,
                    $totalPrice,
                    $useBank,
                    'shop_equipment_purchase',
                    "{$item->name} を装備屋で購入",
                    Item::class,
                    (int) $item->id,
                    [
                        'item_id' => (int) $item->id,
                        'item_name' => $item->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'city_id' => (int) ($lockedCharacter->current_city_id ?? 0),
                    ]
                );

                $characterItem = null;
                for ($i = 0; $i < $quantity; $i++) {
                    $characterItem = CharacterItem::create([
                        'character_id' => $lockedCharacter->id,
                        'item_id' => $item->id,
                        'is_equipped' => false,
                        'acquired_from' => 'shop',
                    ]);
                }

                $message = $quantity > 1
                    ? "{$item->name}を{$quantity}個購入しました。（" . number_format($totalPrice) . 'G）'
                    : "{$item->name}を購入しました。（" . number_format($totalPrice) . 'G）';
                if ($payment['bank_gold_used'] > 0) {
                    $message .= '（手持ち' . number_format($payment['hand_gold_used'])
                        . 'G・銀行' . number_format($payment['bank_gold_used']) . 'G）';
                }

                return [
                    'success' => true,
                    'message' => $message,
                    'character_item_id' => $characterItem->id,
                    'item_type' => $item->type,
                    'quantity' => $quantity,
                ];
            }, 3);

            $character->refresh();

            return $result;
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '購入処理に失敗しました。'];
        }
    }

    private function alreadyClaimedToday(Character $character, Item $item): bool
    {
        if (!Schema::hasTable('character_equipment_daily_supplies')) {
            return false;
        }

        return DB::table('character_equipment_daily_supplies')
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->whereDate('claimed_on', today())
            ->exists();
    }

    private function recordDailyClaim(Character $character, Item $item): void
    {
        if (!Schema::hasTable('character_equipment_daily_supplies')) {
            return;
        }

        DB::table('character_equipment_daily_supplies')->updateOrInsert(
            [
                'character_id' => $character->id,
                'item_id' => $item->id,
                'claimed_on' => today()->toDateString(),
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
