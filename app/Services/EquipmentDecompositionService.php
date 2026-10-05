<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\EquipmentDecompositionLog;
use App\Models\Material;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EquipmentDecompositionService
{
    public const DISABLED_MESSAGE = '装備分解は現在停止中です。不要な装備は売却してください。';
    private const RETURN_PERCENT = 75;

    public function enabled(): bool
    {
        return (bool) config('features.equipment_decomposition_enabled', false);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new RuntimeException(self::DISABLED_MESSAGE);
        }
    }

    public function candidates(Character $character, string $type = 'weapon'): LengthAwarePaginator
    {
        $this->assertEnabled();
        return CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])
            ->where('character_id', $character->id)
            ->where('enhance_level', '>', 0)
            ->whereHas('item', fn ($query) => $query->where('type', $type))
            ->orderBy('enhance_level')->orderBy('id')
            ->paginate(20)->withQueryString()
            ->through(fn (CharacterItem $row) => $this->candidate($row));
    }

    public function candidate(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        $reason = $this->unavailableReason($characterItem);
        $materials = [];
        if ($reason === null) {
            try {
                $materials = $this->returnedMaterials($characterItem);
            } catch (RuntimeException $e) {
                $reason = $e->getMessage();
            }
        }

        return [
            'character_item' => $characterItem,
            'equipment_instance_id' => $characterItem->id,
            'equipment_master_id' => $item?->id,
            'equipment_name' => $characterItem->displayName(),
            'equipment_type' => $item?->type,
            'equipment_type_label' => $this->typeLabel($item?->type),
            'rank' => $this->rank($item),
            'rank_sort' => $this->rankSort($this->rank($item)),
            'enhancement_level' => (int) ($characterItem->enhance_level ?? 0),
            'category' => $this->categoryName($item),
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) ($characterItem->is_locked ?? false),
            'is_stored' => (bool) ($characterItem->is_stored ?? false),
            'can_disassemble' => $reason === null,
            'unavailable_reason' => $reason,
            'expected_materials' => $materials,
            'returned_total' => array_sum(array_column($materials, 'quantity')),
            'confirmation_hash' => $this->confirmationHash($characterItem, $materials),
        ];
    }

    public function preview(Character $character, CharacterItem $characterItem): array
    {
        $this->assertEnabled();
        $characterItem = CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])
            ->where('character_id', $character->id)->whereKey($characterItem->id)->first();
        if (!$characterItem) {
            throw new RuntimeException('この装備は所持していません。');
        }

        $candidate = $this->candidate($characterItem);
        if (!$candidate['can_disassemble']) {
            throw new RuntimeException($candidate['unavailable_reason']);
        }
        $storage = app(StorageCapacityService::class)->summary($character);
        $candidate['storage'] = $storage;
        $candidate['can_receive'] = $storage['material_total'] + $candidate['returned_total'] <= $storage['material_limit'];

        return $candidate;
    }

    public function disassemble(Character $character, CharacterItem $characterItem, string $confirmationHash): array
    {
        $this->assertEnabled();
        return DB::transaction(function () use ($character, $characterItem, $confirmationHash): array {
            $this->assertEnabled();
            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $locked = CharacterItem::query()->with(['item', 'affixPrefix', 'affixSuffix'])
                ->where('character_id', $lockedCharacter->id)->whereKey($characterItem->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new RuntimeException('この装備は所持していません。分解済み、または状態が変わっています。');
            }
            if (app(MapExplorationItemService::class)->restoreActiveSession($lockedCharacter)) {
                throw new RuntimeException('探索中の地図を切り上げてから分解してください。');
            }

            $candidate = $this->preview($lockedCharacter, $locked);
            if (!hash_equals($candidate['confirmation_hash'], $confirmationHash)) {
                throw new RuntimeException('武具または返却素材の内容が変わりました。分解内容を確認し直してください。');
            }
            if (!$candidate['can_receive']) {
                throw new RuntimeException('返却素材が素材倉庫に入りきりません。倉庫を整理してから分解してください。');
            }

            foreach (collect($candidate['expected_materials'])->sortBy('material_id') as $material) {
                $row = CharacterMaterial::query()->where('character_id', $lockedCharacter->id)
                    ->where('material_id', $material['material_id'])->lockForUpdate()->first();
                if ($row) {
                    $row->increment('quantity', $material['quantity']);
                } else {
                    CharacterMaterial::query()->create([
                        'character_id' => $lockedCharacter->id, 'material_id' => $material['material_id'],
                        'quantity' => $material['quantity'],
                    ]);
                }
            }
            EquipmentDecompositionLog::query()->create([
                'character_id' => $lockedCharacter->id, 'equipment_instance_id' => $locked->id,
                'equipment_master_id' => $locked->item_id, 'equipment_name' => $candidate['equipment_name'],
                'rank' => $candidate['rank'], 'enhancement_level' => $candidate['enhancement_level'],
                'obtained_materials' => $candidate['expected_materials'],
            ]);
            $locked->delete();

            return [
                'message' => $candidate['equipment_name'].'を分解し、強化素材を'.number_format($candidate['returned_total']).'個回収しました。',
                'obtained_materials' => $candidate['expected_materials'],
            ];
        }, 3);
    }

    private function unavailableReason(CharacterItem $equipment): ?string
    {
        if (!$this->enabled()) {
            return self::DISABLED_MESSAGE;
        }
        if (!in_array($equipment->item?->type, ['weapon', 'armor', 'accessory'], true)) {
            return '通常の武器・防具・装飾品のみ分解できます。';
        }
        if ((int) $equipment->enhance_level < 1) {
            return '未強化の装備は分解できません。不要な装備は売却してください。';
        }
        if ($equipment->is_equipped) {
            return '装備中の武具は分解できません。先に装備を外してください。';
        }
        if ($equipment->is_locked) {
            return '保護中の武具は分解できません。先に保護を解除してください。';
        }
        if ($equipment->isMarketListed()) {
            return '出品中の武具は分解できません。先に出品を取り消してください。';
        }
        if (app(NamelessRelicEquipmentService::class)->hasAttachedRelics($equipment)) {
            return '遺物装着中の武具は分解できません。先に遺物を取り外してください。';
        }
        if (!app(GoldService::class)->canSellEquipment($equipment)) {
            return '売却不可の特殊装備は分解できません。';
        }

        return null;
    }

    private function returnedMaterials(CharacterItem $equipment): array
    {
        $requirements = app(EquipmentEnhancementService::class)->cumulativeMaterialRequirements(
            (int) $equipment->enhance_level, $equipment->item->type,
        );
        $returns = [];
        $masters = Material::query()->whereIn('material_code', array_column($requirements, 'material_id'))
            ->get()->keyBy('material_code');
        foreach ($requirements as $requirement) {
            $quantity = intdiv((int) $requirement['total_quantity'] * self::RETURN_PERCENT, 100);
            if ($quantity === 0) {
                continue;
            }
            $material = $masters->get($requirement['material_id']);
            if (!$material) {
                throw new RuntimeException('返却素材のマスタが見つかりません。分解は行えません。');
            }
            $returns[] = [
                'material_id' => $material->id, 'material_code' => $material->material_code,
                'name' => $material->name, 'quantity' => $quantity,
            ];
        }

        return $returns;
    }

    private function confirmationHash(CharacterItem $equipment, array $materials): string
    {
        $attributes = $equipment->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode([
            $attributes, $equipment->item?->getAttributes(), $equipment->displayName(), $materials,
        ], JSON_THROW_ON_ERROR));
    }

    private function categoryName($item): string
    {
        if (!$item) {
            return '装備';
        }

        return match ($item->type) {
            'weapon' => (string) ($item->weapon_family_name ?? $item->sub_type ?? '武器'),
            'armor' => (string) ($item->armor_family_name ?? $item->sub_type ?? '防具'),
            'accessory' => (string) ($item->accessory_family_name ?? $item->sub_type ?? '装飾品'),
            default => '装備',
        };
    }

    private function rank($item): ?string
    {
        if (!$item) {
            return null;
        }

        return strtoupper((string) match ($item->type) {
            'weapon' => $item->weapon_rank ?? $item->rarity,
            'armor' => $item->armor_rank ?? $item->rarity,
            'accessory' => $item->accessory_rank ?? $item->rarity,
            default => $item->rarity,
        });
    }

    private function typeLabel(?string $type): string
    {
        return match ($type) {
            'weapon' => '武器',
            'armor' => '防具',
            'accessory' => '装飾品',
            default => '装備',
        };
    }

    private function rankSort(?string $rank): int
    {
        return ['G' => 1, 'F' => 2, 'E' => 3, 'D' => 4, 'C' => 5, 'B' => 6, 'A' => 7, 'S' => 8, 'SS' => 9, 'SSS' => 10, 'EPIC' => 11][$rank] ?? 99;
    }
}
