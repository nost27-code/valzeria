<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\Item;
use App\Models\Material;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Support\MaterialInventoryRules;
use Illuminate\Support\Str;

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
        return $this->equipmentSaleAllowed($characterItem)
            && !app(NamelessRelicEquipmentService::class)->hasAttachedRelics($characterItem);
    }

    /** 一覧の先読み結果だけを使う。更新処理では canSellEquipment() で再検証する。 */
    public function canSellEquipmentForDisplay(CharacterItem $characterItem): bool
    {
        if (!array_key_exists('relics_exists', $characterItem->getAttributes())) {
            return $this->canSellEquipment($characterItem);
        }

        return $this->equipmentSaleAllowed($characterItem) && !(bool) $characterItem->relics_exists;
    }

    private function equipmentSaleAllowed(CharacterItem $characterItem): bool
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
        $result = DB::transaction(function () use ($character, $characterMaterial, $quantity): array {
            // Use the same Character -> inventory lock order as banking and the market.
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $lockedMaterial = CharacterMaterial::query()
                ->whereKey($characterMaterial->id)
                ->where('character_id', $lockedCharacter->id)
                ->with('material')
                ->lockForUpdate()
                ->first();

            if (! $lockedMaterial) {
                throw new RuntimeException('売却する素材の状態が変わりました。持ち物を開き直してください。');
            }

            return $this->sellLockedMaterial($lockedCharacter, $lockedMaterial, $quantity);
        });

        $character->refresh();

        return $result;
    }

    public function sellMaterialsBulk(Character $character, array $sales, string $requestUuid): array
    {
        if (!Str::isUuid($requestUuid) || $sales === []) {
            throw new RuntimeException('売却する素材を選び直してください。');
        }
        $requestUuid = strtolower($requestUuid);

        $sales = collect($sales)->map(fn (array $sale): array => [
            'character_material_id' => (int) ($sale['character_material_id'] ?? 0),
            'quantity' => (int) ($sale['quantity'] ?? 0),
        ])->sortBy('character_material_id')->values();

        if ($sales->contains(fn (array $sale): bool => $sale['character_material_id'] <= 0 || $sale['quantity'] <= 0)
            || $sales->pluck('character_material_id')->unique()->count() !== $sales->count()) {
            throw new RuntimeException('売却する素材と個数を確認してください。');
        }

        $payloadHash = hash('sha256', json_encode($sales->all(), JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($character, $sales, $requestUuid, $payloadHash): array {
            // 単品売却・銀行・市場と同じ Character -> inventory の順でロックする。
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $previous = GoldTransaction::query()
                ->where('character_id', $lockedCharacter->id)
                ->where('type', 'material_sale')
                ->where('metadata->bulk_sale_uuid', $requestUuid)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($previous->isNotEmpty()) {
                if ($previous->contains(fn (GoldTransaction $log): bool => ($log->metadata['bulk_sale_hash'] ?? '') !== $payloadHash)) {
                    throw new RuntimeException('前回と売却内容が変わっています。倉庫を開き直してください。');
                }

                return $this->materialBulkSaleResult($previous->map(fn (GoldTransaction $log): array => [
                    'character_material_id' => (int) $log->source_id,
                    'name' => $log->metadata['sale_name'],
                    'quantity' => (int) $log->metadata['quantity'],
                    'unit_price' => (int) $log->metadata['unit_price'],
                    'amount' => (int) $log->amount,
                    'remaining_quantity' => (int) $log->metadata['remaining_quantity'],
                ])->all(), (int) $lockedCharacter->money);
            }

            $stock = CharacterMaterial::query()
                ->where('character_id', $lockedCharacter->id)
                ->whereIn('id', $sales->pluck('character_material_id'))
                ->orderBy('id')
                ->with('material')
                ->lockForUpdate()
                ->get()->keyBy('id');

            if ($stock->count() !== $sales->count()) {
                throw new RuntimeException('選択した素材の状態が変わりました。倉庫を開き直してください。');
            }

            $results = [];
            foreach ($sales as $sale) {
                $row = $stock->get($sale['character_material_id']);
                $results[] = [
                    'character_material_id' => (int) $row->id,
                    ...$this->sellLockedMaterial($lockedCharacter, $row, $sale['quantity'], [
                        'bulk_sale_uuid' => $requestUuid,
                        'bulk_sale_hash' => $payloadHash,
                        'sale_name' => (string) ($row->material?->displayName() ?? '素材'),
                        'remaining_quantity' => (int) $row->quantity - $sale['quantity'],
                    ]),
                ];
            }

            return $this->materialBulkSaleResult($results, (int) $lockedCharacter->money);
        }, 3);

        $character->refresh();

        return $result;
    }

    private function materialBulkSaleResult(array $sales, int $money): array
    {
        return [
            'count' => count($sales),
            'quantity' => array_sum(array_column($sales, 'quantity')),
            'amount' => array_sum(array_column($sales, 'amount')),
            'money' => $money,
            'sales' => $sales,
        ];
    }

    private function sellLockedMaterial(Character $character, CharacterMaterial $characterMaterial, int $quantity, array $metadata = []): array
    {
        $characterMaterial->loadMissing('material');
        if (MaterialInventoryRules::isKeyMaterial($characterMaterial->material)) {
            throw new RuntimeException('大事なものは売却できません。');
        }
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
            ...$metadata,
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
        if (! app(NamelessWorkshopService::class)->enabled()) {
            return $this->sellLockedEquipment($character, $characterItem);
        }
        $result = DB::transaction(function () use ($character, $characterItem): array {
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $item = CharacterItem::query()->where('character_id', $locked->id)->whereKey($characterItem->id)->with('item')->lockForUpdate()->first();
            if (! $item) {
                throw new RuntimeException('この装備は所持していません。');
            }
            $result = $this->sellLockedEquipment($locked, $item);
            $character->setRawAttributes($locked->getAttributes(), true);

            return $result;
        }, 3);

        return $result;
    }

    private function sellLockedEquipment(Character $character, CharacterItem $characterItem): array
    {
        $characterItem->loadMissing('item');
        if ((int) $characterItem->character_id !== (int) $character->id) {
            throw new RuntimeException('この装備は所持していません。');
        }

        app(NamelessRelicEquipmentService::class)->assertDetached($characterItem);
        if (!$this->canSellEquipment($characterItem)) {
            if ($characterItem->isMarketListed()) {
                throw new RuntimeException('この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
            }
            if ($characterItem->is_locked) {
                throw new RuntimeException('保護中の装備は売却できません。先に星印を解除してください。');
            }
            if ($characterItem->is_equipped) {
                throw new RuntimeException('装備中の装備は売却できません。先に装備を外してください。');
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

    public function sellEquipmentBulk(Character $character, array $characterItemIds): array
    {
        $characterItemIds = collect($characterItemIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($characterItemIds->isEmpty()) {
            throw new RuntimeException('売却する装備を選択してください。');
        }

        $result = DB::transaction(function () use ($character, $characterItemIds): array {
            $lockedCharacter = Character::query()
                ->whereKey($character->id)
                ->lockForUpdate()
                ->firstOrFail();

            $characterItems = CharacterItem::query()
                ->where('character_id', $lockedCharacter->id)
                ->whereIn('id', $characterItemIds)
                ->with('item')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($characterItems->count() !== $characterItemIds->count()) {
                throw new RuntimeException('選択した装備の状態が変わりました。倉庫を再読み込みしてください。');
            }

            $names = [];
            $amount = 0;

            foreach ($characterItemIds as $characterItemId) {
                $sale = $this->sellEquipment($lockedCharacter, $characterItems->get($characterItemId));
                $names[] = $sale['name'];
                $amount += (int) $sale['amount'];
            }

            return [
                'count' => count($names),
                'amount' => $amount,
                'names' => $names,
            ];
        });

        $character->refresh();

        return $result;
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
