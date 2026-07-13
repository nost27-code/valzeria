<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\EquipmentEvolutionLog;
use App\Models\Item;
use App\Models\Material;
use App\Models\CharacterAreaProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EquipmentEvolutionService
{
    private const STAT_LABELS = [
        'hp_bonus' => 'HP',
        'mp_bonus' => 'SP',
        'str_bonus' => '攻撃',
        'def_bonus' => '防御',
        'agi_bonus' => '敏捷',
        'mag_bonus' => '魔力',
        'spr_bonus' => '精神',
        'luk_bonus' => '運',
    ];

    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const FINE_EQUIPMENT_FRAGMENT_CODE = 'MAT_FINE_EQUIPMENT_FRAGMENT';
    private const STRONG_EQUIPMENT_FRAGMENT_CODE = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
    private const HIDDEN_AREA_MIN_ID = 71;
    private const HIDDEN_AREA_MAX_ID = 74;
    private const AFFIX_INHERIT_COLUMNS = [
        'affix_prefix_id',
        'affix_prefix_level',
        'affix_suffix_id',
        'affix_suffix_level',
        'affix_quality',
        'affix_hp_bonus',
        'affix_str_bonus',
        'affix_def_bonus',
        'affix_mag_bonus',
        'affix_spr_bonus',
        'affix_agi_bonus',
        'affix_luk_bonus',
        'killer_species_key',
        'killer_damage_rate',
        'resist_species_key',
        'species_damage_reduction_rate',
        'affix_generated_at',
    ];
    private const MATERIAL_KIND_WEIGHTS = [
        'early' => ['generic' => 50, 'category' => 20, 'regional' => 25, 'enhance' => 5, 'rare' => 0],
        'middle' => ['generic' => 45, 'category' => 25, 'regional' => 20, 'enhance' => 8, 'rare' => 2],
        'late' => ['generic' => 35, 'category' => 25, 'regional' => 20, 'enhance' => 10, 'rare' => 10],
        'demon_castle' => ['generic' => 28, 'category' => 27, 'regional_high' => 30, 'enhance' => 10, 's_evolution' => 5],
        'back' => ['generic_high' => 35, 'category_high' => 35, 'back' => 25, 'ss_evolution' => 3, 'sss_evolution' => 2],
    ];
    private const SOURCE_MATERIAL_KIND_CODES = [
        'generic' => ['MAT_COMMON_MONSTER_FRAGMENT', 'MAT_COMMON_OLD_BADGE'],
        'generic_high' => ['MAT_COMMON_MAGIC_ORE', 'MAT_COMMON_MONSTER_SHELL', 'MAT_COMMON_BEAST_FANG'],
        'category' => ['MAT_COMMON_MAGIC_ORE', 'MAT_COMMON_BEAST_FUR', 'MAT_COMMON_WING_MEMBRANE', 'MAT_COMMON_FEATHER'],
        'category_high' => ['MAT_COMMON_MONSTER_CORE', 'MAT_COMMON_FAIRY_DUST', 'MAT_COMMON_HOLY_FRAGMENT', 'MAT_COMMON_DARK_CRYSTAL'],
        'enhance' => ['MAT_ENHANCE_FRAGMENT', 'MAT_ENHANCE_STONE', 'MAT_ENHANCE_HIGH_STONE', '5007', '5008', '5009', 'ACC0007', 'ACC0008', 'ACC0009'],
        's_evolution' => ['MAT_BR_WPN_HOLY_PATH'],
        'ss_evolution' => ['MAT_REGION_BLACK_IRON_PART', 'MAT_REGION_ICE_CRYSTAL', 'MAT_REGION_MAGIC_CRYSTAL'],
        'sss_evolution' => ['MAT_REGION_ABYSS_FRAGMENT', 'MAT_REGION_HEAVEN_FEATHER'],
        'back' => ['MAT_REGION_ABYSS_FRAGMENT', 'MAT_REGION_HEAVEN_FEATHER', 'MAT_COMMON_DARK_CRYSTAL'],
    ];

    private array $materialSourceCache = [];

    private const EVOLUTION_STONE_QUANTITIES = [
        'G' => 1,
        'F' => 2,
        'E' => 3,
        'D' => 5,
        'C' => 7,
        'B' => 10,
        'A' => 10,
        'S' => 15,
        'SS' => 25,
        'SSS' => 35,
    ];

    public function __construct(private EquipmentPermissionService $permissionService)
    {
    }

    public function candidates(Character $character): array
    {
        $ownedMaterials = $this->ownedMaterialMap($character);
        $discoveredItemIds = $this->discoveredItemIds($character);
        $candidates = [];

        $weaponRecipes = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($weaponRecipes as $recipe) {
            $candidates[] = $this->buildWeaponCandidate($character, $recipe, $ownedMaterials, $discoveredItemIds);
        }

        $armorRecipes = DB::table('armor_evolution_recipes')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($armorRecipes as $recipe) {
            $candidates[] = $this->buildArmorCandidate($character, $recipe, $ownedMaterials, $discoveredItemIds);
        }

        if (DB::getSchemaBuilder()->hasTable('accessory_evolution_recipes')) {
            $accessoryRecipes = DB::table('accessory_evolution_recipes')
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            foreach ($accessoryRecipes as $recipe) {
                $candidates[] = $this->buildAccessoryCandidate($character, $recipe, $ownedMaterials, $discoveredItemIds);
            }
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn (array $candidate) => $candidate['owned_source_count'] > 0
        ));

        usort($candidates, function (array $a, array $b): int {
            return [
                $a['has_equipped_source'] ? 0 : 1,
                -$a['rank_sort'],
                $a['can_evolve'] ? 0 : 1,
                $a['can_equip_source'] ? 0 : 1,
                $a['sort_status'],
                $a['missing_total'],
                $a['equipment_sort'],
                $a['from_name'],
            ] <=> [
                $b['has_equipped_source'] ? 0 : 1,
                -$b['rank_sort'],
                $b['can_evolve'] ? 0 : 1,
                $b['can_equip_source'] ? 0 : 1,
                $b['sort_status'],
                $b['missing_total'],
                $b['equipment_sort'],
                $b['from_name'],
            ];
        });

        return $candidates;
    }

    public function evolve(Character $character, string $recipeType, string $recipeId, ?int $sourceCharacterItemId = null): array
    {
        if (!in_array($recipeType, ['weapon', 'armor', 'accessory'], true)) {
            throw new RuntimeException('合成種別が不正です。');
        }

        return DB::transaction(function () use ($character, $recipeType, $recipeId, $sourceCharacterItemId) {
            $recipe = $this->findRecipeForUpdate($recipeType, $recipeId);
            $ownedMaterials = $this->ownedMaterialMap($character);
            $candidate = match ($recipeType) {
                'weapon' => $this->buildWeaponCandidate($character, $recipe, $ownedMaterials, $this->discoveredItemIds($character)),
                'armor' => $this->buildArmorCandidate($character, $recipe, $ownedMaterials, $this->discoveredItemIds($character)),
                'accessory' => $this->buildAccessoryCandidate($character, $recipe, $ownedMaterials, $this->discoveredItemIds($character)),
            };

            if (!$candidate['can_evolve']) {
                throw new RuntimeException($candidate['unavailable_reason'] ?: '合成条件を満たしていません。');
            }

            $goldCost = (int) ($candidate['gold_cost'] ?? 0);
            if ($goldCost > 0) {
                app(GoldService::class)->spend(
                    $character,
                    $goldCost,
                    'equipment_evolution',
                    "{$candidate['from_name']}から{$candidate['to_name']}への合成",
                    null,
                    null,
                    [
                        'recipe_type' => $recipeType,
                        'recipe_id' => $candidate['recipe_id'],
                        'from_rank' => $candidate['from_rank'],
                        'to_rank' => $candidate['to_rank'],
                    ]
                );
            }

            $usesEvolutionStone = !($candidate['can_use_extra_equipment'] ?? false);
            $materialRequirements = $candidate['required_materials'];
            if ($usesEvolutionStone && !empty($candidate['evolution_stone_requirement'])) {
                $materialRequirements[] = $candidate['evolution_stone_requirement'];
            }

            $materialRows = $this->lockRequiredMaterials($character, $materialRequirements);
            $consumedItems = $this->lockEvolutionSourceEquipment(
                $character,
                (int) $candidate['from_item']->id,
                (int) $candidate['equipment_consume_count'],
                $sourceCharacterItemId
            );

            if ($consumedItems->count() < $candidate['equipment_consume_count']) {
                throw new RuntimeException($usesEvolutionStone ? '進化元の装備が不足しています。' : '素材にできる同名装備が不足しています。');
            }
            if ($consumedItems->contains(fn (CharacterItem $item): bool => $item->isMarketListed())) {
                throw new RuntimeException('この武器は冒険者市場へ出品中です。操作するには先に出品を取り消してください。');
            }

            $equippedConsumed = $consumedItems->firstWhere('is_equipped', true);
            $equippedSlot = $equippedConsumed?->equipped_slot;
            $sourceWasLocked = $consumedItems->contains(fn (CharacterItem $item): bool => (bool) $item->is_locked);
            $selectedSource = $sourceCharacterItemId !== null
                ? $consumedItems->firstWhere('id', $sourceCharacterItemId)
                : $consumedItems->first();
            $sourceEnhanceLevel = (int) ($selectedSource?->enhance_level ?? 0);
            $inheritedEnhanceLevel = min(
                $sourceEnhanceLevel,
                app(EquipmentEnhancementService::class)->maxEnhanceFor($candidate['to_item'])
            );
            $affixSource = $this->selectAffixInheritanceSource($consumedItems);
            $inheritedAffixes = $affixSource ? $this->affixInheritancePayload($affixSource) : [];
            $consumedEquipmentName = $consumedItems->first()?->displayName() ?? $candidate['from_name'];
            CharacterItem::whereIn('id', $consumedItems->pluck('id'))->delete();

            $consumedMaterials = [];
            foreach ($materialRequirements as $materialRequirement) {
                if (!$materialRequirement['is_consumed']) {
                    continue;
                }

                $row = $materialRows[$materialRequirement['material_code']] ?? null;
                if (!$row) {
                    throw new RuntimeException($materialRequirement['name'] . ' が不足しています。');
                }

                $row->quantity -= $materialRequirement['required'];
                if ($row->quantity <= 0) {
                    $row->delete();
                } else {
                    $row->save();
                }

                $consumedMaterials[] = [
                    'material_code' => $materialRequirement['material_code'],
                    'name' => $materialRequirement['name'],
                    'quantity' => $materialRequirement['required'],
                ];
            }

            $created = CharacterItem::create(array_merge([
                'character_id' => $character->id,
                'item_id' => $candidate['to_item']->id,
                'is_equipped' => $equippedSlot !== null,
                'is_stored' => false,
                'is_locked' => $sourceWasLocked,
                'enhance_level' => $inheritedEnhanceLevel,
                'equipped_slot' => $equippedSlot,
                'acquired_from' => 'evolution',
                'market_relistable_at' => $consumedItems->max('market_relistable_at'),
            ], $inheritedAffixes));

            EquipmentEvolutionLog::create([
                'character_id' => $character->id,
                'recipe_type' => $recipeType,
                'recipe_id' => $candidate['recipe_id'],
                'before_equipment_id' => $candidate['from_item']->id,
                'after_equipment_id' => $candidate['to_item']->id,
                'consumed_equipment_count' => $candidate['equipment_consume_count'],
                'consumed_materials' => $consumedMaterials,
                'created_equipment_instance_id' => $created->id,
            ]);

            return [
                'message' => "{$consumedEquipmentName}を合成して、{$candidate['to_name']}を手に入れました！"
                    . ($goldCost > 0 ? ' 合成費用として' . number_format($goldCost) . 'Gを支払いました。' : '')
                    . ($equippedSlot ? ' 装備中だったため、そのまま装備しました。' : '')
                    . ($sourceWasLocked ? ' 保護状態も引き継ぎました。' : '')
                    . ($affixSource ? ' 銘も引き継ぎました。' : '')
                    . ($sourceEnhanceLevel > 0 ? " 進化元の+{$sourceEnhanceLevel}強化値を+{$inheritedEnhanceLevel}として引き継ぎました。" : ''),
                'created_equipment_id' => $created->id,
            ];
        }, 3);
    }

    private function selectAffixInheritanceSource(Collection $consumedItems): ?CharacterItem
    {
        return $consumedItems
            ->filter(fn (CharacterItem $item): bool => $this->hasInheritableAffix($item))
            ->sort(function (CharacterItem $a, CharacterItem $b): int {
                return [$this->affixQualityRank($b), (int) $b->is_equipped, -((int) ($b->enhance_level ?? 0)), -((int) ($b->id ?? 0))]
                    <=> [$this->affixQualityRank($a), (int) $a->is_equipped, -((int) ($a->enhance_level ?? 0)), -((int) ($a->id ?? 0))];
            })
            ->first();
    }

    private function hasInheritableAffix(CharacterItem $item): bool
    {
        return $item->hasAffix()
            || in_array($item->affix_quality, ['good', 'excellent'], true);
    }

    private function affixQualityRank(CharacterItem $item): int
    {
        return match ($item->affix_quality) {
            'excellent' => 3,
            'good' => 2,
            default => $item->hasAffix() ? 1 : 0,
        };
    }

    private function affixInheritancePayload(CharacterItem $item): array
    {
        $payload = [];
        foreach (self::AFFIX_INHERIT_COLUMNS as $column) {
            $payload[$column] = $item->{$column};
        }

        return $payload;
    }

    private function buildWeaponCandidate(Character $character, object $recipe, array $ownedMaterials, array $discoveredItemIds): array
    {
        $fromItem = $this->findActiveItem('weapon', $recipe->from_weapon_id);
        $toItem = $this->findActiveItem('weapon', $recipe->to_weapon_id);
        $ingredients = DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $recipe->recipe_id)
            ->get();

        $requiredEquipmentCount = 1;

        $materials = [];
        foreach ($ingredients as $ingredient) {
            if ($ingredient->ingredient_type === 'same_weapon') {
                continue;
            }

            [$materialCode, $materialName] = $this->resolveWeaponMaterialRequirement(
                (string) $ingredient->ingredient_id,
                $ingredient->ingredient_name,
                $character,
                $recipe,
                $fromItem,
                $toItem,
                (string) $recipe->from_rank
            );

            $materials[] = $this->materialRequirement(
                $materialCode,
                $materialName,
                $this->relaxedMaterialQuantity((string) $recipe->from_rank, (int) $ingredient->quantity),
                (bool) $ingredient->is_consumed,
                $ownedMaterials
            );
        }

        return $this->candidatePayload(
            $character,
            'weapon',
            $recipe->recipe_id,
            '武器',
            $fromItem,
            $toItem,
            $recipe->from_weapon_name,
            $recipe->to_weapon_name,
            $recipe->from_rank,
            $recipe->to_rank,
            $requiredEquipmentCount,
            $materials,
            $ownedMaterials,
            $this->recipeUnlockOk($character, $recipe),
            $this->recipeUnlockReason($character, $recipe),
            $discoveredItemIds
        );
    }

    private function buildArmorCandidate(Character $character, object $recipe, array $ownedMaterials, array $discoveredItemIds): array
    {
        $fromItem = $this->findActiveItem('armor', (string) $recipe->source_armor_id);
        $toItem = $this->findActiveItem('armor', (string) $recipe->target_armor_id);
        $ingredients = DB::table('armor_evolution_recipe_ingredients')
            ->where('evolution_recipe_id', $recipe->evolution_recipe_id)
            ->get();

        $materials = [];
        foreach ($ingredients as $ingredient) {
            [$materialCode, $materialName] = $this->resolveArmorMaterialRequirement(
                (string) $ingredient->material_id,
                $ingredient->material_name,
                $character,
                $recipe,
                $fromItem,
                $toItem,
                (string) $recipe->from_rank
            );

            $materials[] = $this->materialRequirement(
                $materialCode,
                $materialName,
                $this->relaxedMaterialQuantity((string) $recipe->from_rank, (int) $ingredient->required_quantity),
                true,
                $ownedMaterials
            );
        }

        $unlockOk = $this->recipeUnlockOk($character, $recipe);

        return $this->candidatePayload(
            $character,
            'armor',
            $recipe->evolution_recipe_id,
            '防具',
            $fromItem,
            $toItem,
            $recipe->source_armor_name,
            $recipe->target_armor_name,
            $recipe->from_rank,
            $recipe->to_rank,
            (int) $recipe->required_same_armor_count,
            $materials,
            $ownedMaterials,
            $unlockOk,
            $this->recipeUnlockReason($character, $recipe),
            $discoveredItemIds
        );
    }

    private function buildAccessoryCandidate(Character $character, object $recipe, array $ownedMaterials, array $discoveredItemIds): array
    {
        $fromItem = $this->findActiveItem('accessory', (string) $recipe->from_accessory_id);
        $toItem = $this->findActiveItem('accessory', (string) $recipe->to_accessory_id);
        $ingredients = DB::table('accessory_evolution_recipe_ingredients')
            ->where('recipe_id', $recipe->recipe_id)
            ->get();

        $materials = [];
        foreach ($ingredients as $ingredient) {
            if ($ingredient->ingredient_type === 'same_accessory') {
                continue;
            }

            [$materialCode, $materialName] = $this->resolveAccessoryMaterialRequirement(
                (string) $ingredient->material_code,
                (string) $ingredient->material_name,
                $character,
                $recipe,
                $fromItem,
                $toItem,
                (string) $recipe->from_rank
            );

            $materials[] = $this->materialRequirement(
                $materialCode,
                $materialName,
                $this->relaxedMaterialQuantity((string) $recipe->from_rank, (int) $ingredient->required_quantity),
                (bool) $ingredient->is_consumed,
                $ownedMaterials
            );
        }

        $unlockOk = $this->recipeUnlockOk($character, $recipe);

        return $this->candidatePayload(
            $character,
            'accessory',
            $recipe->recipe_id,
            '装飾品',
            $fromItem,
            $toItem,
            $recipe->from_accessory_name,
            $recipe->to_accessory_name,
            $recipe->from_rank,
            $recipe->to_rank,
            (int) $recipe->required_same_accessory_count,
            $materials,
            $ownedMaterials,
            $unlockOk,
            $this->recipeUnlockReason($character, $recipe),
            $discoveredItemIds
        );
    }

    private function candidatePayload(
        Character $character,
        string $type,
        string $recipeId,
        string $typeLabel,
        ?Item $fromItem,
        ?Item $toItem,
        string $fromName,
        string $toName,
        ?string $fromRank,
        ?string $toRank,
        int $requiredEquipmentCount,
        array $materials,
        array $ownedMaterials,
        bool $unlockOk,
        ?string $unlockReason = null,
        array $discoveredItemIds = []
    ): array {
        $sourceItems = $fromItem
            ? $this->evolutionSourceEquipmentQuery($character, $fromItem->id)
                ->with(['item', 'affixPrefix', 'affixSuffix'])
                ->get()
            : collect();
        $ownedEquipmentCount = $sourceItems->count();
        $ownedEnhancedEquipmentCount = $sourceItems->where('enhance_level', '>', 0)->count();
        $ownedSourceCount = $ownedEquipmentCount;
        $hasEquippedSource = $sourceItems->contains(fn (CharacterItem $item): bool => (bool) $item->is_equipped);
        $toIsDiscovered = $toItem && in_array((int) $toItem->id, $discoveredItemIds, true);
        $canEquipSource = $fromItem ? $this->permissionService->canEquip($character, $fromItem) : true;
        $goldCost = app(GoldService::class)->evolutionCost($toRank);
        $ownedGold = (int) ($character->money ?? 0);
        $missingGold = max(0, $goldCost - $ownedGold);

        $requiredBaseEquipmentCount = 1;
        $requiredExtraEquipmentCount = 0;
        $ownedExtraEquipmentCount = 0;
        $canUseExtraEquipment = false;
        $evolutionStoneRequirement = null;
        $canUseEvolutionStone = $evolutionStoneRequirement !== null && $evolutionStoneRequirement['owned'] >= $evolutionStoneRequirement['required'];
        if ($evolutionStoneRequirement === null) {
            $canUseEvolutionStone = true;
        }

        $missingBaseEquipment = max(0, $requiredBaseEquipmentCount - $ownedEquipmentCount);
        $missingExtraEquipment = 0;
        $missingEvolutionStone = $evolutionStoneRequirement ? $evolutionStoneRequirement['missing'] : $requiredExtraEquipmentCount;
        $missingAlternative = $canUseEvolutionStone
            ? 0
            : $missingEvolutionStone;
        $missingMaterials = 0;
        foreach ($materials as $material) {
            $missingMaterials += max(0, $material['required'] - $material['owned']);
        }

        $unavailableReason = null;
        if (!$fromItem) {
            $unavailableReason = '進化元の装備マスタが見つかりません。';
        } elseif (!$toItem) {
            $unavailableReason = '進化先の装備マスタが見つかりません。';
        } elseif (!$unlockOk) {
            $unavailableReason = $unlockReason ?: '解放条件を満たしていません。';
        } elseif ($missingBaseEquipment > 0) {
            $unavailableReason = '進化元の装備が不足しています。';
        } elseif ($evolutionStoneRequirement !== null && !$canUseEvolutionStone) {
            $unavailableReason = '装備の欠片が不足しています。';
        } elseif ($missingMaterials > 0) {
            $unavailableReason = '素材が不足しています。';
        } elseif ($missingGold > 0) {
            $unavailableReason = 'Goldが不足しています。';
        }

        $toDisplayName = $toIsDiscovered
            ? ($toItem?->name ?? $toName)
            : $this->undiscoveredName($type, $toItem, $toRank);
        $sourceOptions = $this->sourceOptionPayloads($sourceItems, $toItem, $toDisplayName);
        $singleSourceOption = count($sourceOptions) === 1 ? $sourceOptions[0] : null;
        $canEvolve = $unavailableReason === null;
        $missingTotal = $missingBaseEquipment + $missingAlternative + $missingMaterials + $missingGold;
        $equipmentConsumeCount = $requiredBaseEquipmentCount;

        return [
            'recipe_id' => $recipeId,
            'equipment_type' => $type,
            'equipment_type_label' => $typeLabel,
            'from_item' => $fromItem,
            'to_item' => $toItem,
            'from_equipment_id' => $fromItem?->id,
            'to_equipment_id' => $toItem?->id,
            'from_name' => $fromItem?->name ?? $fromName,
            'to_name' => $toItem?->name ?? $toName,
            'from_display_name' => $singleSourceOption['display_name'] ?? ($fromItem?->name ?? $fromName),
            'to_display_name' => $toDisplayName,
            'to_preview_display_name' => $singleSourceOption['evolved_display_name'] ?? null,
            'to_is_discovered' => $toIsDiscovered,
            'from_rank' => $fromRank,
            'to_rank' => $toRank,
            'required_equipment_count' => $requiredEquipmentCount,
            'required_base_equipment_count' => $requiredBaseEquipmentCount,
            'required_extra_equipment_count' => $requiredExtraEquipmentCount,
            'owned_equipment_count' => $ownedEquipmentCount,
            'owned_enhanced_equipment_count' => $ownedEnhancedEquipmentCount,
            'owned_extra_equipment_count' => $ownedExtraEquipmentCount,
            'owned_source_count' => $ownedSourceCount,
            'source_options' => $sourceOptions,
            'has_equipped_source' => $hasEquippedSource,
            'can_equip_source' => $canEquipSource,
            'missing_equipment_count' => $missingBaseEquipment,
            'missing_extra_equipment_count' => $missingExtraEquipment,
            'evolution_stone_requirement' => $evolutionStoneRequirement,
            'can_use_extra_equipment' => $canUseExtraEquipment,
            'can_use_evolution_stone' => $canUseEvolutionStone,
            'equipment_consume_count' => $equipmentConsumeCount,
            'required_materials' => $materials,
            'gold_cost' => $goldCost,
            'owned_gold' => $ownedGold,
            'missing_gold' => $missingGold,
            'can_evolve' => $canEvolve,
            'unavailable_reason' => $unavailableReason,
            'stat_changes' => $this->statChanges($fromItem, $toItem),
            'sort_status' => $canEvolve ? 0 : ($missingTotal <= 2 && $unlockOk ? 1 : ($unlockOk ? 2 : 3)),
            'missing_total' => $missingTotal,
            'equipment_sort' => (int) ($fromItem?->sort_order ?? 999999),
            'rank_sort' => $this->rankSort((string) $fromRank),
        ];
    }

    private function sourceOptionPayloads(Collection $sourceItems, ?Item $toItem = null, ?string $toDisplayName = null): array
    {
        $goldService = app(GoldService::class);

        return $sourceItems
            ->map(function (CharacterItem $item) use ($toItem, $toDisplayName, $goldService): array {
                return [
                    'id' => (int) $item->id,
                    'display_name' => $item->displayName(),
                    'display_name_without_rank' => $item->displayName(false),
                    'evolved_display_name' => $this->evolvedSourceDisplayName($item, $toItem, $toDisplayName),
                    'is_equipped' => (bool) $item->is_equipped,
                    'is_locked' => (bool) $item->is_locked,
                    'can_sell' => $goldService->canSellEquipment($item),
                    'sell_price' => $goldService->equipmentSalePrice($item->item),
                    'enhance_level' => (int) ($item->enhance_level ?? 0),
                    'total_stat_value' => array_sum(EquipmentEnhancementService::enhancedStatTotalsForItem(
                        $item->item,
                        (int) ($item->enhance_level ?? 0),
                    )) + array_sum($item->affixStatBonuses()),
                    'inherited_enhance_level' => $toItem
                        ? min((int) ($item->enhance_level ?? 0), app(EquipmentEnhancementService::class)->maxEnhanceFor($toItem))
                        : 0,
                    'has_affix' => $this->hasInheritableAffix($item),
                    'affix_lines' => $item->affixEffectLines(),
                ];
            })
            ->values()
            ->all();
    }

    private function evolvedSourceDisplayName(CharacterItem $source, ?Item $toItem, ?string $toDisplayName = null): ?string
    {
        if (!$toItem) {
            return null;
        }

        $preview = new CharacterItem(array_merge(
            $this->affixInheritancePayload($source),
            ['enhance_level' => min((int) ($source->enhance_level ?? 0), app(EquipmentEnhancementService::class)->maxEnhanceFor($toItem))]
        ));
        $preview->setRelation('item', new Item(['name' => $toDisplayName ?: $toItem->name]));

        if ($source->relationLoaded('affixPrefix')) {
            $preview->setRelation('affixPrefix', $source->affixPrefix);
        }

        if ($source->relationLoaded('affixSuffix')) {
            $preview->setRelation('affixSuffix', $source->affixSuffix);
        }

        return $preview->displayName();
    }

    private function rankSort(string $rank): int
    {
        return match (strtoupper($rank)) {
            'EPIC' => 10,
            'SSS' => 9,
            'SS' => 8,
            'S' => 7,
            'A' => 6,
            'B' => 5,
            'C' => 4,
            'D' => 3,
            'E' => 2,
            'F' => 1,
            'G' => 0,
            default => -1,
        };
    }

    private function statChanges(?Item $fromItem, ?Item $toItem): array
    {
        if (!$fromItem || !$toItem) {
            return [];
        }

        $rows = [];
        foreach (self::STAT_LABELS as $field => $label) {
            $from = (int) ($fromItem->{$field} ?? 0);
            $to = (int) ($toItem->{$field} ?? 0);
            $diff = $to - $from;

            if ($from === 0 && $to === 0) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'from' => $from,
                'to' => $to,
                'diff' => $diff,
            ];
        }

        return $rows;
    }

    private function materialRequirement(string $code, string $fallbackName, int $required, bool $isConsumed, array $ownedMaterials): array
    {
        $owned = $ownedMaterials[$code]['quantity'] ?? 0;
        $name = $ownedMaterials[$code]['name'] ?? $fallbackName;

        return [
            'material_code' => $code,
            'name' => $name,
            'icon_image' => Material::iconImagePathFor($code, $name),
            'required' => $required,
            'owned' => $owned,
            'missing' => max(0, $required - $owned),
            'is_consumed' => $isConsumed,
            'sources' => $this->materialSources($code),
        ];
    }

    private function materialSources(string $code): array
    {
        if (array_key_exists($code, $this->materialSourceCache)) {
            return $this->materialSourceCache[$code];
        }

        $material = Material::where('material_code', $code)->first();
        if (!$material) {
            return $this->materialSourceCache[$code] = ['素材マスタ未登録'];
        }

        if (str_contains((string) $material->name, '古代片') || str_contains((string) $material->name, '古代装飾片')) {
            return $this->materialSourceCache[$code] = [$this->sourcePayload('未実装')];
        }

        $sources = [];
        $dropRows = DB::table('material_drops')
            ->join('enemies', 'material_drops.enemy_id', '=', 'enemies.id')
            ->join('areas', 'enemies.area_id', '=', 'areas.id')
            ->leftJoin('cities', 'areas.city_id', '=', 'cities.id')
            ->where('material_drops.material_id', $material->id)
            ->where('material_drops.is_active', true)
            ->where('material_drops.drop_rate', '>', 0)
            ->where(function ($query) {
                $query->where(function ($normalEnemy) {
                    $normalEnemy->where('enemies.is_boss', false)
                        ->where(function ($roleQuery) {
                            $roleQuery->whereNull('enemies.role')
                                ->orWhere('enemies.role', 'not like', '%ボス%');
                        });
                })
                    ->orWhere('material_drops.drop_first_clear_only', true);
            })
            ->select(
                'areas.id as area_id',
                'areas.name as area_name',
                'areas.sort_order as area_sort_order',
                'enemies.id as enemy_id',
                'enemies.is_boss as enemy_is_boss',
                'enemies.role as enemy_role',
                'cities.id as city_id',
                'cities.name as city_name',
                'cities.sort_order as city_sort_order',
                'material_drops.drop_rate',
                'material_drops.drop_first_clear_only',
                'material_drops.drop_timing'
            )
            ->orderBy('areas.sort_order')
            ->get();

        foreach ($dropRows->groupBy('area_id') as $areaId => $rows) {
            $areaName = (string) ($rows->first()->area_name ?? '');
            $cityName = (string) ($rows->first()->city_name ?? '');
            $repeatRows = $rows
                ->filter(fn ($row): bool => !((bool) ($row->drop_first_clear_only ?? false) || $this->isSourceBoss($row)))
                ->values();
            $rates = $repeatRows
                ->map(fn ($row): ?float => $this->effectiveMaterialDropRate($row, $material))
                ->filter(fn (?float $rate): bool => $rate !== null)
                ->values();
            $rateText = $rates->isNotEmpty()
                ? $this->materialSourceRateLabel((float) $rates->min(), (float) $rates->max())
                : ($rows->contains(fn ($row): bool => (bool) ($row->drop_first_clear_only ?? false)) ? '初回確定' : '入手率変動');
            $sources[] = $this->sourcePayload(
                trim(($cityName !== '' ? $cityName . ' / ' : '') . $areaName . '（' . $rateText . '）'),
                (int) $areaId,
                $material
            );
        }

        $method = trim((string) ($material->obtain_method ?? ''));
        if ($method !== '') {
            $sources[] = $this->sourcePayload($method);
        }

        foreach ($this->fallbackMaterialSources($material) as $source) {
            $sources[] = $this->sourcePayload($source);
        }

        $sources = array_values(array_filter($sources, fn (array $source): bool => ($source['label'] ?? '') !== ''));
        $sources = $this->removeRedundantSources($sources);
        if (empty($sources)) {
            $sources[] = $this->sourcePayload('通常探索・報酬で入手');
        }

        return $this->materialSourceCache[$code] = array_slice($sources, 0, 6);
    }

    private function fallbackMaterialSources(Material $material): array
    {
        $code = (string) $material->material_code;
        $name = (string) $material->name;
        $sources = [];

        if (in_array($code, [
            self::EQUIPMENT_FRAGMENT_CODE,
            self::FINE_EQUIPMENT_FRAGMENT_CODE,
            self::STRONG_EQUIPMENT_FRAGMENT_CODE,
        ], true)) {
            $sources[] = '不要装備の分解';
            $sources[] = '素材交換所で上位変換';
        }

        if ($code === self::EQUIPMENT_FRAGMENT_CODE) {
            $sources[] = '通常敵・低〜中ランク装備の分解';
        } elseif ($code === self::FINE_EQUIPMENT_FRAGMENT_CODE) {
            $sources[] = '強敵・レア敵・C/Bランク装備の分解';
        } elseif ($code === self::STRONG_EQUIPMENT_FRAGMENT_CODE) {
            $sources[] = 'ボス級・高難度探索・A/Sランク装備の分解';
        }

        if (str_contains($name, '秘境晶片')) {
            $sources[] = '秘境採取で入手';
        } elseif (str_contains($name, '秘境晶')) {
            $sources[] = '秘境採取で低確率入手';
        } elseif (str_contains($name, '極印')) {
            $sources[] = 'Phase 3予定: 極印試練で入手';
        }

        return $sources;
    }

    private function materialSourceRateLabel(float $minRate, float $maxRate): string
    {
        $minRate = max(0.0, $minRate);
        $maxRate = max($minRate, $maxRate);

        if (abs($maxRate - $minRate) < 0.005) {
            return '基礎約' . $this->formatSourceRate($maxRate);
        }

        return '基礎約' . $this->formatSourceRate($minRate) . '-' . $this->formatSourceRate($maxRate);
    }

    private function effectiveMaterialDropRate(object $row, Material $material): ?float
    {
        $dropRate = (float) ($row->drop_rate ?? 0);
        if ($dropRate <= 0) {
            return null;
        }

        $band = $this->sourceMaterialBand($row);
        $weights = self::MATERIAL_KIND_WEIGHTS[$band] ?? self::MATERIAL_KIND_WEIGHTS['middle'];
        $kind = $this->sourceMaterialKind($material, $band);
        $kindWeight = (float) ($weights[$kind] ?? 0);
        $totalKindWeight = array_sum(array_map(fn ($weight) => max(0, (float) $weight), $weights));
        if ($kindWeight <= 0 || $totalKindWeight <= 0) {
            return null;
        }

        $sameKindWeight = $this->sameKindMaterialDropWeight((int) $row->enemy_id, $kind, $band);
        if ($sameKindWeight <= 0) {
            return null;
        }

        return $this->sourceMaterialBaseRate($row)
            * ($kindWeight / $totalKindWeight)
            * ($dropRate / $sameKindWeight);
    }

    private function sameKindMaterialDropWeight(int $enemyId, string $kind, string $band): float
    {
        return DB::table('material_drops')
            ->join('materials', 'material_drops.material_id', '=', 'materials.id')
            ->where('material_drops.enemy_id', $enemyId)
            ->where('material_drops.is_active', true)
            ->where('material_drops.drop_first_clear_only', false)
            ->where('material_drops.drop_rate', '>', 0)
            ->select(
                'materials.material_code',
                'materials.name',
                'materials.material_type',
                'materials.rank_tier',
                'materials.city_id',
                'material_drops.drop_rate'
            )
            ->get()
            ->filter(fn ($drop): bool => $this->sourceMaterialKindFromRow($drop, $band) === $kind)
            ->sum(fn ($drop): float => max(0.01, (float) $drop->drop_rate));
    }

    private function sourceMaterialKind(Material $material, string $band): string
    {
        return $this->sourceMaterialKindFromRow((object) [
            'material_code' => $material->material_code,
            'name' => $material->name,
            'material_type' => $material->material_type,
            'rank_tier' => $material->rank_tier,
            'city_id' => $material->city_id,
        ], $band);
    }

    private function sourceMaterialKindFromRow(object $material, string $band): string
    {
        $code = (string) ($material->material_code ?? '');
        $name = (string) ($material->name ?? '');
        $type = (string) ($material->material_type ?? '');
        $tier = (int) ($material->rank_tier ?? 1);
        $cityId = $material->city_id !== null ? (int) $material->city_id : null;

        if (str_contains($type, 'enhance') || str_contains($name, '強化') || str_contains($name, '守護石')) {
            return 'enhance';
        }

        foreach (self::SOURCE_MATERIAL_KIND_CODES as $kind => $codes) {
            if (in_array($code, $codes, true) && isset(self::MATERIAL_KIND_WEIGHTS[$band][$kind])) {
                return $kind;
            }
        }

        if ($cityId !== null) {
            return isset(self::MATERIAL_KIND_WEIGHTS[$band]['regional'])
                ? 'regional'
                : 'regional_high';
        }

        if ($tier >= 2 || str_contains($name, '結晶') || str_contains($name, '核')) {
            return isset(self::MATERIAL_KIND_WEIGHTS[$band]['rare'])
                ? 'rare'
                : (isset(self::MATERIAL_KIND_WEIGHTS[$band]['category_high']) ? 'category_high' : 'generic_high');
        }

        return isset(self::MATERIAL_KIND_WEIGHTS[$band]['category']) ? 'category' : 'generic_high';
    }

    private function sourceMaterialBaseRate(object $row): float
    {
        if ($this->isSourceBackDungeon($row)) {
            return 65.0;
        }

        if ($this->isSourceGrassland($row)) {
            return 40.0;
        }

        return match ($this->sourceCityTier($row)) {
            1, 2, 3, 4 => 50.0,
            5, 6, 7 => 55.0,
            default => 60.0,
        };
    }

    private function sourceMaterialBand(object $row): string
    {
        if ($this->isSourceBackDungeon($row)) {
            return 'back';
        }

        return match ($this->sourceCityTier($row)) {
            1, 2 => 'early',
            3, 4 => 'middle',
            10 => 'demon_castle',
            default => 'late',
        };
    }

    private function sourceCityTier(object $row): int
    {
        $cityId = (int) ($row->city_id ?? 0);
        if ($cityId >= 1 && $cityId <= 10) {
            return $cityId;
        }

        $cityOrder = (int) ($row->city_sort_order ?? 0);
        if ($cityOrder >= 1 && $cityOrder <= 10) {
            return $cityOrder;
        }

        $areaOrder = (int) ($row->area_sort_order ?? $row->area_id ?? 1);
        return max(1, min(10, (int) ceil($areaOrder / 7)));
    }

    private function isSourceBackDungeon(object $row): bool
    {
        return (int) ($row->area_id ?? 0) >= 71
            || str_contains((string) ($row->area_name ?? ''), '裏');
    }

    private function isSourceGrassland(object $row): bool
    {
        return (int) ($row->area_id ?? 0) === 1
            || str_contains((string) ($row->area_name ?? ''), 'はじまりの草原');
    }

    private function isSourceBoss(object $row): bool
    {
        return (bool) ($row->enemy_is_boss ?? false)
            || str_contains((string) ($row->enemy_role ?? ''), 'ボス');
    }

    private function formatSourceRate(float $rate): string
    {
        if ($rate < 0.1) {
            return rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
        }

        return rtrim(rtrim(number_format($rate, 1), '0'), '.') . '%';
    }

    private function normalizeSourceText(string $source): string
    {
        $source = preg_replace('/。{2,}/u', '。', $source) ?? $source;
        return trim($source);
    }

    private function sourcePayload(string $label, ?int $areaId = null, ?Material $material = null): array
    {
        $label = $this->normalizeSourceText($label);
        $params = ['area' => $areaId];
        if ($material) {
            $params['material'] = (int) $material->id;
        }

        return [
            'label' => $label,
            'url' => $areaId ? route('smith.source-area', $params) : null,
            'material_id' => $material ? (int) $material->id : null,
        ];
    }

    private function removeRedundantSources(array $sources): array
    {
        $result = [];

        foreach ($sources as $source) {
            $sourceLabel = (string) ($source['label'] ?? '');
            $isRedundant = false;
            foreach ($result as $existing) {
                $existingLabel = (string) ($existing['label'] ?? '');
                if ($sourceLabel !== $existingLabel && (str_contains($existingLabel, $sourceLabel) || str_contains($sourceLabel, $existingLabel))) {
                    $isRedundant = mb_strlen($sourceLabel) <= mb_strlen($existingLabel);
                    if (!$isRedundant) {
                        $result = array_values(array_filter($result, fn (array $value): bool => ($value['label'] ?? '') !== $existingLabel));
                    }
                    break;
                }
            }

            if (!$isRedundant) {
                $result[] = $source;
            }
        }

        return $result;
    }

    private function evolutionStoneRequirement(string $type, string $fromRank, array $ownedMaterials): ?array
    {
        $required = self::EVOLUTION_STONE_QUANTITIES[strtoupper($fromRank)] ?? 5;
        [$code, $name] = $this->equipmentFragmentForRank($fromRank);

        return $this->materialRequirement($code, $name, $required, true, $ownedMaterials);
    }

    private function usesCommonDropEvolutionRecipe(string $fromRank): bool
    {
        return in_array(strtoupper($fromRank), ['G', 'F', 'E', 'D', 'C', 'B'], true);
    }

    private function relaxedMaterialQuantity(string $fromRank, int $required): int
    {
        if (!in_array(strtoupper($fromRank), ['G', 'F', 'E', 'D'], true)) {
            return max(1, $required);
        }

        return max(1, (int) ceil($required * 0.7));
    }

    private function resolveWeaponMaterialRequirement(string $code, string $fallbackName, Character $character, object $recipe, ?Item $fromItem, ?Item $toItem, string $fromRank): array
    {
        $normalized = $this->normalizeEquipmentFragmentRequirement($code, $fallbackName);
        if ($normalized) {
            return $normalized;
        }

        if ($code === 'TOKEN_CITY_MATERIAL') {
            return $this->resolveCityWeaponMaterial(
                'weapon_city',
                $character,
                $fallbackName,
                $this->recipeCityId($character, $recipe, $fromItem, $toItem, $fromRank)
            );
        }

        if ($code === 'TOKEN_CITY_HIGH_MATERIAL') {
            return $this->resolveCityWeaponMaterial(
                'weapon_city_high',
                $character,
                $fallbackName,
                $this->recipeCityId($character, $recipe, $fromItem, $toItem, $fromRank)
            );
        }

        return [$code, $fallbackName];
    }

    private function resolveArmorMaterialRequirement(string $code, string $fallbackName, Character $character, object $recipe, ?Item $fromItem, ?Item $toItem, string $fromRank): array
    {
        $normalized = $this->normalizeEquipmentFragmentRequirement($code, $fallbackName);
        if ($normalized) {
            return $normalized;
        }

        return match ($code) {
            '5051' => $this->resolveArmorMaterialByCategory('city_material', $character, $fallbackName, false, $this->recipeCityId($character, $recipe, $fromItem, $toItem, $fromRank)),
            '5052' => $this->resolveArmorMaterialByCategory('city_material', $character, $fallbackName, true, $this->recipeCityId($character, $recipe, $fromItem, $toItem, $fromRank)),
            '5053', '5054' => [self::STRONG_EQUIPMENT_FRAGMENT_CODE, '強装備の欠片'],
            default => [$code, $fallbackName],
        };
    }

    private function resolveAccessoryMaterialRequirement(string $code, string $fallbackName, Character $character, object $recipe, ?Item $fromItem, ?Item $toItem, string $fromRank): array
    {
        if (preg_match('/^ACC\d{4}$/', $code) === 1) {
            return [$code, $fallbackName];
        }

        $normalized = $this->normalizeEquipmentFragmentRequirement($code, $fallbackName);
        if ($normalized) {
            return $normalized;
        }

        return match ($code) {
            'ACC_CITY_MATERIAL' => [self::FINE_EQUIPMENT_FRAGMENT_CODE, '上質な装備の欠片'],
            'ACC_CITY_HIGH_MATERIAL' => $this->resolveCityAccessoryMaterial(
                'accessory_city_high',
                $character,
                $fallbackName,
                $this->recipeCityId($character, $recipe, $fromItem, $toItem, $fromRank)
            ),
            default => [$code, $fallbackName],
        };
    }

    private function resolveCityWeaponMaterial(string $materialType, Character $character, string $fallbackName, int $cityId): array
    {
        $material = Material::where('material_type', $materialType)
            ->where('city_id', $cityId)
            ->first();

        if (!$material) {
            $material = Material::where('material_type', $materialType)
                ->orderBy('city_id')
                ->first();
        }

        if (!$material) {
            return [$materialType === 'weapon_city' ? 'TOKEN_CITY_MATERIAL' : 'TOKEN_CITY_HIGH_MATERIAL', $fallbackName];
        }

        return [(string) $material->material_code, $material->name];
    }

    private function isLegacyCommonFragment(string $code): bool
    {
        return in_array($code, ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'], true);
    }

    private function normalizeEquipmentFragmentRequirement(string $code, string $fallbackName): ?array
    {
        if ($this->isBranchEvolutionMaterialRequirement($code, $fallbackName)) {
            return null;
        }

        if (in_array($code, [
            self::EQUIPMENT_FRAGMENT_CODE,
            'WEV0001',
            '5001',
            'ACC0001',
            'MAT_WEAPON_FRAGMENT',
        ], true) || in_array($fallbackName, ['武器の欠片', '防具の欠片', '装飾の欠片'], true)) {
            return [self::EQUIPMENT_FRAGMENT_CODE, '装備の欠片'];
        }

        if (in_array($code, ['WEV0002', '5002', 'ACC0002'], true)
            || in_array($fallbackName, ['武器の結晶', '防具の結晶', '装飾の結晶'], true)) {
            return [self::FINE_EQUIPMENT_FRAGMENT_CODE, '上質な装備の欠片'];
        }

        if (in_array($code, ['WEV0003', '5003', 'ACC0003'], true)
            || in_array($fallbackName, ['武器の核', '防具の核', '装飾の核'], true)) {
            return [self::STRONG_EQUIPMENT_FRAGMENT_CODE, '強装備の欠片'];
        }

        if ($this->isDomainEquipmentFragment($code, $fallbackName)) {
            return [self::STRONG_EQUIPMENT_FRAGMENT_CODE, '強装備の欠片'];
        }

        return null;
    }

    private function isDomainEquipmentFragment(string $code, string $fallbackName): bool
    {
        if ($this->isBranchEvolutionMaterialRequirement($code, $fallbackName)) {
            return false;
        }

        if (preg_match('/^WEV00(0[8-9]|1[0-9]|2[0-2])$/', $code)) {
            return true;
        }

        if (preg_match('/^(501[0-9]|502[0-4]|ACC00[1-3][0-9])$/', $code)) {
            return true;
        }

        foreach (['斬撃', '刺突', '打撃', '射撃', '魔導', '軽装', '重装', '魔布', '聖布', '闘具', '腕力', '守護', '魔力', '祈祷', '疾風', '幸運', '生命', '精神', '均衡', '冒険'] as $prefix) {
            if (str_starts_with($fallbackName, $prefix . 'の')) {
                return true;
            }
        }

        return false;
    }

    private function isBranchEvolutionMaterialRequirement(string $code, string $fallbackName): bool
    {
        if (str_starts_with($code, 'MAT_BR_')) {
            return true;
        }

        foreach (['導石', '古代片', '秘境晶', '秘境晶片', '極印', '極印片'] as $keyword) {
            if (str_contains($fallbackName, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function equipmentFragmentForRank(string $rank): array
    {
        return match (strtoupper($rank)) {
            'G', 'F', 'E', 'D' => [self::EQUIPMENT_FRAGMENT_CODE, '装備の欠片'],
            'C', 'B' => [self::FINE_EQUIPMENT_FRAGMENT_CODE, '上質な装備の欠片'],
            default => [self::STRONG_EQUIPMENT_FRAGMENT_CODE, '強装備の欠片'],
        };
    }

    private function resolveArmorMaterialByCategory(string $category, Character $character, string $fallbackName, bool $highCity, ?int $fixedCityId = null): array
    {
        $query = Material::where('material_type', $category);

        if ($category === 'city_material') {
            $cityId = (int) ($fixedCityId ?: $character->highest_city_id ?: 1);
            $ids = $highCity
                ? [5026, 5028, 5030, 5032, 5034, 5036, 5038, 5040, 5042, 5044]
                : [5025, 5027, 5029, 5031, 5033, 5035, 5037, 5039, 5041, 5043];

            $targetCode = (string) ($ids[$cityId - 1] ?? $ids[0]);
            $material = Material::where('material_code', $targetCode)->first();
        } else {
            $material = $query->orderBy('material_code')->first();
        }

        if (!$material) {
            return [$category, $fallbackName];
        }

        return [(string) $material->material_code, $material->name];
    }

    private function resolveCityAccessoryMaterial(string $materialType, Character $character, string $fallbackName, int $cityId): array
    {
        $material = Material::where('material_type', $materialType)
            ->where('city_id', $cityId)
            ->first();

        if (!$material) {
            $material = Material::where('material_type', $materialType)
                ->orderBy('city_id')
                ->first();
        }

        if (!$material) {
            return [$materialType === 'accessory_city' ? 'ACC_CITY_MATERIAL' : 'ACC_CITY_HIGH_MATERIAL', $fallbackName];
        }

        return [(string) $material->material_code, $material->name];
    }

    private function recipeCityId(Character $character, object $recipe, ?Item $fromItem, ?Item $toItem, string $fromRank): int
    {
        foreach ([
            $recipe->unlock_city_id ?? null,
            $toItem?->unlock_city_id,
            $fromItem?->unlock_city_id,
        ] as $cityId) {
            $cityId = (int) $cityId;
            if ($cityId >= 1 && $cityId <= 10) {
                return $cityId;
            }
        }

        return $this->cityIdForEvolutionRank($fromRank);
    }

    private function cityIdForEvolutionRank(string $fromRank): int
    {
        return match (strtoupper($fromRank)) {
            'C', 'B' => 1,
            'A' => 2,
            default => 1,
        };
    }

    private function findRecipeForUpdate(string $recipeType, string $recipeId): object
    {
        $query = match ($recipeType) {
            'weapon' => DB::table('weapon_evolution_recipes')->where('recipe_id', $recipeId),
            'armor' => DB::table('armor_evolution_recipes')->where('evolution_recipe_id', $recipeId),
            'accessory' => DB::table('accessory_evolution_recipes')->where('recipe_id', $recipeId),
        };

        $recipe = $query->where('is_active', true)->lockForUpdate()->first();
        if (!$recipe) {
            throw new RuntimeException('合成レシピが見つかりません。');
        }

        return $recipe;
    }

    private function findActiveItem(string $type, string $externalId): ?Item
    {
        return Item::where('type', $type)
            ->where('external_item_id', $externalId)
            ->where('is_active', true)
            ->first();
    }

    private function ownedMaterialMap(Character $character): array
    {
        $rows = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->with('material')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if (!$row->material) {
                continue;
            }
            $map[(string) $row->material->material_code] = [
                'quantity' => (int) $row->quantity,
                'name' => $row->material->name,
            ];
        }

        return $map;
    }

    private function discoveredItemIds(Character $character): array
    {
        $ownedIds = CharacterItem::where('character_id', $character->id)
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $evolvedIds = EquipmentEvolutionLog::where('character_id', $character->id)
            ->pluck('after_equipment_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($ownedIds, $evolvedIds)));
    }

    private function undiscoveredName(string $type, ?Item $item, ?string $rank): string
    {
        if (!$item) {
            return '未鑑定の装備';
        }

        if ($type === 'weapon') {
            $label = $item->weapon_family_name ?: $this->weaponFamilyLabel($item->weapon_family_id);
            return '未鑑定の' . ($label ?: '武器');
        }

        if ($type === 'armor') {
            $label = $this->armorFamilyLabel($item->armor_family_id) ?: $item->armor_family_name;
            return '未鑑定の防具・' . ($label ?: '防具');
        }

        $label = $item->sub_type ?: $item->accessory_family_name;
        return '未鑑定の装飾品・' . ($label ?: '装飾品');
    }

    private function weaponFamilyLabel(?string $familyId): ?string
    {
        return match ($familyId) {
            'SWORD' => '剣',
            'DAGGER' => '短剣',
            'SPEAR' => '槍',
            'AXE' => '斧',
            'CLUB' => '棍棒',
            'BOW' => '弓',
            'STAFF' => '杖',
            'GRIMOIRE' => '魔導書',
            'FIST' => '格闘武器',
            'GUN' => '機工銃',
            default => null,
        };
    }

    private function armorFamilyLabel(?string $familyId): ?string
    {
        return match ($familyId) {
            'light_armor',   'BR_LIGHT_ARMOR'    => '軽装系',
            'heavy_armor',   'BR_HEAVY_ARMOR'    => '重装系',
            'traveler_wear', 'BR_TRAVELER_WEAR'  => '旅装系',
            'robe',          'BR_ROBE'           => '魔装系',
            'arcane_armor',  'BR_ARCANE_ARMOR'   => '魔装系',
            'martial_garb',  'BR_MARTIAL_GARB'   => '闘具系',
            'holy_vestment', 'BR_HOLY_VESTMENT'  => '聖衣系',
            'shadow_garb',   'BR_SHADOW_GARB'    => '影装系',
            default => null,
        };
    }

    private function lockRequiredMaterials(Character $character, array $requirements): array
    {
        $locked = [];
        foreach ($requirements as $requirement) {
            $material = Material::where('material_code', $requirement['material_code'])->first();
            if (!$material) {
                throw new RuntimeException($requirement['name'] . ' の素材マスタが見つかりません。');
            }

            $row = CharacterMaterial::where('character_id', $character->id)
                ->where('material_id', $material->id)
                ->lockForUpdate()
                ->first();

            if (!$row || $row->quantity < $requirement['required']) {
                throw new RuntimeException($material->name . ' が不足しています。');
            }

            $locked[$requirement['material_code']] = $row;
        }

        return $locked;
    }

    private function lockEvolutionSourceEquipment(Character $character, int $itemId, int $requiredCount, ?int $sourceCharacterItemId = null)
    {
        if ($sourceCharacterItemId !== null) {
            $selected = $this->evolutionSourceEquipmentQuery($character, $itemId)
                ->where('id', $sourceCharacterItemId)
                ->with(['item', 'affixPrefix', 'affixSuffix'])
                ->lockForUpdate()
                ->first();

            if (!$selected) {
                throw new RuntimeException('選択した進化元装備が見つかりません。');
            }

            if ($requiredCount <= 1) {
                return collect([$selected]);
            }

            $remaining = $this->evolutionSourceEquipmentQuery($character, $itemId)
                ->where('id', '!=', $sourceCharacterItemId)
                ->with(['item', 'affixPrefix', 'affixSuffix'])
                ->lockForUpdate()
                ->limit($requiredCount - 1)
                ->get();

            return collect([$selected])->concat($remaining)->values();
        }

        return $this->evolutionSourceEquipmentQuery($character, $itemId)
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->lockForUpdate()
            ->limit($requiredCount)
            ->get();
    }

    private function evolutionSourceEquipmentQuery(Character $character, int $itemId)
    {
        return CharacterItem::where('character_id', $character->id)
            ->where('item_id', $itemId)
            ->orderByDesc('is_equipped')
            ->orderBy('enhance_level')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function recipeUnlockOk(Character $character, object $recipe): bool
    {
        if ($this->isEarlyEvolutionRecipe($recipe)) {
            return true;
        }

        $highestCityId = (int) ($character->highest_city_id ?: $character->current_city_id ?: 1);

        if (isset($recipe->unlock_city_id) && $recipe->unlock_city_id !== null && (int) $recipe->unlock_city_id > $highestCityId) {
            return false;
        }

        if ($this->truthy($recipe->requires_city7_boss_cleared ?? false) && !$this->hasClearedAnyAreaInRange($character, 7, 70)) {
            return false;
        }

        if ($this->truthy($recipe->requires_hidden_dungeon_unlocked ?? false) && !$this->hasUnlockedHiddenArea($character)) {
            return false;
        }

        if ($this->truthy($recipe->requires_hidden_boss_cleared ?? false) && !$this->hasClearedAnyAreaInRange($character, self::HIDDEN_AREA_MIN_ID, self::HIDDEN_AREA_MAX_ID)) {
            return false;
        }

        if ($this->truthy($recipe->requires_demon_king_cleared ?? false) && !$this->hasClearedCity($character, 10)) {
            return false;
        }

        return true;
    }

    private function recipeUnlockReason(Character $character, object $recipe): ?string
    {
        if ($this->recipeUnlockOk($character, $recipe)) {
            return null;
        }

        $highestCityId = (int) ($character->highest_city_id ?: $character->current_city_id ?: 1);

        if (isset($recipe->unlock_city_id) && $recipe->unlock_city_id !== null && (int) $recipe->unlock_city_id > $highestCityId) {
            return '進化に必要な街まで到達していません。';
        }

        if ($this->truthy($recipe->requires_city7_boss_cleared ?? false) && !$this->hasClearedAnyAreaInRange($character, 7, 70)) {
            return '対象エリアのボス撃破が必要です。';
        }

        if ($this->truthy($recipe->requires_hidden_dungeon_unlocked ?? false) && !$this->hasUnlockedHiddenArea($character)) {
            return '現時点で未実装です';
        }

        if ($this->truthy($recipe->requires_hidden_boss_cleared ?? false) && !$this->hasClearedAnyAreaInRange($character, self::HIDDEN_AREA_MIN_ID, self::HIDDEN_AREA_MAX_ID)) {
            return '秘境主の撃破が必要です。';
        }

        if ($this->truthy($recipe->requires_demon_king_cleared ?? false) && !$this->hasClearedCity($character, 10)) {
            return '魔王撃破が必要です。';
        }

        return '進化の解放条件を満たしていません。';
    }

    private function isEarlyEvolutionRecipe(object $recipe): bool
    {
        $fromRank = strtoupper((string) ($recipe->from_rank ?? ''));
        $toRank = strtoupper((string) ($recipe->to_rank ?? ''));

        return in_array($fromRank, ['G', 'F', 'E', 'D', 'C', 'B'], true)
            && in_array($toRank, ['F', 'E', 'D', 'C', 'B', 'A'], true);
    }

    private function hasUnlockedHiddenArea(Character $character): bool
    {
        return CharacterAreaProgress::where('character_id', $character->id)
            ->whereBetween('area_id', [self::HIDDEN_AREA_MIN_ID, self::HIDDEN_AREA_MAX_ID])
            ->where('is_unlocked', true)
            ->exists();
    }

    private function hasClearedAnyAreaInRange(Character $character, int $minAreaId, ?int $maxAreaId): bool
    {
        $query = CharacterAreaProgress::where('character_id', $character->id)
            ->where('area_id', '>=', $minAreaId)
            ->where('boss_defeated', true);

        if ($maxAreaId !== null) {
            $query->where('area_id', '<=', $maxAreaId);
        }

        return $query->exists();
    }

    private function hasClearedCity(Character $character, int $cityId): bool
    {
        return DB::table('areas')
            ->leftJoin('character_area_progresses', function ($join) use ($character) {
                $join->on('areas.id', '=', 'character_area_progresses.area_id')
                    ->where('character_area_progresses.character_id', $character->id)
                    ->where('character_area_progresses.boss_defeated', true);
            })
            ->where('areas.city_id', $cityId)
            ->where('areas.id', '<=', 70)
            ->whereNull('character_area_progresses.id')
            ->doesntExist();
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
