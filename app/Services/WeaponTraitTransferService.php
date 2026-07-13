<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\WeaponTraitOperationLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WeaponTraitTransferService
{
    public const OPERATIONS = [
        'engraving_transfer' => '銘移し',
        'slayer_transfer' => '特攻移し',
    ];

    public function __construct(
        private readonly EquipmentAffixRulesService $rules,
        private readonly GoldService $goldService,
    ) {
    }

    /**
     * @return array<string, array{base_options: list<array<string, mixed>>, material_options: list<array<string, mixed>>, gold_costs: array<int, int>}>
     */
    public function candidates(Character $character): array
    {
        $weapons = CharacterItem::query()
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->orderByDesc('is_equipped')
            ->orderByDesc('id')
            ->get();

        $baseOptions = $weapons
            ->reject(fn (CharacterItem $weapon): bool => $weapon->isMarketListed())
            ->map(fn (CharacterItem $weapon): array => $this->itemPayload($weapon))
            ->values()
            ->all();

        return [
            'engraving_transfer' => [
                'base_options' => $baseOptions,
                'material_options' => $weapons
                    ->filter(fn (CharacterItem $weapon): bool => $weapon->affix_prefix_id !== null)
                    ->map(fn (CharacterItem $weapon): array => $this->itemPayload($weapon))
                    ->values()
                    ->all(),
                'gold_costs' => config('equipment_affix.transfer.gold_costs', []),
            ],
            'slayer_transfer' => [
                'base_options' => $baseOptions,
                'material_options' => $weapons
                    ->filter(fn (CharacterItem $weapon): bool => $weapon->affix_suffix_id !== null)
                    ->map(fn (CharacterItem $weapon): array => $this->itemPayload($weapon))
                    ->values()
                    ->all(),
                'gold_costs' => config('equipment_affix.transfer.gold_costs', []),
            ],
        ];
    }

    /**
     * @return array{message: string, base_character_item_id: int, gold_cost: int}
     */
    public function transfer(Character $character, string $operation, int $baseCharacterItemId, int $materialCharacterItemId): array
    {
        $this->assertOperation($operation);

        if ($baseCharacterItemId === $materialCharacterItemId) {
            throw new RuntimeException('ベース武器と素材武器に同じ武器を選択できません。');
        }

        return DB::transaction(function () use ($character, $operation, $baseCharacterItemId, $materialCharacterItemId) {
            $lockedCharacter = Character::query()->lockForUpdate()->find($character->id);
            if (!$lockedCharacter) {
                throw new RuntimeException('冒険者情報が見つかりません。');
            }
            if ((bool) $lockedCharacter->is_frozen) {
                throw new RuntimeException('凍結中のため、武器の移し操作はできません。');
            }

            $itemIds = [$baseCharacterItemId, $materialCharacterItemId];
            sort($itemIds);
            $items = CharacterItem::query()
                ->where('character_id', $lockedCharacter->id)
                ->whereIn('id', $itemIds)
                ->with(['item', 'affixPrefix', 'affixSuffix'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $base = $items->get($baseCharacterItemId);
            $material = $items->get($materialCharacterItemId);
            if (!$base || !$material) {
                throw new RuntimeException('選択した武器が見つかりません。');
            }

            $this->assertWeaponsCanBeTransferred($base, $material);
            $kind = $this->traitKind($operation);
            $this->assertMaterialHasTrait($material, $kind);
            $sourceLevel = $this->traitLevel($material, $kind);
            $this->assertBaseCanHoldLevel($base, $sourceLevel, $kind);
            $this->assertMeaningfulTransfer($base, $material, $kind);
            $this->assertBaseLockAllowsTransfer($base, $kind);

            $goldCost = $this->transferCost($sourceLevel);
            $beforeSnapshot = $this->snapshot($base);
            $materialSnapshot = $this->snapshot($material);
            $beforeTrait = $this->traitMetadata($base, $kind);
            $afterTrait = $this->traitMetadata($material, $kind);

            $this->goldService->spend(
                $lockedCharacter,
                $goldCost,
                $operation === 'engraving_transfer' ? 'weapon_engraving_transfer' : 'weapon_slayer_transfer',
                $operation === 'engraving_transfer' ? '武器の銘移し' : '武器の特攻移し',
                WeaponTraitOperationLog::class,
                null,
                [
                    'operation' => $operation,
                    'base_character_item_id' => $base->id,
                    'material_character_item_id' => $material->id,
                    'before_' . $kind . '_id' => $beforeTrait['id'],
                    'before_' . $kind . '_level' => $beforeTrait['level'],
                    'after_' . $kind . '_id' => $afterTrait['id'],
                    'after_' . $kind . '_level' => $afterTrait['level'],
                ],
            );

            $this->applyTransferredTrait($base, $material, $kind, $sourceLevel);
            $base->save();
            $base->refresh()->load(['item', 'affixPrefix', 'affixSuffix']);
            $afterSnapshot = $this->snapshot($base);

            $material->delete();

            WeaponTraitOperationLog::create([
                'character_id' => $lockedCharacter->id,
                'operation' => $operation,
                'base_character_item_id' => $base->id,
                'material_character_item_id' => $material->id,
                'before_snapshot' => $beforeSnapshot,
                'material_snapshot' => $materialSnapshot,
                'after_snapshot' => $afterSnapshot,
                'gold_cost' => $goldCost,
            ]);

            return [
                'message' => self::OPERATIONS[$operation] . 'が完了しました。 '
                    . $beforeSnapshot['display_name'] . ' → ' . $afterSnapshot['display_name'] . '。 '
                    . $materialSnapshot['display_name'] . 'を素材として消費し、'
                    . number_format($goldCost) . 'Gを支払った。',
                'base_character_item_id' => (int) $base->id,
                'gold_cost' => $goldCost,
            ];
        }, 3);
    }

    private function assertWeaponsCanBeTransferred(CharacterItem $base, CharacterItem $material): void
    {
        if (($base->item?->type ?? null) !== 'weapon' || ($material->item?->type ?? null) !== 'weapon') {
            throw new RuntimeException('銘・特攻移しは武器同士でのみ行えます。');
        }

        if ($base->isMarketListed() || $material->isMarketListed()) {
            throw new RuntimeException('この武器は冒険者市場へ出品中です。移し操作を行うには、先に出品を取り消してください。');
        }

        if ($material->is_equipped) {
            throw new RuntimeException('この武器は装備中のため素材に使用できません。');
        }

        if ($material->is_locked) {
            throw new RuntimeException('この武器は保護中のため素材に使用できません。');
        }
    }

    private function assertMaterialHasTrait(CharacterItem $material, string $kind): void
    {
        if ($this->traitId($material, $kind) !== null) {
            return;
        }

        throw new RuntimeException($kind === 'engraving'
            ? '素材武器に銘が付いていません。'
            : '素材武器に種族特攻が付いていません。');
    }

    private function assertBaseCanHoldLevel(CharacterItem $base, int $level, string $kind): void
    {
        $maximumLevel = $this->rules->maxLevelForItem($base->item);
        if ($level <= $maximumLevel) {
            return;
        }

        $rank = strtoupper((string) ($base->item?->weapon_rank ?? ''));
        $label = $kind === 'engraving' ? '銘' : '特攻';
        throw new RuntimeException("{$rank}ランク武器が保持できる{$label}段階は{$this->romanLevel($maximumLevel)}までです。");
    }

    private function assertMeaningfulTransfer(CharacterItem $base, CharacterItem $material, string $kind): void
    {
        $baseId = $this->traitId($base, $kind);
        $materialId = $this->traitId($material, $kind);
        if ($baseId === null || $baseId !== $materialId) {
            return;
        }

        if ($this->traitLevel($material, $kind) > $this->traitLevel($base, $kind)) {
            return;
        }

        $label = $kind === 'engraving' ? '銘' : '特攻';
        throw new RuntimeException("同じ{$label}の段階を下げることはできません。");
    }

    private function assertBaseLockAllowsTransfer(CharacterItem $base, string $kind): void
    {
        if (!$base->is_locked || $this->traitId($base, $kind) === null) {
            return;
        }

        $label = $kind === 'engraving' ? '銘' : '特攻';
        throw new RuntimeException("保護中の武器に付いている{$label}は上書きできません。");
    }

    private function applyTransferredTrait(CharacterItem $base, CharacterItem $material, string $kind, int $level): void
    {
        if ($kind === 'engraving') {
            /** @var EquipmentAffixPrefix $prefix */
            $prefix = $material->affixPrefix;
            $bonuses = $this->rules->prefixBonuses($base->item, $prefix, $level, $base->affix_quality);
            $base->forceFill([
                'affix_prefix_id' => $prefix->id,
                'affix_prefix_level' => $level,
                'affix_hp_bonus' => $bonuses['hp'] ?? 0,
                'affix_str_bonus' => $bonuses['str'] ?? 0,
                'affix_def_bonus' => $bonuses['def'] ?? 0,
                'affix_mag_bonus' => $bonuses['mag'] ?? 0,
                'affix_spr_bonus' => $bonuses['spr'] ?? 0,
                'affix_agi_bonus' => $bonuses['agi'] ?? 0,
                'affix_luk_bonus' => $bonuses['luk'] ?? 0,
            ]);

            return;
        }

        /** @var EquipmentAffixSuffix $suffix */
        $suffix = $material->affixSuffix;
        $base->forceFill([
            'affix_suffix_id' => $suffix->id,
            'affix_suffix_level' => $level,
            'killer_species_key' => $suffix->species_key,
            'killer_damage_rate' => $this->rules->weaponKillerDamageRate($base->item, $level, $base->affix_quality),
        ]);
    }

    private function transferCost(int $level): int
    {
        $cost = (int) config('equipment_affix.transfer.gold_costs.' . $level, 0);
        if ($cost <= 0) {
            throw new RuntimeException('移し費用の設定が見つかりません。');
        }

        return $cost;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(CharacterItem $characterItem): array
    {
        return [
            'id' => (int) $characterItem->id,
            'item_name' => (string) ($characterItem->item?->name ?? ''),
            'display_name' => $characterItem->displayName(),
            'display_name_without_rank' => $characterItem->displayName(false),
            'lock_url' => route('equipment.lock', $characterItem),
            'rank' => strtoupper((string) ($characterItem->item?->weapon_rank ?? '-')),
            'weapon_category' => $characterItem->item
                ? (app(EquipmentPermissionService::class)->categoryLabel($characterItem->item) ?? '不明')
                : '不明',
            'quality' => (string) ($characterItem->affix_quality ?: 'normal'),
            'enhance_level' => (int) $characterItem->enhance_level,
            'base_performance_lines' => $characterItem->basePerformanceLines(),
            'engraving_effect_lines' => $characterItem->engravingEffectLines(),
            'slayer_effect_lines' => $characterItem->slayerEffectLines(),
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) $characterItem->is_locked,
            'is_market_listed' => $characterItem->isMarketListed(),
            'maximum_level' => $this->rules->maxLevelForItem($characterItem->item),
            'engraving' => $this->traitPayload($characterItem, 'engraving'),
            'slayer' => $this->traitPayload($characterItem, 'slayer'),
            'warning_lines' => $this->materialWarningLines($characterItem),
        ];
    }

    /**
     * @return array{id: int|null, level: int, label: string}
     */
    private function traitPayload(CharacterItem $characterItem, string $kind): array
    {
        $id = $this->traitId($characterItem, $kind);
        $level = $id === null ? 0 : $this->traitLevel($characterItem, $kind);
        $name = $kind === 'engraving'
            ? $characterItem->affixPrefix?->name
            : $characterItem->affixSuffix?->name;

        return [
            'id' => $id,
            'level' => $level,
            'label' => $name ? $this->withLevel($name, $level) : ($kind === 'engraving' ? '銘なし' : '特攻なし'),
        ];
    }

    /**
     * @return list<string>
     */
    private function materialWarningLines(CharacterItem $material): array
    {
        $warnings = [];
        if (in_array($material->affix_quality, ['good', 'excellent'], true)) {
            $warnings[] = $material->affix_quality === 'excellent' ? '逸品' : '良品';
        }
        if ((int) $material->enhance_level > 0) {
            $warnings[] = '+' . (int) $material->enhance_level;
        }
        if (in_array(strtoupper((string) ($material->item?->weapon_rank ?? '')), ['S', 'SS', 'SSS', 'EPIC'], true)) {
            $warnings[] = strtoupper((string) $material->item->weapon_rank) . 'ランク';
        }
        if ($material->effectiveAffixPrefixLevel() >= 4) {
            $warnings[] = '銘' . $this->romanLevel($material->effectiveAffixPrefixLevel());
        }
        if ($material->effectiveAffixSuffixLevel() >= 4) {
            $warnings[] = '特攻' . $this->romanLevel($material->effectiveAffixSuffixLevel());
        }
        if ($material->acquired_from === 'equipment_market') {
            $warnings[] = '市場購入品';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CharacterItem $characterItem): array
    {
        return [
            'character_item_id' => (int) $characterItem->id,
            'item_id' => (int) $characterItem->item_id,
            'item_name' => (string) ($characterItem->item?->name ?? ''),
            'display_name' => $characterItem->displayName(),
            'weapon_category' => (string) ($characterItem->item?->weapon_category ?? ''),
            'weapon_rank' => strtoupper((string) ($characterItem->item?->weapon_rank ?? '')),
            'quality' => (string) ($characterItem->affix_quality ?: 'normal'),
            'enhance_level' => (int) $characterItem->enhance_level,
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) $characterItem->is_locked,
            'market_relistable_at' => $characterItem->market_relistable_at?->toIso8601String(),
            'engraving_id' => $characterItem->affix_prefix_id,
            'engraving_name' => $characterItem->affixPrefix?->name,
            'engraving_level' => $characterItem->effectiveAffixPrefixLevel(),
            'slayer_type_id' => $characterItem->affix_suffix_id,
            'slayer_name' => $characterItem->affixSuffix?->name,
            'slayer_level' => $characterItem->effectiveAffixSuffixLevel(),
        ];
    }

    /**
     * @return array{id: int|null, level: int}
     */
    private function traitMetadata(CharacterItem $characterItem, string $kind): array
    {
        return [
            'id' => $this->traitId($characterItem, $kind),
            'level' => $this->traitLevel($characterItem, $kind),
        ];
    }

    private function traitKind(string $operation): string
    {
        return $operation === 'engraving_transfer' ? 'engraving' : 'slayer';
    }

    private function traitId(CharacterItem $characterItem, string $kind): ?int
    {
        $column = $kind === 'engraving' ? 'affix_prefix_id' : 'affix_suffix_id';
        return $characterItem->{$column} === null ? null : (int) $characterItem->{$column};
    }

    private function traitLevel(CharacterItem $characterItem, string $kind): int
    {
        return $kind === 'engraving'
            ? $characterItem->effectiveAffixPrefixLevel()
            : $characterItem->effectiveAffixSuffixLevel();
    }

    private function assertOperation(string $operation): void
    {
        if (!array_key_exists($operation, self::OPERATIONS)) {
            throw new RuntimeException('移し種別が不正です。');
        }
    }

    private function withLevel(string $name, int $level): string
    {
        $roman = $this->romanLevel($level);
        if (mb_substr($name, -1) === 'の') {
            return mb_substr($name, 0, -1) . $roman . 'の';
        }

        return $name . $roman;
    }

    private function romanLevel(int $level): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$level] ?? (string) $level;
    }
}
