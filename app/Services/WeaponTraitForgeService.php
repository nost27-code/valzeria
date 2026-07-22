<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\WeaponTraitOperationLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WeaponTraitForgeService
{
    public const OPERATIONS = [
        'engraving_forge' => '銘鍛錬',
        'slayer_forge' => '特攻磨き',
        'dual_forge' => '重ね鍛錬',
    ];

    private const FORGEABLE_ITEM_TYPES = ['weapon', 'armor'];

    public function __construct(
        private readonly EquipmentAffixRulesService $rules,
        private readonly GoldService $goldService,
    ) {
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function candidates(Character $character): array
    {
        $equipments = CharacterItem::query()
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', self::FORGEABLE_ITEM_TYPES))
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->orderByDesc('is_equipped')
            ->orderByDesc('id')
            ->get();

        $candidates = [];
        foreach (array_keys(self::OPERATIONS) as $operation) {
            $candidates[$operation] = $this->candidatesForOperation($equipments, $operation);
        }

        return $candidates;
    }

    /**
     * @return array{message: string, base_character_item_id: int, gold_cost: int}
     */
    public function forge(Character $character, string $operation, int $baseCharacterItemId, int $materialCharacterItemId): array
    {
        $this->assertOperation($operation);

        if ($baseCharacterItemId === $materialCharacterItemId) {
            throw new RuntimeException('同じ装備をベースと素材に選択できません。');
        }

        return DB::transaction(function () use ($character, $operation, $baseCharacterItemId, $materialCharacterItemId) {
            $lockedCharacter = Character::query()->lockForUpdate()->find($character->id);
            if (!$lockedCharacter) {
                throw new RuntimeException('冒険者情報が見つかりません。');
            }

            $items = CharacterItem::query()
                ->where('character_id', $lockedCharacter->id)
                ->whereIn('id', [$baseCharacterItemId, $materialCharacterItemId])
                ->with(['item', 'affixPrefix', 'affixSuffix'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $base = $items->get($baseCharacterItemId);
            $material = $items->get($materialCharacterItemId);
            if (!$base || !$material) {
                throw new RuntimeException('選択した装備が見つかりません。');
            }

            $result = $this->validatePair($base, $material, $operation);
            $beforeSnapshot = $this->snapshot($base);
            $materialSnapshot = $this->snapshot($material);

            $this->goldService->spend(
                $lockedCharacter,
                $result['gold_cost'],
                'weapon_trait_forge',
                $this->operationLabel($operation, $base) . '：' . $base->displayName(),
                WeaponTraitOperationLog::class,
                null,
                [
                    'operation' => $operation,
                    'base_character_item_id' => $base->id,
                    'material_character_item_id' => $material->id,
                    'result_prefix_level' => $result['prefix_level'],
                    'result_suffix_level' => $result['suffix_level'],
                ],
            );

            $fill = [
                'affix_prefix_level' => $result['prefix_level'],
                'affix_suffix_level' => $result['suffix_level'],
            ];
            // 防具の耐性は保存カラムも参照される箇所が残るため、段階更新に合わせて再計算して保存する。
            if (($base->item?->type ?? null) === 'armor' && $base->affix_suffix_id) {
                $fill['species_damage_reduction_rate'] = $this->rules->armorSpeciesResistRate(
                    $base->item,
                    $result['suffix_level'],
                    $base->affix_quality,
                );
            }
            $base->forceFill($fill)->save();
            $qualityUpgrade = in_array($operation, ['engraving_forge', 'slayer_forge'], true)
                ? app(EquipmentAffixService::class)->upgradeQualityAfterWeaponForge($base)
                : null;
            $base->load(['item', 'affixPrefix', 'affixSuffix']);
            $afterSnapshot = $this->snapshot($base);

            $material->delete();

            WeaponTraitOperationLog::create([
                'character_id' => $lockedCharacter->id,
                'operation' => $operation,
                'base_character_item_id' => $base->id,
                'material_character_item_id' => $materialCharacterItemId,
                'before_snapshot' => $beforeSnapshot,
                'material_snapshot' => $materialSnapshot,
                'after_snapshot' => $afterSnapshot,
                'gold_cost' => $result['gold_cost'],
            ]);

            if ($qualityUpgrade === 'excellent') {
                app(PublicLogService::class)->addLog(
                    'drop',
                    "【逸品】{$lockedCharacter->name}さんが鍛冶で「{$base->displayName()}」を逸品に仕上げました！",
                    $lockedCharacter,
                    3,
                );
            }

            return [
                'message' => $this->operationLabel($operation, $base) . 'に成功！ '
                    . $base->displayName() . 'になった。 '
                    . $materialSnapshot['display_name'] . 'を素材として消費し、'
                    . number_format($result['gold_cost']) . 'Gを支払った。'
                    . ($qualityUpgrade === 'good' ? ' 良品に仕上がった！' : ($qualityUpgrade === 'excellent' ? ' 逸品に仕上がった！' : '')),
                'base_character_item_id' => (int) $base->id,
                'gold_cost' => $result['gold_cost'],
            ];
        }, 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidatesForOperation(Collection $equipments, string $operation): array
    {
        $pairs = [];

        foreach ($equipments as $base) {
            foreach ($equipments as $material) {
                if ((int) $base->id === (int) $material->id) {
                    continue;
                }

                try {
                    $result = $this->validatePair($base, $material, $operation);
                } catch (RuntimeException) {
                    continue;
                }

                $pairs[] = [
                    'operation' => $operation,
                    'operation_label' => $this->operationLabel($operation, $base),
                    'base' => $this->itemPayload($base),
                    'material' => $this->itemPayload($material),
                    'result_prefix_level' => $result['prefix_level'],
                    'result_suffix_level' => $result['suffix_level'],
                    'gold_cost' => $result['gold_cost'],
                    'warning_lines' => $this->materialWarningLines($material),
                ];
            }
        }

        usort($pairs, function (array $left, array $right): int {
            return [
                -((int) $left['base']['is_equipped']),
                $left['gold_cost'],
                $left['base']['display_name'],
                $left['material']['display_name'],
            ] <=> [
                -((int) $right['base']['is_equipped']),
                $right['gold_cost'],
                $right['base']['display_name'],
                $right['material']['display_name'],
            ];
        });

        return $pairs;
    }

    /**
     * @return array{prefix_level: int, suffix_level: int, gold_cost: int}
     */
    private function validatePair(CharacterItem $base, CharacterItem $material, string $operation): array
    {
        $this->assertOperation($operation);
        $this->assertEquipmentsCanBeForged($base, $material);

        $prefixLevel = $base->effectiveAffixPrefixLevel();
        $suffixLevel = $base->effectiveAffixSuffixLevel();

        if ($operation === 'engraving_forge' || $operation === 'dual_forge') {
            $this->assertMatchingAffix($base, $material, 'prefix');
            $prefixLevel++;
            $this->assertResultLevelAllowed($base, $prefixLevel, '銘');
        }

        if ($operation === 'slayer_forge' || $operation === 'dual_forge') {
            $this->assertMatchingAffix($base, $material, 'suffix');
            $suffixLevel++;
            $this->assertResultLevelAllowed($base, $suffixLevel, $this->suffixTraitLabel($base));
        }

        if ($operation === 'dual_forge') {
            if ($prefixLevel > 5 || $suffixLevel > 5) {
                throw new RuntimeException('片方が最大段階に到達しているため、重ね鍛錬はできません。');
            }

            $goldCost = (int) floor((
                $this->singleForgeCost($prefixLevel)
                + $this->singleForgeCost($suffixLevel)
            ) * (float) config('equipment_affix.forge.dual_discount_rate', 0.80));
        } elseif ($operation === 'engraving_forge') {
            $goldCost = $this->singleForgeCost($prefixLevel);
        } else {
            $goldCost = $this->singleForgeCost($suffixLevel);
        }

        return [
            'prefix_level' => $prefixLevel,
            'suffix_level' => $suffixLevel,
            'gold_cost' => $goldCost,
        ];
    }

    private function assertEquipmentsCanBeForged(CharacterItem $base, CharacterItem $material): void
    {
        $baseType = $base->item?->type ?? null;
        $materialType = $material->item?->type ?? null;
        if (!in_array($baseType, self::FORGEABLE_ITEM_TYPES, true) || !in_array($materialType, self::FORGEABLE_ITEM_TYPES, true)) {
            throw new RuntimeException('武器または防具同士でのみ鍛錬できます。');
        }

        $label = $this->equipmentLabel($base);
        if ($baseType !== $materialType) {
            throw new RuntimeException('武器と防具をまたいで鍛錬できません。');
        }

        if ($base->isMarketListed()) {
            throw new RuntimeException("ベース{$label}は冒険者市場へ出品中です。先に出品を取り消してください。");
        }

        if ($material->is_equipped) {
            throw new RuntimeException("素材{$label}は装備中です。先に装備を外してください。");
        }

        if ($material->is_locked) {
            throw new RuntimeException("素材{$label}は保護中です。先に保護を解除してください。");
        }

        if ($material->isMarketListed()) {
            throw new RuntimeException("素材{$label}は冒険者市場へ出品中です。先に出品を取り消してください。");
        }

        $categoryColumn = $baseType === 'armor' ? 'armor_category' : 'weapon_category';
        $baseCategory = (string) ($base->item?->{$categoryColumn} ?? '');
        $materialCategory = (string) ($material->item?->{$categoryColumn} ?? '');
        if ($baseCategory === '' || $baseCategory !== $materialCategory) {
            throw new RuntimeException("鍛錬には同じ{$label}種が必要です。");
        }
    }

    private function assertMatchingAffix(CharacterItem $base, CharacterItem $material, string $kind): void
    {
        $idColumn = $kind === 'prefix' ? 'affix_prefix_id' : 'affix_suffix_id';
        $levelMethod = $kind === 'prefix' ? 'effectiveAffixPrefixLevel' : 'effectiveAffixSuffixLevel';
        $label = $kind === 'prefix' ? '銘' : $this->suffixTraitLabel($base);
        $equipmentLabel = $this->equipmentLabel($base);

        if (!$base->{$idColumn} || (int) $base->{$idColumn} !== (int) $material->{$idColumn}) {
            throw new RuntimeException("{$label}鍛錬には、同じ{$label}を持つ{$equipmentLabel}が必要です。");
        }

        if ($base->{$levelMethod}() !== $material->{$levelMethod}()) {
            throw new RuntimeException("{$label}鍛錬には、同じ{$label}段階の{$equipmentLabel}が必要です。");
        }
    }

    private function assertResultLevelAllowed(CharacterItem $base, int $resultLevel, string $label): void
    {
        $equipmentLabel = $this->equipmentLabel($base);
        if ($resultLevel > (int) config('equipment_affix.maximum_level', 5)) {
            throw new RuntimeException("この{$equipmentLabel}の{$label}は最大段階に到達しています。");
        }

        $requiredRank = $this->rules->minimumEquipmentRankForLevel($resultLevel);
        if ($resultLevel > $this->rules->maxLevelForItem($base->item)) {
            throw new RuntimeException("完成後の{$label}" . $this->romanLevel($resultLevel) . "には{$requiredRank}ランク以上の{$equipmentLabel}が必要です。");
        }
    }

    private function singleForgeCost(int $resultLevel): int
    {
        $cost = (int) config('equipment_affix.forge.single_gold_costs.' . $resultLevel, 0);
        if ($cost <= 0) {
            throw new RuntimeException('鍛錬費用の設定が見つかりません。');
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
            'display_name' => $characterItem->displayName(),
            'item_type' => (string) ($characterItem->item?->type ?? ''),
            'rank' => $this->equipmentRank($characterItem),
            'weapon_category' => $characterItem->item
                ? (app(EquipmentPermissionService::class)->categoryLabel($characterItem->item) ?? '不明')
                : '不明',
            'is_equipped' => (bool) $characterItem->is_equipped,
            'is_locked' => (bool) $characterItem->is_locked,
            'enhance_level' => (int) $characterItem->enhance_level,
            'prefix_level' => $characterItem->effectiveAffixPrefixLevel(),
            'suffix_level' => $characterItem->effectiveAffixSuffixLevel(),
            'engraving_lines' => $characterItem->engravingEffectLines(),
            'slayer_lines' => $characterItem->slayerEffectLines(),
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
        if (in_array($this->equipmentRank($material), ['S', 'SS', 'SSS', 'EPIC'], true)) {
            $warnings[] = $this->equipmentRank($material) . 'ランク';
        }
        if ($material->effectiveAffixPrefixLevel() >= 4) {
            $warnings[] = '銘' . $this->romanLevel($material->effectiveAffixPrefixLevel());
        }
        if ($material->effectiveAffixSuffixLevel() >= 4) {
            $warnings[] = $this->suffixTraitLabel($material) . $this->romanLevel($material->effectiveAffixSuffixLevel());
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
            'item_type' => (string) ($characterItem->item?->type ?? ''),
            'display_name' => $characterItem->displayName(),
            'weapon_category' => (string) ($characterItem->item?->weapon_category ?? $characterItem->item?->armor_category ?? ''),
            'weapon_rank' => $this->equipmentRank($characterItem),
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

    private function assertOperation(string $operation): void
    {
        if (!array_key_exists($operation, self::OPERATIONS)) {
            throw new RuntimeException('鍛錬種別が不正です。');
        }
    }

    /**
     * 防具の接尾辞は「特攻」ではなく「耐性」なので、操作名も対象に合わせて出し分ける。
     */
    private function operationLabel(string $operation, CharacterItem $base): string
    {
        if ($operation === 'slayer_forge' && ($base->item?->type ?? null) === 'armor') {
            return '耐性磨き';
        }

        return self::OPERATIONS[$operation] ?? $operation;
    }

    private function equipmentLabel(CharacterItem $characterItem): string
    {
        return ($characterItem->item?->type ?? null) === 'armor' ? '防具' : '武器';
    }

    private function suffixTraitLabel(CharacterItem $characterItem): string
    {
        return ($characterItem->item?->type ?? null) === 'armor' ? '耐性' : '特攻';
    }

    private function equipmentRank(CharacterItem $characterItem): string
    {
        $rank = $characterItem->item?->weapon_rank ?: $characterItem->item?->armor_rank;

        return strtoupper((string) ($rank ?: '-'));
    }

    private function romanLevel(int $level): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$level] ?? (string) $level;
    }
}
