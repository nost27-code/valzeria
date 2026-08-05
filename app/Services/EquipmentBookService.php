<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EquipmentBookService
{
    private const TYPES = ['weapon', 'armor'];

    private const RANK_SORT = [
        'J' => 1,
        'I' => 2,
        'H' => 3,
        'G' => 4,
        'F' => 5,
        'E' => 6,
        'D' => 7,
        'C' => 8,
        'B' => 9,
        'A' => 10,
        'S' => 11,
        'SS' => 12,
        'SSS' => 13,
        'EPIC' => 14,
        'SPECIAL' => 15,
    ];

    public function __construct(
        private readonly EquipmentDiscoveryService $discoveryService,
        private readonly FavoriteWeaponService $favoriteWeaponService,
    ) {
    }

    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive('equipment_book');
    }

    public function bookFor(Character $character, string $type, ?string $requestedChartKey = null): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : 'weapon';
        $discoveredIds = $this->discoveryService->syncFor($character);
        $ownedCounts = DB::table('character_items')
            ->where('character_id', $character->id)
            ->selectRaw('item_id, COUNT(*) as owned_count')
            ->groupBy('item_id')
            ->pluck('owned_count', 'item_id')
            ->mapWithKeys(fn ($count, $itemId): array => [(int) $itemId => (int) $count])
            ->all();

        [$items, $childrenById, $incomingIds] = $this->graphFor($type);
        $roots = $items
            ->reject(fn (Item $item): bool => isset($incomingIds[(int) $item->id]))
            ->sortBy(fn (Item $item): array => [
                $this->categoryLabel($item),
                (int) ($item->sort_order ?? PHP_INT_MAX),
                $this->rankSort($this->rankFor($item)),
                (string) $item->name,
            ])
            ->values();

        $charts = $roots
            ->map(function (Item $root, int $index) use ($type, $childrenById, $discoveredIds, $ownedCounts): array {
                $reachableIds = $this->reachableItemIds((int) $root->id, $childrenById);
                $hasDiscovery = count(array_intersect($reachableIds, $discoveredIds)) > 0;
                $familyLabel = $this->familyLabel($root);
                $categoryLabel = $this->categoryLabel($root);

                return [
                    'key' => $type . '-' . $root->id,
                    'title' => $hasDiscovery
                        ? ($familyLabel . 'の系譜')
                        : ('未発見の' . $categoryLabel . '系統 ' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)),
                    'category' => $categoryLabel,
                    'node_count' => count($reachableIds),
                    'discovered_count' => count(array_intersect($reachableIds, $discoveredIds)),
                    'tree' => $this->nodePayload($root, $childrenById, $discoveredIds, $ownedCounts),
                ];
            })
            ->values();

        $selectedChart = $charts->firstWhere('key', $requestedChartKey) ?? $charts->first();
        $allItemIds = $items->pluck('id')->map(fn ($itemId): int => (int) $itemId)->all();

        return [
            'type' => $type,
            'type_label' => $type === 'weapon' ? '武器' : '防具',
            'chart_options' => $charts->map(fn (array $chart): array => [
                'key' => $chart['key'],
                'title' => $chart['title'],
                'category' => $chart['category'],
                'node_count' => $chart['node_count'],
                'discovered_count' => $chart['discovered_count'],
            ])->all(),
            'selected_chart' => $selectedChart,
            'summary' => [
                'total_count' => count($allItemIds),
                'discovered_count' => count(array_intersect($allItemIds, $discoveredIds)),
                'chart_count' => $charts->count(),
            ],
        ];
    }

    private function graphFor(string $type): array
    {
        $items = Item::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereNotNull('external_item_id')
            ->get()
            ->keyBy(fn (Item $item): int => (int) $item->id);
        $byExternalId = $items->keyBy(fn (Item $item): string => (string) $item->external_item_id);

        $recipeConfig = $type === 'weapon'
            ? ['weapon_evolution_recipes', 'from_weapon_id', 'to_weapon_id']
            : ['armor_evolution_recipes', 'source_armor_id', 'target_armor_id'];
        [$table, $fromColumn, $toColumn] = $recipeConfig;

        if (!Schema::hasTable($table)) {
            return [collect(), [], []];
        }

        $childrenById = [];
        $incomingIds = [];
        $linkedItemIds = [];

        DB::table($table)
            ->where('is_active', true)
            ->orderBy('id')
            ->get([$fromColumn, $toColumn])
            ->each(function (object $recipe) use ($fromColumn, $toColumn, $byExternalId, $items, &$childrenById, &$incomingIds, &$linkedItemIds): void {
                $fromItem = $this->resolveRecipeItem($recipe->{$fromColumn}, $byExternalId, $items);
                $toItem = $this->resolveRecipeItem($recipe->{$toColumn}, $byExternalId, $items);
                if (!$fromItem || !$toItem) {
                    return;
                }

                $fromId = (int) $fromItem->id;
                $toId = (int) $toItem->id;
                $childrenById[$fromId][$toId] = $toItem;
                $incomingIds[$toId] = true;
                $linkedItemIds[$fromId] = true;
                $linkedItemIds[$toId] = true;
            });

        foreach ($childrenById as $fromId => $children) {
            $childrenById[$fromId] = collect($children)
                ->sortBy(fn (Item $item): array => [
                    $this->rankSort($this->rankFor($item)),
                    (int) ($item->sort_order ?? PHP_INT_MAX),
                    (string) $item->name,
                ])
                ->values()
                ->all();
        }

        return [
            $items->only(array_keys($linkedItemIds)),
            $childrenById,
            $incomingIds,
        ];
    }

    private function resolveRecipeItem(mixed $recipeItemId, Collection $byExternalId, Collection $byId): ?Item
    {
        return $byExternalId->get((string) $recipeItemId)
            ?? $byId->get((int) $recipeItemId);
    }

    private function reachableItemIds(int $rootId, array $childrenById): array
    {
        $seen = [];
        $stack = [$rootId];

        while ($stack !== []) {
            $itemId = (int) array_pop($stack);
            if (isset($seen[$itemId])) {
                continue;
            }

            $seen[$itemId] = true;
            foreach ($childrenById[$itemId] ?? [] as $child) {
                $stack[] = (int) $child->id;
            }
        }

        return array_keys($seen);
    }

    private function nodePayload(Item $item, array $childrenById, array $discoveredIds, array $ownedCounts, array $path = []): array
    {
        $itemId = (int) $item->id;
        $discovered = in_array($itemId, $discoveredIds, true);
        $path[$itemId] = true;
        $image = $discovered && $item->type === 'weapon'
            ? $this->favoriteWeaponService->imagePathFor($item)
            : null;
        $image ??= $item->iconImagePath() ?: 'images/icon/icon_006.webp';

        $children = collect($childrenById[$itemId] ?? [])
            ->reject(fn (Item $child): bool => isset($path[(int) $child->id]))
            ->map(fn (Item $child): array => $this->nodePayload($child, $childrenById, $discoveredIds, $ownedCounts, $path))
            ->values()
            ->all();

        $rank = $this->rankFor($item);
        $displayName = $discovered ? (string) $item->name : '？？？';
        $stats = $discovered ? $this->statLines($item) : [];

        return [
            'id' => $itemId,
            'name' => $displayName,
            'rank' => $rank,
            'image' => $image,
            'discovered' => $discovered,
            'owned_count' => (int) ($ownedCounts[$itemId] ?? 0),
            'children' => $children,
            'detail' => [
                'name' => $displayName,
                'rank' => $rank,
                'type' => $item->type === 'weapon' ? '武器' : '防具',
                'family' => $discovered ? $this->familyLabel($item) : '未発見',
                'image' => asset($image),
                'discovered' => $discovered,
                'owned_count' => (int) ($ownedCounts[$itemId] ?? 0),
                'description' => $discovered
                    ? ((string) ($item->description ?: 'この装備の詳しい記録はありません。'))
                    : 'まだ発見していない装備です。',
                'stats' => $stats,
            ],
        ];
    }

    private function statLines(Item $item): array
    {
        $stats = [
            'HP' => (int) $item->hp_bonus,
            'SP' => (int) $item->mp_bonus,
            '攻撃' => (int) $item->str_bonus,
            '防御' => (int) $item->def_bonus,
            '魔力' => (int) $item->mag_bonus,
            '精神' => (int) $item->spr_bonus,
            '敏捷' => (int) $item->agi_bonus,
            '運' => (int) $item->luk_bonus,
        ];

        return collect($stats)
            ->filter(fn (int $value): bool => $value !== 0)
            ->map(fn (int $value, string $label): string => $label . ' ' . ($value > 0 ? '+' : '') . $value)
            ->values()
            ->all();
    }

    private function rankFor(Item $item): string
    {
        return strtoupper((string) (
            $item->type === 'weapon'
                ? ($item->weapon_rank ?: $item->display_rank ?: $item->rarity)
                : ($item->armor_rank ?: $item->display_rank ?: $item->rarity)
        ));
    }

    private function rankSort(string $rank): int
    {
        return self::RANK_SORT[$rank] ?? 999;
    }

    private function familyLabel(Item $item): string
    {
        return (string) (
            $item->type === 'weapon'
                ? ($item->weapon_family_name ?: $this->categoryLabel($item))
                : ($item->armor_family_name ?: $this->categoryLabel($item))
        );
    }

    private function categoryLabel(Item $item): string
    {
        if ($item->type === 'armor') {
            return (string) ($item->armor_family_name ?: '防具');
        }

        return [
            'sword' => '剣',
            'dagger' => '短剣',
            'spear' => '槍',
            'axe' => '斧・棍棒',
            'bow' => '弓',
            'staff' => '杖',
            'magic_device' => '魔導具',
            'fist' => '拳甲',
            'gun' => '銃',
            'katana' => '刀',
        ][(string) $item->weapon_category] ?? '武器';
    }

}
