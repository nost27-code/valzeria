<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Material;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ItemBookService
{
    private const HIDDEN_MATERIAL_TYPES = [
        'abstract_city_material',
        'common_armor',
        'legend',
        'secret',
    ];

    private const HIDDEN_MATERIAL_NAMES = [
        '都市高位素材',
        '都市素材（進化対象街）',
        '都市高位素材（進化対象街）',
        '伝説の縫魂',
        '秘境の守護繊維',
        '重装の古代片',
        '魔装の古代片',
        '軽装の古代片',
        '旅装の古代片',
        '古代武器片',
        '秘境の星砂',
        '伝説の武器紋章',
        '古代装飾片',
        '星屑の宝材',
    ];

    public function __construct(private MaterialExchangeService $materialExchangeService)
    {
    }

    public function materialBookFor(Character $character): array
    {
        $owned = $this->ownedMaterialMap($character);
        $exchangeRecipes = collect($this->materialExchangeService->catalogRecipes($character));
        $craftRecipesByTarget = $exchangeRecipes
            ->filter(fn (array $recipe): bool => ($recipe['target_kind'] ?? 'material') === 'material')
            ->groupBy(fn (array $recipe): string => (string) ($recipe['target_code'] ?? ''));
        $exchangeTargetCodes = $craftRecipesByTarget->keys()
            ->filter(fn (string $code): bool => $code !== '')
            ->flip()
            ->all();
        $usedInExchangeBySource = $this->usedInExchangeMap($exchangeRecipes);
        $usedInEvolutionByCode = $this->usedInEvolutionMap();
        $cityOrderById = City::query()->orderBy('id')->pluck('id')->flip()->all();

        $materials = Material::query()
            ->orderByRaw('CASE WHEN display_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(function (Material $material) use ($owned, $craftRecipesByTarget, $exchangeTargetCodes, $usedInExchangeBySource, $usedInEvolutionByCode, $cityOrderById): ?array {
                if ($this->isHiddenMaterial($material)) {
                    return null;
                }

                $code = (string) $material->material_code;
                $ownedQuantity = (int) ($owned[$code] ?? 0);
                $craftRecipes = $craftRecipesByTarget->get($code, collect())->values();
                $dropSources = $this->dropSourcesFor($material);

                if (! $this->shouldShowMaterial($code, $dropSources, $exchangeTargetCodes, $usedInExchangeBySource, $usedInEvolutionByCode)) {
                    return null;
                }

                $obtainNotes = $this->obtainNotesFor($material, $craftRecipes, $dropSources);
                $usageNotes = $this->usageNotesFor($material, $usedInExchangeBySource[$code] ?? [], $usedInEvolutionByCode[$code] ?? []);

                return [
                    'code' => $code,
                    'anchor_id' => 'item-book-material-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $code),
                    'name' => $material->displayName(),
                    'raw_name' => (string) $material->name,
                    'category' => $this->categoryLabel($material),
                    'category_key' => $this->categoryKey($material),
                    'rarity' => $this->rarityLabel($material),
                    'rarity_rank' => $this->rarityRank($material),
                    'group_rank' => $this->groupRank($material),
                    'order_rank' => $this->orderRank($material),
                    'city_order' => $cityOrderById[$material->city_id] ?? PHP_INT_MAX,
                    'owned_quantity' => $ownedQuantity,
                    'is_owned' => $ownedQuantity > 0,
                    'icon_image' => $material->iconImagePath(),
                    'main_use' => (string) ($material->main_use ?? ''),
                    'obtain_notes' => $obtainNotes,
                    'usage_notes' => $usageNotes,
                    'craft_recipes' => $craftRecipes->map(fn (array $recipe): array => $this->recipePayload($recipe))->all(),
                    'drop_sources' => $dropSources,
                    'search_text' => implode(' ', array_filter([
                        $material->name,
                        $material->material_code,
                        $material->category,
                        $material->material_type,
                        $material->main_use,
                        implode(' ', $obtainNotes),
                        implode(' ', $usageNotes),
                    ])),
                ];
            })
            ->filter()
            ->sortBy([
                fn (array $a, array $b): int => $a['group_rank'] <=> $b['group_rank'],
                fn (array $a, array $b): int => $a['city_order'] <=> $b['city_order'],
                fn (array $a, array $b): int => $a['order_rank'] <=> $b['order_rank'],
                fn (array $a, array $b): int => $a['category'] <=> $b['category'],
                fn (array $a, array $b): int => $a['name'] <=> $b['name'],
            ])
            ->values();

        $materialLinks = $this->materialLinkTargets($materials);
        $materials = $materials
            ->map(fn (array $entry): array => $entry + [
                'linked_usage_notes' => $this->linkedNotesFor($entry['usage_notes'] ?? [], $materialLinks, (string) ($entry['code'] ?? '')),
            ])
            ->values();

        return [
            'materials' => $materials,
            'summary' => [
                'total_count' => $materials->count(),
                'owned_count' => $materials->where('is_owned', true)->count(),
                'craftable_count' => $materials->filter(fn (array $entry): bool => count($entry['craft_recipes']) > 0)->count(),
            ],
            'filters' => $this->filtersFor($materials),
        ];
    }

    private function shouldShowMaterial(string $code, array $dropSources, array $exchangeTargetCodes, array $usedInExchangeBySource, array $usedInEvolutionByCode): bool
    {
        return isset($exchangeTargetCodes[$code])
            || isset($usedInExchangeBySource[$code])
            || isset($usedInEvolutionByCode[$code])
            || $dropSources !== [];
    }

    private function ownedMaterialMap(Character $character): array
    {
        return CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->whereHas('material')
            ->with('material')
            ->get()
            ->mapWithKeys(fn (CharacterMaterial $row): array => [
                (string) $row->material->material_code => (int) $row->quantity,
            ])
            ->all();
    }

    private function usedInExchangeMap(Collection $recipes): array
    {
        $map = [];
        foreach ($recipes as $recipe) {
            foreach (($recipe['source_materials'] ?? []) as $source) {
                $code = (string) ($source['material_code'] ?? '');
                if ($code === '') {
                    continue;
                }

                $map[$code][] = [
                    'label' => ($recipe['target_name'] ?? '素材') . 'の錬成に利用',
                    'type' => (string) ($recipe['type_label'] ?? '素材交換'),
                ];
            }
        }

        return $this->uniqueNoteMap($map);
    }

    private function usedInEvolutionMap(): array
    {
        $map = [];

        if (Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_type', 'material')
                ->select(['ingredient_id', 'ingredient_name'])
                ->get()
                ->each(function (object $row) use (&$map): void {
                    $map[(string) $row->ingredient_id][] = ['label' => '武器進化に利用', 'type' => '合成屋'];
                });
        }

        if (Schema::hasTable('armor_evolution_recipe_ingredients')) {
            DB::table('armor_evolution_recipe_ingredients')
                ->select(['material_id', 'material_name'])
                ->get()
                ->each(function (object $row) use (&$map): void {
                    $map[(string) $row->material_id][] = ['label' => '防具進化に利用', 'type' => '合成屋'];
                });
        }

        if (Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            DB::table('accessory_evolution_recipe_ingredients')
                ->where('ingredient_type', 'material')
                ->select(['material_code', 'material_name'])
                ->get()
                ->each(function (object $row) use (&$map): void {
                    $map[(string) $row->material_code][] = ['label' => '装飾品進化に利用', 'type' => '合成屋'];
                });
        }

        return $this->uniqueNoteMap($map);
    }

    private function uniqueNoteMap(array $map): array
    {
        foreach ($map as $code => $notes) {
            $map[$code] = collect($notes)
                ->unique(fn (array $note): string => $note['type'] . '|' . $note['label'])
                ->values()
                ->all();
        }

        return $map;
    }

    private function dropSourcesFor(Material $material): array
    {
        if (!Schema::hasTable('material_drops')) {
            return [];
        }

        return DB::table('material_drops')
            ->join('enemies', 'enemies.id', '=', 'material_drops.enemy_id')
            ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('material_drops.material_id', $material->id)
            ->where('material_drops.is_active', true)
            ->where('material_drops.drop_rate', '>', 0)
            ->orderBy('areas.id')
            ->orderBy('enemies.id')
            ->limit(6)
            ->get([
                'enemies.name as enemy_name',
                'areas.name as area_name',
                'material_drops.drop_rate',
                'material_drops.drop_first_clear_only',
            ])
            ->map(fn (object $row): array => [
                'enemy_name' => (string) $row->enemy_name,
                'area_name' => (string) ($row->area_name ?: '不明な地域'),
                'rate' => (float) $row->drop_rate,
                'first_clear_only' => (bool) $row->drop_first_clear_only,
            ])
            ->all();
    }

    private function obtainNotesFor(Material $material, Collection $craftRecipes, array $dropSources): array
    {
        $notes = [];
        if (! $this->isLegacyNormalMaterial($material) && (string) ($material->obtain_method ?? '') !== '') {
            $notes[] = (string) $material->obtain_method;
        }

        if (! $this->isLegacyNormalMaterial($material) && $craftRecipes->isNotEmpty()) {
            $notes[] = '素材交換所で錬成できます。';
        }

        if ($dropSources !== []) {
            $notes[] = '通常探索の敵ドロップで入手できます。';
        }

        if ($notes === []) {
            $notes[] = '詳しい入手方法は各施設や探索で確認できます。';
        }

        return array_values(array_unique($notes));
    }

    private function usageNotesFor(Material $material, array $exchangeUses, array $evolutionUses): array
    {
        $notes = [];
        if (! $this->isLegacyNormalMaterial($material) && (string) ($material->main_use ?? '') !== '') {
            $notes[] = (string) $material->main_use;
        }

        if (! $this->isLegacyNormalMaterial($material)) {
            foreach (array_merge($exchangeUses, $evolutionUses) as $use) {
                $notes[] = (string) $use['label'];
            }
        }

        if ($notes === []) {
            $notes[] = '主な用途は各施設で確認できます。';
        }

        return array_values(array_unique($notes));
    }

    private function materialLinkTargets(Collection $materials): array
    {
        $targets = [];

        foreach ($materials as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $anchorId = (string) ($entry['anchor_id'] ?? '');
            if ($code === '' || $anchorId === '') {
                continue;
            }

            foreach ([(string) ($entry['name'] ?? ''), (string) ($entry['raw_name'] ?? '')] as $name) {
                $name = trim(str_replace('[SR]', '', $name));
                if ($name === '') {
                    continue;
                }

                $targets[$name] = [
                    'code' => $code,
                    'name' => $name,
                    'anchor_id' => $anchorId,
                ];
            }
        }

        return collect($targets)
            ->sortByDesc(fn (array $target): int => mb_strlen($target['name']))
            ->values()
            ->all();
    }

    private function linkedNotesFor(array $notes, array $targets, string $currentCode): array
    {
        return collect($notes)
            ->map(fn (string $note): array => $this->linkedNoteFor($note, $targets, $currentCode))
            ->all();
    }

    private function linkedNoteFor(string $note, array $targets, string $currentCode): array
    {
        foreach ($targets as $target) {
            if (($target['code'] ?? '') === $currentCode) {
                continue;
            }

            $name = (string) ($target['name'] ?? '');
            $position = $name !== '' ? mb_strpos($note, $name) : false;
            if ($position === false) {
                continue;
            }

            return [
                'text' => $note,
                'segments' => [
                    ['text' => mb_substr($note, 0, $position), 'anchor_id' => null],
                    ['text' => $name, 'anchor_id' => (string) $target['anchor_id']],
                    ['text' => mb_substr($note, $position + mb_strlen($name)), 'anchor_id' => null],
                ],
            ];
        }

        return [
            'text' => $note,
            'segments' => [
                ['text' => $note, 'anchor_id' => null],
            ],
        ];
    }

    private function recipePayload(array $recipe): array
    {
        return [
            'label' => (string) ($recipe['type_label'] ?? '素材交換'),
            'group_label' => (string) ($recipe['group_label'] ?? ''),
            'target_quantity' => (int) ($recipe['target_quantity'] ?? 1),
            'gold_cost' => (int) ($recipe['gold_cost'] ?? 0),
            'sources' => collect($recipe['source_materials'] ?? [])->map(fn (array $source): array => [
                'material_code' => (string) ($source['material_code'] ?? ''),
                'name' => (string) ($source['name'] ?? '素材'),
                'required' => (int) ($source['required'] ?? 0),
                'owned' => (int) ($source['owned'] ?? 0),
                'icon_image' => $source['icon_image'] ?? null,
                'anchor_id' => ! empty($source['material_code'])
                    ? 'item-book-material-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $source['material_code'])
                    : null,
            ])->all(),
        ];
    }

    private function filtersFor(Collection $materials): array
    {
        return $materials
            ->groupBy('category_key')
            ->map(fn (Collection $rows): array => [
                'label' => (string) ($rows->first()['category'] ?? '素材'),
                'count' => $rows->count(),
            ])
            ->sortBy('label')
            ->all();
    }

    /**
     * material_type / category は素材交換・強化・進化・倉庫容量など他サービスの分岐条件として
     * DBの値そのものを直接参照しているため、DB値は変更せず表示ラベルだけをここで正規化する。
     */
    private function categoryLabel(Material $material): string
    {
        $type = (string) ($material->material_type ?? '');

        return match ($type) {
            'branch_evolution' => '分岐進化素材',
            'accessory_evolution' => '装飾進化素材',
            'equipment_common', 'enhance', 'evolution_stone' => '強化素材',
            'city_material', 'city' => '都市素材',
            'city_high' => '都市高位素材',
            'weapon_city' => '武器都市素材',
            'weapon_city_high' => '武器都市高位素材',
            'weapon_common', 'weapon_category' => '武器合成素材',
            'weapon_unlock_key' => '開放証',
            'accessory_city' => '装飾都市素材',
            'accessory_city_high' => '装飾都市高位素材',
            'light_material', 'heavy_material', 'magic_cloth', 'holy_cloth', 'martial_material' => '防具合成素材',
            'back_dungeon' => '裏ダンジョン素材',
            'back_high' => '裏高位素材',
            'secret' => '秘境素材',
            'token' => '特殊素材',
            'exchange_ticket' => '交換券',
            'champ' => 'チャンプ戦素材',
            'brewing' => '調合素材',
            'boss_unique' => '大事なもの',
            'sell_treasure' => '換金品',
            'common', 'common_drop', 'regional_drop', 'common_armor' => '共通素材',
            'legacy_normal_drop' => '旧通常素材',
            default => (string) ($material->category ?: '素材'),
        };
    }

    /**
     * rarity列は素材交換レート・餌経験値計算(N/N+/R/SR/SS/SSS/EPIC)で直接参照されるため
     * DB値は変更せず、図鑑表示だけをN〜EPICの単一階層に正規化する。
     */
    private const RARITY_DISPLAY_MAP = [
        'N' => 'N', 'LOW' => 'N', 'T1' => 'N', 'CITY_LOW' => 'N',
        'N+' => 'N+',
        'R' => 'R', 'MID' => 'R', 'T2' => 'R', 'CITY_HIGH' => 'R',
        'R+' => 'R+', 'HIGH' => 'R+', 'T3' => 'R+',
        'SR' => 'SR', 'T4' => 'SR',
        'SR+' => 'SR+', 'SSR' => 'SR+',
        'SS' => 'SS', 'T5' => 'SS',
        'SSS' => 'SSS', 'SSSR' => 'SSS',
        'EPIC' => 'EPIC',
        'KEY' => '重要',
    ];

    private const RARITY_RANK_ORDER = ['N', 'N+', 'R', 'R+', 'SR', 'SR+', 'SS', 'SSS', 'EPIC', '重要'];

    private function rarityLabel(Material $material): string
    {
        $raw = strtoupper((string) ($material->rarity ?? ''));

        return self::RARITY_DISPLAY_MAP[$raw] ?? ($raw !== '' ? $raw : '-');
    }

    private function rarityRank(Material $material): int
    {
        $rank = array_search($this->rarityLabel($material), self::RARITY_RANK_ORDER, true);

        return $rank === false ? count(self::RARITY_RANK_ORDER) : $rank;
    }

    /**
     * 図鑑全体の並び順: 0=装備強化素材(欠片→石→高純度), 1=地域素材(街の訪問順→レアリティ順), 2=それ以外。
     */
    private const ENHANCEMENT_MATERIAL_TYPES = ['enhance', 'equipment_common', 'evolution_stone'];

    private function groupRank(Material $material): int
    {
        $type = (string) ($material->material_type ?? '');

        // 調律石(装飾用)はaccessory_evolution扱いのデータになっているが、
        // 強化石・守護石と同じ「欠片→石→高純度」系列として図鑑上はまとめる。
        if (in_array($type, self::ENHANCEMENT_MATERIAL_TYPES, true)
            || preg_match('/^(?:高純度)?(?:強化石|守護石|調律石)(?:の欠片)?$/u', (string) $material->name) === 1) {
            return 0;
        }

        if ($material->city_id !== null) {
            return 1;
        }

        return 2;
    }

    /**
     * 装備強化素材グループ内の並び順。晶糸核芯・粗精錬核・織成核殻は精錬核の前段素材で
     * rarityはRだが、欠片→石→高純度の後(精錬核の手前)に置きたいため専用の順序表で並べる。
     */
    private const ENHANCEMENT_SUB_ORDER = [
        '強化石の欠片' => 11, '守護石の欠片' => 12, '調律石の欠片' => 13,
        '強化石' => 21, '守護石' => 22, '調律石' => 23,
        '高純度強化石' => 31, '高純度守護石' => 32, '高純度調律石' => 33,
        '織成核殻' => 41, '晶糸核芯' => 42,
        '粗精錬核' => 50,
        '覇王黒晶' => 61, '蒼炉魔晶' => 62, '星樹氷晶' => 63,
        '精錬核' => 70,
    ];

    private function enhancementSubRank(Material $material): int
    {
        return self::ENHANCEMENT_SUB_ORDER[(string) $material->name] ?? (100 + $this->rarityRank($material));
    }

    private function orderRank(Material $material): int
    {
        if ($this->groupRank($material) === 0) {
            return $this->enhancementSubRank($material);
        }

        return $this->rarityRank($material);
    }

    private function categoryKey(Material $material): string
    {
        $label = $this->categoryLabel($material);

        return preg_replace('/[^A-Za-z0-9_\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]+/u', '_', $label) ?: 'material';
    }

    private function isPlannedMaterial(Material $material): bool
    {
        $name = (string) $material->name;

        return str_contains($name, '極印')
            || str_contains($name, '星屑の宝材');
    }

    private function isHiddenMaterial(Material $material): bool
    {
        if ($this->isDeprecatedMaterial($material)) {
            return true;
        }

        if ($this->isPlannedMaterial($material)) {
            return true;
        }

        $name = (string) $material->name;
        if (in_array($name, self::HIDDEN_MATERIAL_NAMES, true)) {
            return true;
        }

        $type = (string) ($material->material_type ?? '');
        if (in_array($type, self::HIDDEN_MATERIAL_TYPES, true)) {
            return true;
        }

        if ($this->isLegacyNormalMaterial($material)) {
            return $this->dropSourcesFor($material) === [];
        }

        return false;
    }

    private function isLegacyNormalMaterial(Material $material): bool
    {
        return (string) ($material->category ?? '') === '旧通常素材';
    }

    private function isDeprecatedMaterial(Material $material): bool
    {
        return str_contains((string) ($material->main_use ?? ''), '廃止済み')
            || str_contains((string) ($material->obtain_method ?? ''), '廃止済み');
    }
}
