<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MaterialExchangeService
{
    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const FINE_EQUIPMENT_FRAGMENT_CODE = 'MAT_FINE_EQUIPMENT_FRAGMENT';
    private const STRONG_EQUIPMENT_FRAGMENT_CODE = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
    private const ENHANCE_STONE_CONVERSION_RATE = 20;
    private const ENHANCE_STONE_GOLD_COST = 500;
    private const HIGH_PURITY_STONE_GOLD_COST = 2000;
    private const LOW_REFINING_CORE_GOLD_COST = 5000;
    private const REFINING_CORE_GOLD_COST = 10000;
    private const LOW_REFINING_CORE_A_CODE = 'MAT_REFINING_CORE_LOW_A';
    private const LOW_REFINING_CORE_B_CODE = 'MAT_REFINING_CORE_LOW_B';
    private const LOW_REFINING_CORE_CODE = 'MAT_REFINING_CORE_LOW';
    private const REFINING_CORE_PART_A_CODE = 'MAT_REFINING_CORE_PART_A';
    private const REFINING_CORE_PART_B_CODE = 'MAT_REFINING_CORE_PART_B';
    private const REFINING_CORE_PART_C_CODE = 'MAT_REFINING_CORE_PART_C';
    private const FRAGMENT_SYNTHESIS_GOLD_COST = 100;
    private const UPGRADE_RATE = 10;
    private const STRONG_FRAGMENT_UPGRADE_RATE = 15;
    private const SAME_RANK_RATE = 3;
    private const CROSS_CATEGORY_RATE = 5;
    private const CITY_MATERIAL_PATH_STONE_RATE = 10;
    private const ENEMY_TO_FRAGMENT_RATE = 5;

    private const FRAGMENT_SYNTHESIS_RECIPES = [
        [
            'target' => 'MAT_ENHANCE_FRAGMENT',
            'label' => '武器強化',
            'sources' => [['MAT_COMMON_GOBLIN_FANG', 4], ['MAT_COMMON_MAGIC_ORE', 2]],
        ],
        [
            'target' => 'MAT_ENHANCE_FRAGMENT',
            'label' => '序盤救済',
            'sources' => [['MAT_COMMON_SLIME_MUCUS', 8], ['MAT_COMMON_OLD_BADGE', 3]],
        ],
        [
            'target' => '5007',
            'label' => '防具強化',
            'sources' => [['MAT_COMMON_MONSTER_SHELL', 5], ['MAT_COMMON_BEAST_FUR', 3]],
        ],
        [
            'target' => 'ACC0007',
            'label' => '装飾強化',
            'sources' => [['MAT_COMMON_FAIRY_DUST', 5], ['MAT_COMMON_MONSTER_CORE', 2], ['MAT_COMMON_OLD_BADGE', 2]],
        ],
    ];

    private const COMMON_GROUPS = [];

    private const DOMAIN_GROUPS = [];

    private const TIER_LABELS = ['欠片', '結晶', '核'];

    private const EVOLUTION_STONE_RECIPES = [];

    private const ENHANCEMENT_STONE_RECIPES = [
        'MAT_ENHANCE_STONE' => [
            'label' => '武器強化',
            'sources' => [['MAT_ENHANCE_FRAGMENT', self::ENHANCE_STONE_CONVERSION_RATE]],
            'gold_cost' => self::ENHANCE_STONE_GOLD_COST,
        ],
        '5008' => [
            'label' => '防具強化',
            'sources' => [['5007', self::ENHANCE_STONE_CONVERSION_RATE]],
            'gold_cost' => self::ENHANCE_STONE_GOLD_COST,
        ],
        'ACC0008' => [
            'label' => '装飾強化',
            'sources' => [['ACC0007', self::ENHANCE_STONE_CONVERSION_RATE]],
            'gold_cost' => self::ENHANCE_STONE_GOLD_COST,
        ],
        'MAT_ENHANCE_HIGH_STONE' => [
            'label' => '武器高純度',
            'sources' => [['MAT_ENHANCE_STONE', 5], ['MAT_COMMON_MAGIC_ORE', 10]],
            'gold_cost' => self::HIGH_PURITY_STONE_GOLD_COST,
        ],
        '5009' => [
            'label' => '防具高純度',
            'sources' => [['5008', 5], ['MAT_COMMON_MAGIC_ORE', 10]],
            'gold_cost' => self::HIGH_PURITY_STONE_GOLD_COST,
        ],
        'ACC0009' => [
            'label' => '装飾高純度',
            'sources' => [['ACC0008', 5], ['MAT_COMMON_MAGIC_ORE', 10]],
            'gold_cost' => self::HIGH_PURITY_STONE_GOLD_COST,
        ],
    ];

    private const REFINING_CORE_PART_RECIPES = [
        self::REFINING_CORE_PART_A_CODE => [
            'label' => '覇王黒晶',
            'sources' => [['WEV0033', 5], ['WEV0043', 5], ['WEV0051', 5]],
        ],
        self::REFINING_CORE_PART_B_CODE => [
            'label' => '蒼炉魔晶',
            'sources' => [['WEV0035', 5], ['WEV0039', 5], ['WEV0045', 5], ['WEV0047', 5]],
        ],
        self::REFINING_CORE_PART_C_CODE => [
            'label' => '星樹氷晶',
            'sources' => [['WEV0037', 5], ['WEV0041', 5], ['WEV0049', 5]],
        ],
    ];

    private const LOW_REFINING_CORE_RECIPES = [
        self::LOW_REFINING_CORE_A_CODE => [
            'label' => '織成核殻',
            'sources' => [['5025', 10], ['5027', 10], ['5029', 10], ['5031', 10]],
        ],
        self::LOW_REFINING_CORE_B_CODE => [
            'label' => '晶糸核芯',
            'sources' => [['5033', 10], ['5035', 10], ['5037', 10]],
        ],
        self::LOW_REFINING_CORE_CODE => [
            'label' => '粗精錬核',
            'sources' => [[self::LOW_REFINING_CORE_A_CODE, 1], [self::LOW_REFINING_CORE_B_CODE, 1], ['MAT_COMMON_MONSTER_FRAGMENT', 20]],
            'gold_cost' => self::LOW_REFINING_CORE_GOLD_COST,
        ],
    ];

    private const RECOVERY_ITEM_NAMES = ['薬草', '回復薬', '魔力水'];

    private const PATH_STONE_RECIPES = [
        'MAT_BR_WPN_HOLY_PATH' => [['5025', 7], ['5033', 3]],
        'MAT_BR_WPN_DARK_PATH' => [['5043', 7], ['5031', 3]],
        'MAT_BR_WPN_GALE_PATH' => [['5029', 7], ['5041', 3]],
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => [['5031', 7], ['5037', 3]],
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => [['5027', 7], ['5035', 3]],
    ];

    private const ANCIENT_COMPOSITE_RECIPES = [
        'MAT_BR_WPN_HOLY_COMPOSITE' => [
            'label' => '聖剣SS素材',
            'sources' => [['5030', 1], ['MAT_REGION_HEAVEN_FEATHER', 1], ['5041', 2], ['5042', 1], ['5026', 1]],
        ],
        'MAT_BR_WPN_DARK_COMPOSITE' => [
            'label' => '魔剣SS素材',
            'sources' => [['5040', 1], ['MAT_REGION_ABYSS_FRAGMENT', 1], ['5043', 2], ['5044', 1], ['5039', 1]],
        ],
        'MAT_BR_WPN_GALE_COMPOSITE' => [
            'label' => '迅刃SS素材',
            'sources' => [['5029', 2], ['5030', 1], ['5041', 1], ['MAT_REGION_HEAVEN_FEATHER', 1], ['5042', 1]],
        ],
        'MAT_BR_ARM_HEAVY_COMPOSITE' => [
            'label' => '重装SS素材',
            'sources' => [['5034', 1], ['MAT_REGION_ICE_CRYSTAL', 1], ['MAT_REGION_BLACK_IRON_PART', 1], ['5032', 1]],
        ],
        'MAT_BR_ARM_ARCANE_COMPOSITE' => [
            'label' => '魔装SS素材',
            'sources' => [['5037', 1], ['5038', 1], ['MAT_REGION_MAGIC_CRYSTAL', 1], ['5032', 1]],
        ],
        'MAT_BR_ARM_LIGHT_COMPOSITE' => [
            'label' => '軽装SS素材',
            'sources' => [['5030', 1], ['MAT_REGION_HEAVEN_FEATHER', 1], ['5029', 1], ['5041', 1]],
        ],
        'MAT_BR_ARM_TRAVELER_COMPOSITE' => [
            'label' => '旅装SS素材',
            'sources' => [['5036', 1], ['MAT_REGION_ANCIENT_SAND', 1], ['5027', 1], ['5028', 1]],
        ],
    ];

    private const ACCESSORY_EVOLUTION_MATERIAL_RECIPES = [
        'ACC0003' => [
            'label' => '装飾共通',
            'sources' => [['MAT_COMMON_MONSTER_CORE', 10], ['MAT_COMMON_MAGIC_ORE', 5]],
        ],
        'ACC0011' => [
            'label' => '腕力装飾',
            'sources' => [['MAT_COMMON_BEAST_FANG', 8], ['MAT_REGION_ARKREA_RAW', 2]],
        ],
        'ACC0014' => [
            'label' => '守護装飾',
            'sources' => [['MAT_COMMON_MONSTER_SHELL', 8], ['MAT_REGION_ARKREA_RAW', 2]],
        ],
        'ACC0017' => [
            'label' => '魔力装飾',
            'sources' => [['MAT_COMMON_MAGIC_ORE', 8], ['MAT_REGION_MAGIC_CRYSTAL', 2]],
        ],
        'ACC0020' => [
            'label' => '祈祷装飾',
            'sources' => [['MAT_COMMON_FAIRY_DUST', 8], ['MAT_REGION_WORLD_TREE_LEAF', 2]],
        ],
        'ACC0023' => [
            'label' => '疾風装飾',
            'sources' => [['MAT_COMMON_WING_MEMBRANE', 8], ['MAT_COMMON_BEAST_FUR', 4]],
        ],
        'ACC0026' => [
            'label' => '幸運装飾',
            'sources' => [['MAT_COMMON_FAIRY_DUST', 8], ['MAT_COMMON_DARK_CRYSTAL', 4]],
        ],
        'ACC0029' => [
            'label' => '生命装飾',
            'sources' => [['MAT_COMMON_MONSTER_SHELL', 8], ['MAT_REGION_WORLD_TREE_LEAF', 2]],
        ],
        'ACC0032' => [
            'label' => '精神装飾',
            'sources' => [['MAT_COMMON_MAGIC_ORE', 8], ['MAT_REGION_MAGIC_CRYSTAL', 2]],
        ],
        'ACC0035' => [
            'label' => '均衡装飾',
            'sources' => [['MAT_COMMON_OLD_BADGE', 8], ['MAT_COMMON_MONSTER_FRAGMENT', 8]],
        ],
        'ACC0038' => [
            'label' => '冒険装飾',
            'sources' => [['MAT_COMMON_OLD_BADGE', 8], ['MAT_REGION_ARKREA_RAW', 2]],
        ],
        'MAT_BR_ACC_PRIMORDIAL_ORNAMENT_CRYSTAL' => [
            'label' => '原初装飾',
            'sources' => [
                ['MAT_BR_WPN_HOLY_SECRET', 2],
                ['MAT_BR_WPN_DARK_SECRET', 2],
                ['MAT_BR_WPN_GALE_SECRET', 2],
                ['MAT_BR_ARM_HEAVY_SECRET', 2],
                ['MAT_BR_ARM_ARCANE_SECRET', 2],
                ['MAT_BR_ARM_LIGHT_SECRET', 2],
                ['MAT_BR_ARM_TRAVELER_SECRET', 2],
            ],
        ],
    ];

    public function recipes(Character $character): array
    {
        return $this->buildRecipes($character, true);
    }

    public function catalogRecipes(Character $character): array
    {
        return $this->buildRecipes($character, false);
    }

    private function buildRecipes(Character $character, bool $ownedOnly): array
    {
        $owned = $this->ownedMaterialMap($character);
        $materials = $this->materialsByCode();
        $recipes = [];

        foreach ($this->allGroups() as $group) {
            $recipes = array_merge($recipes, $this->upgradeRecipes($group, $materials, $owned, $ownedOnly));
        }

        $recipes = array_merge($recipes, $this->evolutionStoneRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->fragmentSynthesisRecipes($materials, $owned, (int) ($character->money ?? 0), $ownedOnly));
        $recipes = array_merge($recipes, $this->enhancementStoneRecipes($materials, $owned, (int) ($character->money ?? 0), $ownedOnly));
        $recipes = array_merge($recipes, $this->lowRefiningCoreRecipes($materials, $owned, (int) ($character->money ?? 0), $ownedOnly));
        $recipes = array_merge($recipes, $this->refiningCorePartRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->refiningCoreRecipes($materials, $owned, (int) ($character->money ?? 0), $ownedOnly));
        $recipes = array_merge($recipes, $this->secretCrystalShardRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->cityMaterialPathStoneRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->ancientCompositeRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->accessoryEvolutionMaterialRecipes($materials, $owned, $ownedOnly));
        $recipes = array_merge($recipes, $this->enemyPartToCommonMaterialRecipes($materials, $owned, $ownedOnly));

        usort($recipes, fn (array $a, array $b): int => [
            $a['can_exchange'] ? 0 : 1,
            $a['sort_order'],
            $a['source_name'],
            $a['target_name'],
        ] <=> [
            $b['can_exchange'] ? 0 : 1,
            $b['sort_order'],
            $b['source_name'],
            $b['target_name'],
        ]);

        return $recipes;
    }

    public function exchange(Character $character, string $recipeId, int $quantity = 1): array
    {
        return DB::transaction(function () use ($character, $recipeId, $quantity) {
            return $this->performExchange($character, $recipeId, $quantity);
        }, 3);
    }

    public function exchangeMany(Character $character, array $recipeIds, array $quantities = []): array
    {
        $exchangeRequests = [];
        foreach ($recipeIds as $index => $recipeId) {
            if (!is_string($recipeId) || $recipeId === '' || isset($exchangeRequests[$recipeId])) {
                continue;
            }

            $exchangeRequests[$recipeId] = $this->normalizeExchangeQuantity($quantities[$recipeId] ?? $quantities[$index] ?? 1);
        }

        $recipeIds = array_keys($exchangeRequests);

        if ($recipeIds === []) {
            throw new RuntimeException('一括交換する素材を選択してください。');
        }

        if (count($recipeIds) > 50) {
            throw new RuntimeException('一度に交換できるのは50件までです。');
        }

        $totalQuantity = 0;
        foreach ($recipeIds as $recipeId) {
            $totalQuantity += $exchangeRequests[$recipeId];
        }

        if ($totalQuantity > 500) {
            throw new RuntimeException('一度に交換できる合計回数は500回までです。');
        }

        return DB::transaction(function () use ($character, $exchangeRequests) {
            $messages = [];

            foreach ($exchangeRequests as $recipeId => $quantity) {
                $messages[] = $this->performExchange($character, $recipeId, $quantity)['message'];
            }

            return [
                'message' => count($messages) . '件 / 合計' . array_sum($exchangeRequests) . '回分の交換を完了しました。',
                'messages' => $messages,
            ];
        }, 3);
    }

    private function performExchange(Character $character, string $recipeId, int $quantity = 1): array
    {
        $quantity = $this->normalizeExchangeQuantity($quantity);
        $recipe = collect($this->allRecipes($this->materialsByCode()))
            ->firstWhere('id', $recipeId);

        if (!$recipe) {
            throw new RuntimeException('交換レシピが見つかりません。');
        }

        $requirements = $recipe['source_materials'] ?? [[
            'material_code' => $recipe['source_code'],
            'name' => $recipe['source_name'],
            'required' => $recipe['source_quantity'],
        ]];

        $sourceCodes = array_column($requirements, 'material_code');
        $sourceMaterials = Material::whereIn('material_code', $sourceCodes)
            ->get()
            ->keyBy(fn (Material $material) => (string) $material->material_code);

        $target = null;
        $targetItem = null;
        if (($recipe['target_kind'] ?? 'material') === 'material') {
            $target = Material::where('material_code', $recipe['target_code'])->lockForUpdate()->first();
        } elseif (($recipe['target_kind'] ?? null) === 'item') {
            $targetItem = Item::where('type', 'consumable')->where('name', $recipe['target_code'])->first();
        } elseif (($recipe['target_kind'] ?? null) === 'random_item') {
            $targetItem = $this->randomRecoveryItem();
        }

        if ($sourceMaterials->count() !== count(array_unique($sourceCodes)) || (!$target && !$targetItem)) {
            throw new RuntimeException('素材マスタが見つかりません。');
        }

        $sourceRows = CharacterMaterial::where('character_id', $character->id)
            ->whereIn('material_id', $sourceMaterials->pluck('id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('material_id');

        foreach ($requirements as $requirement) {
            $source = $sourceMaterials[(string) $requirement['material_code']] ?? null;
            $sourceRow = $source ? $sourceRows->get($source->id) : null;
            if (!$source || !$sourceRow || $sourceRow->quantity < ((int) $requirement['required'] * $quantity)) {
                throw new RuntimeException(($requirement['name'] ?? '素材') . ' が不足しています。');
            }
        }

        $goldCost = (int) ($recipe['gold_cost'] ?? 0) * $quantity;
        if ($goldCost > 0 && (int) ($character->money ?? 0) < $goldCost) {
            throw new RuntimeException('Goldが不足しています。');
        }

        foreach ($requirements as $requirement) {
            $source = $sourceMaterials[(string) $requirement['material_code']];
            $sourceRow = $sourceRows->get($source->id);
            $sourceRow->quantity -= (int) $requirement['required'] * $quantity;
            if ($sourceRow->quantity <= 0) {
                $sourceRow->delete();
            } else {
                $sourceRow->save();
            }
        }

        if ($goldCost > 0) {
            app(GoldService::class)->spend(
                $character,
                $goldCost,
                'material_exchange',
                "{$recipe['target_name']}の素材交換"
            );
        }

        if ($target) {
            $targetRow = CharacterMaterial::firstOrCreate(
                ['character_id' => $character->id, 'material_id' => $target->id],
                ['quantity' => 0]
            );
            $targetRow->increment('quantity', $recipe['target_quantity'] * $quantity);
        } else {
            $totalTargetQuantity = (int) $recipe['target_quantity'] * $quantity;
            for ($i = 0; $i < $totalTargetQuantity; $i++) {
                if (($recipe['target_kind'] ?? null) === 'random_item') {
                    $targetItem = $this->randomRecoveryItem();
                }

                if (!$targetItem) {
                    throw new RuntimeException('交換先アイテムが見つかりません。');
                }

                CharacterItem::create([
                    'character_id' => $character->id,
                    'item_id' => $targetItem->id,
                    'is_equipped' => false,
                    'is_stored' => false,
                    'is_locked' => false,
                    'enhance_level' => 0,
                    'equipped_slot' => null,
                    'acquired_from' => 'brewing',
                ]);
            }
        }

        $sourceText = collect($requirements)
            ->map(fn (array $requirement): string => "{$requirement['name']}を" . ((int) $requirement['required'] * $quantity) . "個")
            ->implode('、');
        $targetName = ($recipe['target_kind'] ?? null) === 'random_item'
            ? $recipe['target_name']
            : ($target?->name ?? $targetItem?->name ?? $recipe['target_name']);
        $targetQuantity = (int) $recipe['target_quantity'] * $quantity;

        return [
            'message' => "{$sourceText}交換し、{$targetName}を{$targetQuantity}個受け取りました。"
                . ($goldCost > 0 ? ' 交換費用として' . number_format($goldCost) . 'Gを支払いました。' : ''),
        ];
    }

    private function normalizeExchangeQuantity(mixed $quantity): int
    {
        $quantity = (int) $quantity;

        return max(1, min(500, $quantity));
    }

    private function upgradeRecipes(array $group, array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        foreach ([0, 1] as $tier) {
            $sourceCode = $group['codes'][$tier] ?? null;
            $targetCode = $group['codes'][$tier + 1] ?? null;
            if (!$this->canBuildRecipe($sourceCode, $targetCode, $materials)) {
                continue;
            }

            $sourceQuantity = $targetCode === self::STRONG_EQUIPMENT_FRAGMENT_CODE
                ? self::STRONG_FRAGMENT_UPGRADE_RATE
                : self::UPGRADE_RATE;

            $recipes[] = $this->recipePayload(
                'upgrade',
                '上位変換',
                $group['label'],
                $tier,
                $sourceCode,
                $targetCode,
                $sourceQuantity,
                1,
                $materials,
                $owned,
                10 + $tier
            );
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function sameRankRecipes(string $domain, array $groups, array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        foreach ([0, 1, 2] as $tier) {
            foreach ($groups as $sourceGroup) {
                foreach ($groups as $targetGroup) {
                    if ($sourceGroup['label'] === $targetGroup['label']) {
                        continue;
                    }

                    $sourceCode = $sourceGroup['codes'][$tier] ?? null;
                    $targetCode = $targetGroup['codes'][$tier] ?? null;
                    if (!$this->canBuildRecipe($sourceCode, $targetCode, $materials)) {
                        continue;
                    }

                    $recipes[] = $this->recipePayload(
                        'same_rank',
                        '同ランク別系統',
                        $this->domainLabel($domain),
                        $tier,
                        $sourceCode,
                        $targetCode,
                        self::SAME_RANK_RATE,
                        1,
                        $materials,
                        $owned,
                        100 + ($tier * 10)
                    );
                }
            }
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function crossCategoryRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $groups = array_values(self::COMMON_GROUPS);
        foreach ([0, 1, 2] as $tier) {
            foreach ($groups as $sourceGroup) {
                foreach ($groups as $targetGroup) {
                    if ($sourceGroup['label'] === $targetGroup['label']) {
                        continue;
                    }

                    $sourceCode = $sourceGroup['codes'][$tier] ?? null;
                    $targetCode = $targetGroup['codes'][$tier] ?? null;
                    if (!$this->canBuildRecipe($sourceCode, $targetCode, $materials)) {
                        continue;
                    }

                    $recipes[] = $this->recipePayload(
                        'cross_category',
                        '別カテゴリ変換',
                        '共通素材',
                        $tier,
                        $sourceCode,
                        $targetCode,
                        self::CROSS_CATEGORY_RATE,
                        1,
                        $materials,
                        $owned,
                        200 + ($tier * 10)
                    );
                }
            }
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function allRecipes(array $materials): array
    {
        $emptyOwned = [];
        $recipes = [];
        foreach ($this->allGroups() as $group) {
            $recipes = array_merge($recipes, $this->upgradeRecipes($group, $materials, $emptyOwned, false));
        }
        $recipes = array_merge($recipes, $this->evolutionStoneRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->fragmentSynthesisRecipes($materials, $emptyOwned, PHP_INT_MAX, false));
        $recipes = array_merge($recipes, $this->enhancementStoneRecipes($materials, $emptyOwned, PHP_INT_MAX, false));
        $recipes = array_merge($recipes, $this->lowRefiningCoreRecipes($materials, $emptyOwned, PHP_INT_MAX, false));
        $recipes = array_merge($recipes, $this->refiningCorePartRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->refiningCoreRecipes($materials, $emptyOwned, PHP_INT_MAX, false));
        $recipes = array_merge($recipes, $this->secretCrystalShardRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->cityMaterialPathStoneRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->ancientCompositeRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->accessoryEvolutionMaterialRecipes($materials, $emptyOwned, false));
        $recipes = array_merge($recipes, $this->enemyPartToCommonMaterialRecipes($materials, $emptyOwned, false));

        return $recipes;
    }

    private function evolutionStoneRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        foreach (self::EVOLUTION_STONE_RECIPES as $index => $recipe) {
            if (!$this->canBuildRecipe($recipe['source'], $recipe['target'], $materials)) {
                continue;
            }

            $recipes[] = $this->recipePayload(
                'evolution_stone',
                '進化石交換',
                $recipe['label'],
                -1,
                $recipe['source'],
                $recipe['target'],
                self::UPGRADE_RATE,
                1,
                $materials,
                $owned,
                60 + $index
            );
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function enhancementStoneRecipes(array $materials, array $owned, int $ownedGold, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::ENHANCEMENT_STONE_RECIPES as $targetCode => $recipe) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'enhancement_stone',
                '強化石精製',
                $recipe['label'],
                $recipe['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                70 + $index,
                '',
                (int) ($recipe['gold_cost'] ?? 0),
                $ownedGold
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function refiningCoreRecipes(array $materials, array $owned, int $ownedGold, bool $ownedOnly = true): array
    {
        if (!isset($materials['MAT_REFINING_CORE'])) {
            return [];
        }

        $sources = [
            [self::LOW_REFINING_CORE_CODE, 1],
            ['MAT_COMMON_MONSTER_CORE', 20],
            [self::REFINING_CORE_PART_A_CODE, 1],
            [self::REFINING_CORE_PART_B_CODE, 1],
            [self::REFINING_CORE_PART_C_CODE, 1],
        ];

        $recipes = [$this->multiSourceRecipePayload(
            'refining_core',
            '精錬核錬成',
            '精錬材一式',
            $sources,
            'material',
            'MAT_REFINING_CORE',
            $materials['MAT_REFINING_CORE']->name,
            1,
            $materials,
            $owned,
            [],
            90,
            '',
            self::REFINING_CORE_GOLD_COST,
            $ownedGold
        )];

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function refiningCorePartRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::REFINING_CORE_PART_RECIPES as $targetCode => $recipe) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'refining_core_part',
                '精錬材錬成',
                $recipe['label'],
                $recipe['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                88 + $index
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function lowRefiningCoreRecipes(array $materials, array $owned, int $ownedGold = PHP_INT_MAX, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::LOW_REFINING_CORE_RECIPES as $targetCode => $recipe) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'low_refining_core',
                '粗精錬核錬成',
                $recipe['label'],
                $recipe['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                85 + $index,
                '',
                (int) ($recipe['gold_cost'] ?? 0),
                $ownedGold
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $recipes;
    }

    private function fragmentSynthesisRecipes(array $materials, array $owned, int $ownedGold, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::FRAGMENT_SYNTHESIS_RECIPES as $definition) {
            $targetCode = $definition['target'];
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'fragment_synthesis',
                '欠片合成',
                $definition['label'],
                $definition['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                65 + $index,
                '',
                self::FRAGMENT_SYNTHESIS_GOLD_COST,
                $ownedGold
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function cityMaterialPathStoneRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::PATH_STONE_RECIPES as $targetCode => $sourceRequirements) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'city_path_stone',
                '導石錬成',
                '都市素材7:3',
                $sourceRequirements,
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                120 + $index
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function ancientCompositeRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::ANCIENT_COMPOSITE_RECIPES as $targetCode => $definition) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'ancient_composite',
                'SS進化素材錬成',
                $definition['label'],
                $definition['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                130 + $index
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function accessoryEvolutionMaterialRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach (self::ACCESSORY_EVOLUTION_MATERIAL_RECIPES as $targetCode => $definition) {
            if (!isset($materials[$targetCode])) {
                continue;
            }

            $recipes[] = $this->multiSourceRecipePayload(
                'accessory_evolution_material',
                '装飾素材錬成',
                $definition['label'],
                $definition['sources'],
                'material',
                $targetCode,
                $materials[$targetCode]->name,
                1,
                $materials,
                $owned,
                [],
                180 + $index
            );
            $index++;
        }

        $recipes = array_values(array_filter($recipes));

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function secretCrystalShardRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach ($materials as $sourceCode => $source) {
            if (!str_ends_with((string) $sourceCode, '_SECRET_SHARD')) {
                continue;
            }

            $targetCode = str_replace('_SECRET_SHARD', '_SECRET', (string) $sourceCode);
            if (!$this->canBuildRecipe((string) $sourceCode, $targetCode, $materials)) {
                continue;
            }

            $recipes[] = $this->recipePayload(
                'secret_crystal',
                '秘境晶交換',
                '分岐秘境素材',
                -1,
                (string) $sourceCode,
                $targetCode,
                5,
                1,
                $materials,
                $owned,
                80 + $index
            );
            $index++;
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function enemyPartToCommonMaterialRecipes(array $materials, array $owned, bool $ownedOnly = true): array
    {
        $recipes = [];
        $index = 0;

        foreach ($materials as $sourceCode => $material) {
            if (!$this->isEnemyPartMaterial($material)) {
                continue;
            }

            $targetCode = $this->commonMaterialCodeFor($material);
            if (!$targetCode || !$this->canBuildRecipe((string) $sourceCode, $targetCode, $materials)) {
                continue;
            }

            $recipes[] = $this->recipePayload(
                'enemy_to_common',
                '共通化',
                '敵素材',
                -1,
                (string) $sourceCode,
                $targetCode,
                $this->commonMaterialExchangeRate($material, $targetCode),
                1,
                $materials,
                $owned,
                250 + $index
            );
            $index++;
        }

        return $ownedOnly ? $this->visibleRecipes($recipes) : $recipes;
    }

    private function recipePayload(
        string $type,
        string $typeLabel,
        string $groupLabel,
        int $tier,
        string $sourceCode,
        string $targetCode,
        int $sourceQuantity,
        int $targetQuantity,
        array $materials,
        array $owned,
        int $sortOrder
    ): array {
        return $this->multiSourceRecipePayload(
            $type,
            $typeLabel,
            $groupLabel,
            [[$sourceCode, $sourceQuantity]],
            'material',
            $targetCode,
            $materials[$targetCode]->name,
            $targetQuantity,
            $materials,
            $owned,
            [],
            $sortOrder,
            self::TIER_LABELS[$tier] ?? ''
        );
    }

    private function multiSourceRecipePayload(
        string $type,
        string $typeLabel,
        string $groupLabel,
        array $sourceRequirements,
        string $targetKind,
        string $targetCode,
        string $targetName,
        int $targetQuantity,
        array $materials,
        array $owned,
        array $ownedItems,
        int $sortOrder,
        string $tierLabel = '',
        int $goldCost = 0,
        int $ownedGold = PHP_INT_MAX
    ): ?array {
        $sourceMaterials = [];
        foreach ($sourceRequirements as [$code, $required]) {
            if (!isset($materials[$code])) {
                return null;
            }

            $sourceMaterials[] = [
                'material_code' => $code,
                'name' => $materials[$code]->name,
                'icon_image' => $materials[$code]->iconImagePath(),
                'required' => (int) $required,
                'owned' => $owned[$code] ?? 0,
                'missing' => max(0, (int) $required - ($owned[$code] ?? 0)),
            ];
        }

        $ownedQuantity = count($sourceMaterials) === 1
            ? $sourceMaterials[0]['owned']
            : array_sum(array_column($sourceMaterials, 'owned'));
        $sourceQuantity = count($sourceMaterials) === 1
            ? $sourceMaterials[0]['required']
            : array_sum(array_column($sourceMaterials, 'required'));
        $targetOwnedQuantity = $targetKind === 'material'
            ? ($owned[$targetCode] ?? 0)
            : ($ownedItems[$targetCode] ?? 0);
        $missingQuantity = array_sum(array_column($sourceMaterials, 'missing'));
        $missingGold = max(0, $goldCost - $ownedGold);
        $canExchange = $missingQuantity === 0 && $missingGold === 0;
        $maxExchangeCount = min(array_map(
            fn (array $source): int => (int) floor(($source['owned'] ?? 0) / max(1, (int) $source['required'])),
            $sourceMaterials
        ));
        if ($goldCost > 0) {
            $maxExchangeCount = min($maxExchangeCount, (int) floor($ownedGold / $goldCost));
        }
        $sourceName = collect($sourceMaterials)
            ->map(fn (array $source): string => $source['name'])
            ->implode(' + ');

        return [
            'id' => implode(':', [$type, implode('+', array_column($sourceMaterials, 'material_code')), $targetCode, $sourceQuantity]),
            'type' => $type,
            'type_label' => $typeLabel,
            'group_label' => $groupLabel,
            'tier_label' => $tierLabel,
            'source_code' => $sourceMaterials[0]['material_code'],
            'source_name' => $sourceName,
            'source_quantity' => $sourceQuantity,
            'source_materials' => $sourceMaterials,
            'target_code' => $targetCode,
            'target_kind' => $targetKind,
            'target_material_id' => $targetKind === 'material' ? ($materials[$targetCode]->id ?? null) : null,
            'target_name' => $targetName,
            'target_icon_image' => $targetKind === 'material' && isset($materials[$targetCode])
                ? $materials[$targetCode]->iconImagePath()
                : ($targetKind === 'material'
                    ? Material::iconImagePathFor($targetCode, $targetName)
                    : 'images/icon/icon_087.webp'),
            'target_usage' => $this->targetUsageText($type, $targetCode, $targetName, $groupLabel),
            'target_quantity' => $targetQuantity,
            'target_owned_quantity' => $targetOwnedQuantity,
            'owned_quantity' => $ownedQuantity,
            'missing_quantity' => $missingQuantity,
            'gold_cost' => $goldCost,
            'owned_gold' => $ownedGold,
            'missing_gold' => $missingGold,
            'can_exchange' => $canExchange,
            'max_exchange_count' => $maxExchangeCount,
            'has_any_source' => $ownedQuantity > 0,
            'sort_order' => $sortOrder,
        ];
    }

    private function targetUsageText(string $type, string $targetCode, string $targetName, string $groupLabel): string
    {
        if (in_array($targetCode, ['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], true)) {
            return '鍛冶+1→+2、鍛冶+2→+3に利用';
        }

        if (in_array($targetCode, ['MAT_ENHANCE_STONE', '5008', 'ACC0008'], true)) {
            return '鍛冶+2→+3、鍛冶+3→+4に利用';
        }

        if (in_array($targetCode, ['MAT_ENHANCE_HIGH_STONE', '5009', 'ACC0009'], true)) {
            return '鍛冶+3→+4、鍛冶+4→+5に利用';
        }

        if ($targetCode === self::LOW_REFINING_CORE_A_CODE || $targetCode === self::LOW_REFINING_CORE_B_CODE) {
            return '粗精錬核の錬成に利用';
        }

        if (in_array($targetCode, [
            self::REFINING_CORE_PART_A_CODE,
            self::REFINING_CORE_PART_B_CODE,
            self::REFINING_CORE_PART_C_CODE,
        ], true)) {
            return '精錬核の錬成に利用';
        }

        if ($targetCode === self::LOW_REFINING_CORE_CODE) {
            return '鍛冶+3→+4に利用';
        }

        if ($targetCode === 'MAT_REFINING_CORE') {
            return '鍛冶+4→+5に利用';
        }

        if ($type === 'city_path_stone') {
            return str_contains($targetCode, '_WPN_')
                ? '武器SS→SSSの分岐進化に利用'
                : '防具SS→SSSの分岐進化に利用';
        }

        if ($type === 'ancient_composite') {
            return str_contains($targetCode, '_WPN_')
                ? '武器S→SSの分岐進化に利用'
                : '防具S→SSの分岐進化に利用';
        }

        if ($type === 'secret_crystal') {
            return '秘境系の進化・解放に利用';
        }

        if ($type === 'accessory_evolution_material') {
            return '装飾品の進化に利用';
        }

        if ($type === 'enemy_to_common') {
            return '鍛冶・素材交換の共通素材として利用';
        }

        if ($type === 'enemy_part') {
            return '回復アイテムの調合素材として利用';
        }

        if ($type === 'recovery_brewing') {
            return '探索中の回復アイテムとして利用';
        }

        return filled($groupLabel)
            ? $groupLabel . 'に利用'
            : $targetName . 'を使うレシピで利用';
    }

    private function materialsByCode(): array
    {
        return Material::whereIn('material_code', $this->materialLookupCodes())
            ->get()
            ->keyBy(fn (Material $material) => (string) $material->material_code)
            ->all();
    }

    private function ownedMaterialMap(Character $character): array
    {
        $rows = CharacterMaterial::where('character_id', $character->id)
            ->with('material')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            if ($row->material) {
                $map[(string) $row->material->material_code] = (int) $row->quantity;
            }
        }

        return $map;
    }

    private function allGroups(): array
    {
        return array_merge(array_values(self::COMMON_GROUPS), ...array_values(self::DOMAIN_GROUPS));
    }

    private function knownCodes(): array
    {
        $codes = [];
        foreach ($this->allGroups() as $group) {
            $codes = array_merge($codes, $group['codes']);
        }

        return array_values(array_unique($codes));
    }

    private function materialLookupCodes(): array
    {
        return array_values(array_unique(array_merge(
            $this->knownCodes(),
            [
                self::EQUIPMENT_FRAGMENT_CODE,
                self::FINE_EQUIPMENT_FRAGMENT_CODE,
                self::STRONG_EQUIPMENT_FRAGMENT_CODE,
                'MAT_ENHANCE_FRAGMENT',
                'MAT_ENHANCE_STONE',
                '5007',
                '5008',
                'ACC0007',
                'ACC0008',
                'ACC0009',
                '5009',
                'MAT_ENHANCE_HIGH_STONE',
                'MAT_REFINING_CORE',
                self::LOW_REFINING_CORE_A_CODE,
                self::LOW_REFINING_CORE_B_CODE,
                self::LOW_REFINING_CORE_CODE,
                self::REFINING_CORE_PART_A_CODE,
                self::REFINING_CORE_PART_B_CODE,
                self::REFINING_CORE_PART_C_CODE,
            ],
            $this->enhancementStoneMaterialCodes(),
            $this->lowRefiningCoreMaterialCodes(),
            $this->refiningCorePartMaterialCodes(),
            $this->fragmentSynthesisMaterialCodes(),
            $this->commonMaterialTargetCodes(),
            $this->pathStoneSourceCodes(),
            array_keys(self::PATH_STONE_RECIPES),
            $this->ancientCompositeMaterialCodes(),
            $this->accessoryEvolutionMaterialCodes(),
            $this->branchSecretMaterialCodes(),
            $this->enemyPartMaterialCodes()
        )));
    }

    private function pathStoneSourceCodes(): array
    {
        $codes = [];
        foreach (self::PATH_STONE_RECIPES as $requirements) {
            $codes = array_merge($codes, array_column($requirements, 0));
        }

        return array_values(array_unique($codes));
    }

    private function ancientCompositeMaterialCodes(): array
    {
        $codes = array_keys(self::ANCIENT_COMPOSITE_RECIPES);
        foreach (self::ANCIENT_COMPOSITE_RECIPES as $definition) {
            $codes = array_merge($codes, array_column($definition['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function accessoryEvolutionMaterialCodes(): array
    {
        $codes = array_keys(self::ACCESSORY_EVOLUTION_MATERIAL_RECIPES);
        foreach (self::ACCESSORY_EVOLUTION_MATERIAL_RECIPES as $definition) {
            $codes = array_merge($codes, array_column($definition['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function enhancementStoneMaterialCodes(): array
    {
        $codes = array_keys(self::ENHANCEMENT_STONE_RECIPES);
        foreach (self::ENHANCEMENT_STONE_RECIPES as $recipe) {
            $codes = array_merge($codes, array_column($recipe['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function lowRefiningCoreMaterialCodes(): array
    {
        $codes = array_keys(self::LOW_REFINING_CORE_RECIPES);
        foreach (self::LOW_REFINING_CORE_RECIPES as $recipe) {
            $codes = array_merge($codes, array_column($recipe['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function refiningCorePartMaterialCodes(): array
    {
        $codes = array_keys(self::REFINING_CORE_PART_RECIPES);
        foreach (self::REFINING_CORE_PART_RECIPES as $recipe) {
            $codes = array_merge($codes, array_column($recipe['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function fragmentSynthesisMaterialCodes(): array
    {
        $codes = [];
        foreach (self::FRAGMENT_SYNTHESIS_RECIPES as $definition) {
            $codes[] = $definition['target'];
            $codes = array_merge($codes, array_column($definition['sources'], 0));
        }

        return array_values(array_unique($codes));
    }

    private function commonMaterialTargetCodes(): array
    {
        return [
            'MAT_COMMON_BEAST_FANG',
            'MAT_COMMON_GOBLIN_FANG',
            'MAT_COMMON_BEAST_FUR',
            'MAT_COMMON_WING_MEMBRANE',
            'MAT_COMMON_MONSTER_SHELL',
            'MAT_COMMON_OLD_BONE',
            'MAT_COMMON_OLD_BADGE',
            'MAT_COMMON_MONSTER_CORE',
            'MAT_COMMON_MAGIC_ORE',
            'MAT_COMMON_FAIRY_DUST',
            'MAT_COMMON_HOLY_FRAGMENT',
            'MAT_COMMON_DARK_CRYSTAL',
            'MAT_COMMON_MONSTER_FRAGMENT',
            'MAT_COMMON_SLIME_MUCUS',
        ];
    }

    private function ownedItemMap(Character $character): array
    {
        $itemIdsByName = $this->recoveryItemsByName();
        if ($itemIdsByName === []) {
            return [];
        }

        $counts = CharacterItem::where('character_id', $character->id)
            ->whereIn('item_id', collect($itemIdsByName)->pluck('id'))
            ->where('is_equipped', false)
            ->select('item_id', DB::raw('count(*) as total'))
            ->groupBy('item_id')
            ->pluck('total', 'item_id');

        $map = [];
        foreach ($itemIdsByName as $name => $item) {
            $map[$name] = (int) ($counts[$item->id] ?? 0);
        }

        return $map;
    }

    private function recoveryItemsByName(): array
    {
        return Item::where('type', 'consumable')
            ->whereIn('name', self::RECOVERY_ITEM_NAMES)
            ->get()
            ->keyBy('name')
            ->all();
    }

    private function randomRecoveryItem(): ?Item
    {
        $items = $this->recoveryItemsByName();
        if (count($items) < count(self::RECOVERY_ITEM_NAMES)) {
            return null;
        }

        return $items[self::RECOVERY_ITEM_NAMES[random_int(0, count(self::RECOVERY_ITEM_NAMES) - 1)]] ?? null;
    }

    private function enemyPartMaterialCodes(): array
    {
        return Material::query()
            ->where(function ($query) {
                $query->whereNotNull('source_enemy_id')
                    ->orWhere('material_code', 'like', 'MAT%');
            })
            ->pluck('material_code')
            ->map(fn ($code): string => (string) $code)
            ->all();
    }

    private function branchSecretMaterialCodes(): array
    {
        return Material::query()
            ->where('material_type', 'branch_evolution')
            ->where(function ($query) {
                $query->where('material_code', 'like', '%_SECRET')
                    ->orWhere('material_code', 'like', '%_SECRET_SHARD');
            })
            ->pluck('material_code')
            ->map(fn ($code): string => (string) $code)
            ->all();
    }

    private function isEnemyPartMaterial(Material $material): bool
    {
        $code = (string) $material->material_code;
        $name = (string) $material->name;
        $category = (string) ($material->category ?? '');
        $type = (string) ($material->material_type ?? '');

        if (in_array($code, [
            self::EQUIPMENT_FRAGMENT_CODE,
            self::FINE_EQUIPMENT_FRAGMENT_CODE,
            self::STRONG_EQUIPMENT_FRAGMENT_CODE,
        ], true)) {
            return false;
        }

        if ($type !== '' && in_array($type, ['equipment_common', 'branch_evolution', 'champ', 'brewing', 'boss_unique'], true)) {
            return false;
        }

        foreach (['討伐証', 'ボス特異素材', '進行キー', '分岐進化', '装備共通', '進化解放キー'] as $blockedCategory) {
            if (str_contains($category, $blockedCategory)) {
                return false;
            }
        }

        foreach (['刻印', '王印', '神印', '進化証', '英雄の証', '討伐証', '導石', '古代片', '秘境晶', '極印', 'ボス特異素材', '進行キー', '装備の欠片', '強化石', '調律石'] as $blockedName) {
            if (str_contains($name, $blockedName)) {
                return false;
            }
        }

        return (bool) ($material->source_enemy_id || preg_match('/^MAT\d+$/', $code));
    }

    private function commonMaterialCodeFor(Material $material): ?string
    {
        $name = (string) $material->name;

        if ($this->containsAny($name, ['スライム', '粘液', 'ゼリー', 'ゲル'])) {
            return 'MAT_COMMON_SLIME_MUCUS';
        }

        if ($this->containsAny($name, ['牙', '爪', 'ゴブリン', '小鬼'])) {
            return 'MAT_COMMON_BEAST_FANG';
        }

        if ($this->containsAny($name, ['毛皮', '獣毛', 'ウルフ', '狼', 'うさぎ', '兎', '猪', '熊'])) {
            return 'MAT_COMMON_BEAST_FUR';
        }

        if ($this->containsAny($name, ['翼膜', '羽膜', '蝙蝠', 'コウモリ'])) {
            return 'MAT_COMMON_WING_MEMBRANE';
        }

        if ($this->containsAny($name, ['外殻', '甲殻', '装甲', '鱗', '甲羅', '殻'])) {
            return 'MAT_COMMON_MONSTER_SHELL';
        }

        if ($this->containsAny($name, ['古骨', '骨片', '骨'])) {
            return 'MAT_COMMON_OLD_BONE';
        }

        if ($this->containsAny($name, ['徽章', '勲章', '盗賊', '兵士', '騎士', '海賊'])) {
            return 'MAT_COMMON_OLD_BADGE';
        }

        if ($this->containsAny($name, ['黒結晶', '呪い', '闇', '瘴気', '腐'])) {
            return 'MAT_COMMON_DARK_CRYSTAL';
        }

        if ($this->containsAny($name, ['聖片', '神殿', '天使', '司祭', '聖'])) {
            return 'MAT_COMMON_HOLY_FRAGMENT';
        }

        if ($this->containsAny($name, ['魔核', '魔導炉', '炉心', '核'])) {
            return 'MAT_COMMON_MONSTER_CORE';
        }

        if ($this->containsAny($name, ['鉱片', '魔鉱', '水晶', '結晶', '雷石', '火種', '鉱石'])) {
            return 'MAT_COMMON_MAGIC_ORE';
        }

        if ($this->containsAny($name, ['妖精粉', '花', '葉', '自然片', '樹液', '若草', '苔', '茸'])) {
            return 'MAT_COMMON_FAIRY_DUST';
        }

        return 'MAT_COMMON_MONSTER_FRAGMENT';
    }

    private function commonMaterialExchangeRate(Material $material, string $targetCode): int
    {
        $rarity = strtoupper((string) ($material->rarity ?? 'N'));

        if ($targetCode === 'MAT_COMMON_MONSTER_FRAGMENT') {
            return match ($rarity) {
                'R', 'SR', 'SS', 'SSS', 'EPIC' => 2,
                'N+' => 3,
                default => self::ENEMY_TO_FRAGMENT_RATE,
            };
        }

        return match ($rarity) {
            'R', 'SR', 'SS', 'SSS', 'EPIC' => 1,
            'N+' => 2,
            default => 3,
        };
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isUnlockKeyMaterial(Material $material): bool
    {
        $name = (string) $material->name;
        $type = (string) ($material->material_type ?? '');
        $category = (string) ($material->category ?? '');

        return str_contains($name, '進化証')
            || str_contains($type, 'unlock_key')
            || str_contains($category, '進化解放キー');
    }

    private function canBuildRecipe(?string $sourceCode, ?string $targetCode, array $materials): bool
    {
        return $sourceCode
            && $targetCode
            && $sourceCode !== $targetCode
            && isset($materials[$sourceCode], $materials[$targetCode])
            && (string) $materials[$sourceCode]->name !== (string) $materials[$targetCode]->name;
    }

    private function visibleRecipes(array $recipes): array
    {
        return array_values(array_filter(
            $recipes,
            fn (array $recipe) => ($recipe['has_any_source'] ?? false) || ($recipe['owned_quantity'] ?? 0) > 0
        ));
    }

    private function domainLabel(string $domain): string
    {
        return match ($domain) {
            'weapon' => '武器素材',
            'armor' => '防具素材',
            'accessory' => '装飾素材',
            default => '素材',
        };
    }
}
