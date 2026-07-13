<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\Item;
use App\Models\Material;
use RuntimeException;

class GoldService
{
    public function materialSalePrice(?Material $material): int
    {
        if (!$material) {
            return 0;
        }

        return max(0, (int) ($material->npc_sale_price ?? 0));
    }

    public function equipmentSalePrice(?Item $item): int
    {
        if (!$item || !in_array($item->type, ['weapon', 'armor', 'accessory'], true)) {
            return 0;
        }

        return max(0, (int) ($item->sell_price ?? 0));
    }

    public function canSellEquipment(CharacterItem $characterItem): bool
    {
        $item = $characterItem->item;

        return $item
            && !$characterItem->is_equipped
            && !$characterItem->is_locked
            && !$characterItem->isMarketListed()
            && $this->equipmentSalePrice($item) > 0
            && !$this->isProtectedEquipment($item);
    }

    public function evolutionCost(?string $rank): int
    {
        $rank = strtoupper((string) $rank);

        return max(0, (int) (config("gold.evolution_costs.{$rank}") ?? 0));
    }

    public function add(Character $character, int $amount, string $type, ?string $note = null, ?string $sourceType = null, ?int $sourceId = null, array $metadata = []): GoldTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Gold加算額が不正です。');
        }

        $character->money = max(0, (int) $character->money) + $amount;
        $character->save();

        return $this->record($character, $type, $amount, $note, $sourceType, $sourceId, $metadata);
    }

    public function spend(Character $character, int $amount, string $type, ?string $note = null, ?string $sourceType = null, ?int $sourceId = null, array $metadata = []): GoldTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Gold消費額が不正です。');
        }

        if ((int) $character->money < $amount) {
            throw new RuntimeException('Goldが不足しています。');
        }

        $character->money = (int) $character->money - $amount;
        $character->save();

        return $this->record($character, $type, -$amount, $note, $sourceType, $sourceId, $metadata);
    }

    public function sellMaterial(Character $character, CharacterMaterial $characterMaterial, int $quantity): array
    {
        $characterMaterial->loadMissing('material');
        $unitPrice = $this->materialSalePrice($characterMaterial->material);
        if ($unitPrice <= 0) {
            throw new RuntimeException('この素材は売却できません。');
        }

        $quantity = max(1, $quantity);
        if ($characterMaterial->quantity < $quantity) {
            throw new RuntimeException('売却する素材数が不足しています。');
        }

        $amount = $unitPrice * $quantity;
        $remaining = (int) $characterMaterial->quantity - $quantity;
        if ($remaining <= 0) {
            $characterMaterial->delete();
        } else {
            $characterMaterial->forceFill(['quantity' => $remaining])->save();
        }

        $materialName = (string) ($characterMaterial->material?->displayName() ?? '素材');

        $this->add($character, $amount, 'material_sale', "{$materialName} x{$quantity} を売却", CharacterMaterial::class, $characterMaterial->id, [
            'material_id' => $characterMaterial->material_id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return [
            'name' => $materialName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'remaining_quantity' => $remaining,
        ];
    }

    public function sellEquipment(Character $character, CharacterItem $characterItem): array
    {
        $characterItem->loadMissing('item');
        if ((int) $characterItem->character_id !== (int) $character->id) {
            throw new RuntimeException('この装備は所持していません。');
        }

        if (!$this->canSellEquipment($characterItem)) {
            if ($characterItem->isMarketListed()) {
                throw new RuntimeException('この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
            }
            throw new RuntimeException('この装備は売却できません。');
        }

        $item = $characterItem->item;
        $amount = $this->equipmentSalePrice($item);
        $name = $characterItem->displayName();
        $characterItemId = (int) $characterItem->id;
        $itemId = (int) $item->id;
        $characterItem->delete();

        $this->add($character, $amount, 'equipment_sale', "{$name} を売却", CharacterItem::class, $characterItemId, [
            'item_id' => $itemId,
            'item_name' => $item->name,
            'rank' => $this->equipmentRank($item),
        ]);

        return [
            'name' => $name,
            'amount' => $amount,
        ];
    }

    public function record(Character $character, string $type, int $amount, ?string $note = null, ?string $sourceType = null, ?int $sourceId = null, array $metadata = []): GoldTransaction
    {
        return GoldTransaction::create([
            'character_id' => $character->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => (int) $character->money,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'metadata' => $metadata,
        ]);
    }

    public function equipmentRank(?Item $item): ?string
    {
        if (!$item) {
            return null;
        }

        return match ($item->type) {
            'weapon' => $item->weapon_rank,
            'armor' => $item->armor_rank,
            'accessory' => $item->accessory_rank,
            default => $item->rarity,
        };
    }

    private function isProtectedEquipment(Item $item): bool
    {
        $name = (string) $item->name;
        $subType = (string) ($item->sub_type ?? '');

        return in_array($subType, ['刻印', '王印', '神印'], true)
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }
}
