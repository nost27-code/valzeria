<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\AreaDiscoveryLink;
use App\Models\City;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\JobRequirement;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Models\Recipe;
use App\Models\Title;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class ValzeriaLabWorldGraphService
{
    public const TYPE_LABELS = [
        'city' => '街',
        'area' => 'エリア',
        'enemy' => '敵',
        'equipment' => '装備',
        'item' => 'アイテム',
        'material' => '素材',
        'job' => '職業',
        'title' => '称号',
    ];

    public const ISSUE_TYPE_LABELS = [
        'missing_reference' => '参照切れ',
        'no_acquisition_path' => '入手経路なし候補',
        'no_usage_path' => '使用経路なし候補',
        'unreachable_progression' => '到達不能候補',
    ];

    /**
     * The graph intentionally avoids hydrating unrelated text/blob columns.
     * Missing optional columns are removed against the current schema so the
     * read-only lab also works while a local branch is between migrations.
     *
     * @var array<class-string, list<string>>
     */
    private const RECORD_COLUMNS = [
        City::class => [
            'id', 'name', 'is_initial', 'recommended_level_min', 'recommended_level_max',
            'unlock_condition_type', 'unlock_condition_value',
        ],
        Area::class => [
            'id', 'name', 'slug', 'area_kind', 'is_published', 'recommended_level_min',
            'recommended_level_max', 'city_id', 'unlock_required_area_id',
        ],
        Enemy::class => [
            'id', 'name', 'level', 'is_boss', 'species_key', 'family_key', 'area_id',
        ],
        Item::class => [
            'id', 'name', 'type', 'rarity', 'required_level', 'is_shop_item', 'is_active',
            'is_supply_enabled', 'is_drop_enabled', 'source_type', 'external_item_id',
            'unlock_city_id', 'next_item_external_id', 'next_armor_external_id',
            'next_accessory_external_id',
        ],
        Material::class => [
            'id', 'name', 'material_code', 'category', 'material_type', 'obtain_method',
            'main_use', 'acquisition_summary', 'acquisition_tags', 'usage_summary',
            'usage_tags', 'city_id', 'dungeon_id', 'source_area_id', 'source_enemy_id',
        ],
        JobClass::class => ['id', 'name', 'key', 'rank', 'category', 'is_active'],
        Title::class => [
            'id', 'name', 'category', 'rarity', 'unlock_type', 'target_type', 'target_id',
            'source_master', 'is_hidden',
        ],
        EnemyDrop::class => ['id', 'enemy_id', 'item_id', 'is_active', 'drop_rate'],
        MaterialDrop::class => [
            'id', 'enemy_id', 'material_id', 'is_active', 'drop_rate', 'drop_timing',
        ],
        AreaDiscoveryLink::class => [
            'id', 'from_type', 'from_id', 'to_type', 'to_id', 'condition_type',
            'requires_boss_defeated', 'required_development_point',
        ],
        JobRequirement::class => [
            'id', 'job_id', 'requirement_type', 'required_job_id', 'required_value',
            'required_key',
        ],
        Recipe::class => [
            'id', 'name', 'result_item_id', 'result_item_name', 'area_id', 'city_name',
            'materials', 'key_material_code', 'is_active',
        ],
    ];

    /** @var array<string, list<string>> */
    private array $tableColumns = [];

    /**
     * @return array{
     *   nodes: array<string, array<string, mixed>>,
     *   edges: list<array<string, mixed>>,
     *   issues: list<array<string, mixed>>,
     *   counts: array<string, mixed>
     * }
     */
    public function build(): array
    {
        $cities = $this->records(City::class);
        $areas = $this->records(Area::class);
        $enemies = $this->records(Enemy::class);
        $items = $this->records(Item::class);
        $materials = $this->records(Material::class);
        $jobs = $this->records(JobClass::class);
        $titles = $this->records(Title::class);

        $nodes = [];
        $itemNodeKeys = [];
        foreach ($cities as $city) {
            $this->addNode($nodes, 'city', (int) $city->id, (string) $city->name, 'cities', [
                '初期街' => (bool) $city->is_initial,
                '推奨Lv' => $this->levelRange($city->recommended_level_min, $city->recommended_level_max),
                '解放条件' => trim((string) ($city->unlock_condition_type ?? '')) ?: 'なし',
            ], [
                'initial' => (bool) $city->is_initial,
            ]);
        }
        foreach ($areas as $area) {
            $this->addNode($nodes, 'area', (int) $area->id, (string) $area->name, 'areas', [
                '種別' => (string) ($area->area_kind ?: 'dungeon'),
                '公開' => (bool) ($area->is_published ?? true),
                '推奨Lv' => $this->levelRange($area->recommended_level_min, $area->recommended_level_max),
                'slug' => (string) $area->slug,
            ], [
                'published' => (bool) ($area->is_published ?? true),
                'city_id' => $area->city_id !== null ? (int) $area->city_id : null,
                'required_area_id' => $area->unlock_required_area_id !== null
                    ? (int) $area->unlock_required_area_id
                    : null,
                'area_kind' => (string) ($area->area_kind ?: 'dungeon'),
            ]);
        }
        foreach ($enemies as $enemy) {
            $this->addNode($nodes, 'enemy', (int) $enemy->id, (string) $enemy->name, 'enemies', [
                'Lv' => (int) $enemy->level,
                'BOSS' => (bool) $enemy->is_boss,
                '系統' => trim((string) ($enemy->species_key ?: $enemy->family_key ?: '')) ?: '未設定',
            ], [
                'boss' => (bool) $enemy->is_boss,
                'area_id' => (int) $enemy->area_id,
            ]);
        }
        foreach ($items as $item) {
            $type = in_array((string) $item->type, ['weapon', 'armor', 'accessory'], true)
                ? 'equipment'
                : 'item';
            $key = $this->addNode($nodes, $type, (int) $item->id, (string) $item->name, 'items', [
                'DB種別' => (string) $item->type,
                'レア度' => (string) ($item->rarity ?: '未設定'),
                '必要Lv' => (int) ($item->required_level ?? 0),
                '店売り' => (bool) $item->is_shop_item,
                '有効' => (bool) $item->is_active,
            ], [
                'active' => (bool) $item->is_active,
                'shop' => (bool) $item->is_shop_item,
                'supply' => (bool) ($item->is_supply_enabled ?? false),
                'drop_enabled' => (bool) ($item->is_drop_enabled ?? false),
                'source_type' => trim((string) ($item->source_type ?? '')),
                'external_item_id' => $item->external_item_id,
            ]);
            $itemNodeKeys[(int) $item->id] = $key;
        }
        foreach ($materials as $material) {
            $this->addNode($nodes, 'material', (int) $material->id, (string) $material->name, 'materials', [
                '素材コード' => (string) $material->material_code,
                'カテゴリ' => (string) ($material->category ?: $material->material_type ?: '未設定'),
                '入手方法' => trim((string) ($material->obtain_method ?? '')) ?: '記載なし',
                '主用途' => trim((string) ($material->main_use ?? $material->usage_summary ?? '')) ?: '記載なし',
            ], [
                'material_code' => (string) $material->material_code,
                'has_acquisition_text' => $this->hasAnyValue([
                    $material->obtain_method,
                    $material->acquisition_summary,
                    $material->acquisition_tags,
                ]),
                'has_usage_text' => $this->hasAnyValue([
                    $material->main_use,
                    $material->usage_summary,
                    $material->usage_tags,
                ]),
            ]);
        }
        foreach ($jobs as $job) {
            $this->addNode($nodes, 'job', (int) $job->id, (string) $job->name, 'job_classes', [
                'key' => (string) $job->key,
                '階層' => (string) $job->rank,
                'カテゴリ' => (string) ($job->category ?: '未設定'),
                '有効' => (bool) $job->is_active,
            ], [
                'active' => (bool) $job->is_active,
                'job_key' => (string) $job->key,
            ]);
        }
        foreach ($titles as $title) {
            $this->addNode($nodes, 'title', (int) $title->id, (string) $title->name, 'titles', [
                'カテゴリ' => (string) ($title->category ?: '未設定'),
                'レア度' => (string) ($title->rarity ?: '未設定'),
                '解放種別' => (string) ($title->unlock_type ?: '未設定'),
                '対象' => trim(implode(':', array_filter([(string) $title->target_type, (string) $title->target_id]))) ?: '個別参照なし',
            ], [
                'hidden' => (bool) $title->is_hidden,
            ]);
        }

        $edges = [];
        $edgeKeys = [];
        $issues = [];
        $issueKeys = [];

        foreach ($areas as $area) {
            if ($area->city_id !== null) {
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $this->key('city', (int) $area->city_id),
                    $this->key('area', (int) $area->id),
                    'contains_area',
                    '所属エリア',
                    'confirmed',
                    'areas.city_id',
                );
            }
            if ($area->unlock_required_area_id !== null) {
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $this->key('area', (int) $area->unlock_required_area_id),
                    $this->key('area', (int) $area->id),
                    'unlocks_area',
                    'クリアで解放',
                    'confirmed',
                    'areas.unlock_required_area_id',
                    progression: true,
                );
            }
        }

        foreach ($cities as $city) {
            if ((string) $city->unlock_condition_type === 'area_cleared'
                && ctype_digit((string) $city->unlock_condition_value)
            ) {
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $this->key('area', (int) $city->unlock_condition_value),
                    $this->key('city', (int) $city->id),
                    'unlocks_city',
                    'クリアで街解放',
                    'declared',
                    'cities.unlock_condition_type / unlock_condition_value',
                    progression: true,
                );
            }
        }

        foreach ($enemies as $enemy) {
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $this->key('area', (int) $enemy->area_id),
                $this->key('enemy', (int) $enemy->id),
                'contains_enemy',
                '出現する敵',
                'confirmed',
                'enemies.area_id',
            );
        }

        foreach ($this->records(EnemyDrop::class) as $drop) {
            $target = $itemNodeKeys[(int) $drop->item_id] ?? $this->key('item', (int) $drop->item_id);
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $this->key('enemy', (int) $drop->enemy_id),
                $target,
                'drops_item',
                'アイテムドロップ',
                'confirmed',
                'enemy_drops.enemy_id / item_id',
                [
                    'active' => (bool) $drop->is_active,
                    'drop_rate' => $drop->drop_rate,
                ],
            );
        }

        foreach ($this->records(MaterialDrop::class) as $drop) {
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $this->key('enemy', (int) $drop->enemy_id),
                $this->key('material', (int) $drop->material_id),
                'drops_material',
                '素材ドロップ',
                'confirmed',
                'material_drops.enemy_id / material_id',
                [
                    'active' => (bool) $drop->is_active,
                    'drop_rate' => $drop->drop_rate,
                    'timing' => $drop->drop_timing,
                ],
            );
        }

        foreach ($this->records(AreaDiscoveryLink::class) as $link) {
            $from = $this->polymorphicWorldKey((string) $link->from_type, (int) $link->from_id);
            $to = $this->polymorphicWorldKey((string) $link->to_type, (int) $link->to_id);
            if ($from === null || $to === null) {
                $this->addIssue($issues, $issueKeys, [
                    'type' => 'missing_reference',
                    'severity' => 'warning',
                    'certainty' => 'confirmed',
                    'title' => '未対応種別の発見リンク',
                    'detail' => "{$link->from_type}:{$link->from_id} → {$link->to_type}:{$link->to_id}",
                    'evidence' => 'area_discovery_links.from_type / to_type',
                    'node_key' => null,
                ]);
                continue;
            }
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $from,
                $to,
                'discovery_unlock',
                '発見リンク',
                'declared',
                'area_discovery_links.from_type/from_id / to_type/to_id',
                [
                    'condition_type' => $link->condition_type,
                    'requires_boss_defeated' => (bool) $link->requires_boss_defeated,
                    'required_development_point' => $link->required_development_point,
                ],
                progression: true,
            );
        }

        foreach ($this->records(JobRequirement::class) as $requirement) {
            if ($requirement->required_job_id === null) {
                continue;
            }
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $this->key('job', (int) $requirement->required_job_id),
                $this->key('job', (int) $requirement->job_id),
                'job_requirement',
                '転職要件',
                'confirmed',
                'job_requirements.required_job_id / job_id',
                [
                    'requirement_type' => $requirement->requirement_type,
                    'required_value' => $requirement->required_value,
                    'required_key' => $requirement->required_key,
                ],
            );
        }

        $this->addMaterialSourceEdges($materials, $nodes, $edges, $edgeKeys, $issues, $issueKeys);
        $this->addItemEdges($items, $itemNodeKeys, $nodes, $edges, $edgeKeys, $issues, $issueKeys);
        $this->addRecipeEdges($items, $materials, $cities, $itemNodeKeys, $nodes, $edges, $edgeKeys, $issues, $issueKeys);
        $this->addTitleEdges($titles, $jobs, $nodes, $edges, $edgeKeys, $issues, $issueKeys);
        $this->addFerdiaConfigEdges($nodes, $edges, $edgeKeys, $issues, $issueKeys);

        $this->addPathIssues($nodes, $edges, $issues, $issueKeys);
        $this->addProgressionIssues($nodes, $edges, $issues, $issueKeys);

        uasort($nodes, fn (array $left, array $right): int => [
            array_search($left['type'], array_keys(self::TYPE_LABELS), true),
            $left['master_id'],
        ] <=> [
            array_search($right['type'], array_keys(self::TYPE_LABELS), true),
            $right['master_id'],
        ]);
        usort($edges, fn (array $left, array $right): int => [$left['from'], $left['to'], $left['relation'], $left['evidence']]
            <=> [$right['from'], $right['to'], $right['relation'], $right['evidence']]);
        usort($issues, fn (array $left, array $right): int => [$left['type'], $left['title'], $left['detail']]
            <=> [$right['type'], $right['title'], $right['detail']]);

        return [
            'nodes' => $nodes,
            'edges' => array_values($edges),
            'issues' => array_values($issues),
            'counts' => [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'issues' => count($issues),
                'by_type' => collect($nodes)->countBy('type')->all(),
                'by_certainty' => collect($edges)->countBy('certainty')->all(),
                'by_issue' => collect($issues)->countBy('type')->all(),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function filterNodes(array $graph, string $search = '', string $type = 'all'): Collection
    {
        $needle = mb_strtolower(trim($search));

        return collect($graph['nodes'])
            ->filter(fn (array $node): bool => $type === 'all' || $node['type'] === $type)
            ->filter(function (array $node) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }
                $haystack = mb_strtolower(implode(' ', [
                    $node['key'],
                    $node['name'],
                    $node['source'],
                    ...array_map(static fn ($value): string => is_bool($value)
                        ? ($value ? 'true' : 'false')
                        : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)), $node['attributes']),
                ]));

                return str_contains($haystack, $needle);
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function filterIssues(array $graph, string $type = 'all'): Collection
    {
        return collect($graph['issues'])
            ->filter(fn (array $issue): bool => $type === 'all' || $issue['type'] === $type)
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function detail(array $graph, ?string $key): ?array
    {
        if ($key === null || ! isset($graph['nodes'][$key])) {
            return null;
        }

        return [
            'node' => $graph['nodes'][$key],
            'incoming' => collect($graph['edges'])->where('to', $key)->values()->all(),
            'outgoing' => collect($graph['edges'])->where('from', $key)->values()->all(),
            'issues' => collect($graph['issues'])->where('node_key', $key)->values()->all(),
        ];
    }

    private function addNode(
        array &$nodes,
        string $type,
        int $masterId,
        string $name,
        string $source,
        array $attributes,
        array $flags = [],
    ): string {
        $key = $this->key($type, $masterId);
        $nodes[$key] = [
            'key' => $key,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'master_id' => $masterId,
            'name' => $name,
            'source' => $source,
            'attributes' => $attributes,
            'flags' => $flags,
        ];

        return $key;
    }

    private function connect(
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
        string $from,
        string $to,
        string $relation,
        string $label,
        string $certainty,
        string $evidence,
        array $metadata = [],
        bool $progression = false,
    ): void {
        if (! isset($nodes[$from]) || ! isset($nodes[$to])) {
            $missing = array_values(array_filter([$from, $to], fn (string $key): bool => ! isset($nodes[$key])));
            $this->addIssue($issues, $issueKeys, [
                'type' => 'missing_reference',
                'severity' => $certainty === 'confirmed' ? 'error' : 'warning',
                'certainty' => 'confirmed',
                'title' => '参照先マスタが存在しない',
                'detail' => "{$from} → {$to}（欠落: ".implode(', ', $missing).'）',
                'evidence' => $evidence,
                'node_key' => isset($nodes[$from]) ? $from : (isset($nodes[$to]) ? $to : null),
            ]);

            return;
        }

        $edgeKey = implode('|', [$from, $to, $relation, $evidence]);
        if (isset($edgeKeys[$edgeKey])) {
            return;
        }
        $edgeKeys[$edgeKey] = true;
        $edges[] = [
            'id' => sha1($edgeKey),
            'from' => $from,
            'from_name' => $nodes[$from]['name'],
            'to' => $to,
            'to_name' => $nodes[$to]['name'],
            'relation' => $relation,
            'label' => $label,
            'certainty' => $certainty,
            'certainty_label' => $certainty === 'confirmed' ? '明示参照' : '宣言参照',
            'evidence' => $evidence,
            'metadata' => $metadata,
            'progression' => $progression,
        ];
    }

    private function addMaterialSourceEdges(
        Collection $materials,
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
    ): void {
        foreach ($materials as $material) {
            $target = $this->key('material', (int) $material->id);
            foreach ([
                ['column' => 'city_id', 'type' => 'city'],
                ['column' => 'dungeon_id', 'type' => 'area'],
                ['column' => 'source_area_id', 'type' => 'area'],
                ['column' => 'source_enemy_id', 'type' => 'enemy'],
            ] as $source) {
                $sourceId = $material->getAttribute($source['column']);
                if ($sourceId === null || (int) $sourceId <= 0) {
                    continue;
                }
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $this->key($source['type'], (int) $sourceId),
                    $target,
                    'material_source',
                    '素材の入手元',
                    'declared',
                    "materials.{$source['column']}",
                );
            }
        }
    }

    private function addItemEdges(
        Collection $items,
        array $itemNodeKeys,
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
    ): void {
        $byExternalId = $items
            ->filter(fn (Item $item): bool => $item->external_item_id !== null && (string) $item->external_item_id !== '')
            ->groupBy(fn (Item $item): string => (string) $item->external_item_id);

        foreach ($items as $item) {
            $target = $itemNodeKeys[(int) $item->id];
            if ($item->unlock_city_id !== null) {
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $this->key('city', (int) $item->unlock_city_id),
                    $target,
                    'sold_in_city',
                    '街で解放',
                    'declared',
                    'items.unlock_city_id',
                );
            }

            foreach (['next_item_external_id', 'next_armor_external_id', 'next_accessory_external_id'] as $column) {
                $externalId = trim((string) ($item->getAttribute($column) ?? ''));
                if ($externalId === '') {
                    continue;
                }
                $next = $byExternalId->get($externalId)?->first();
                $nextKey = $next ? $itemNodeKeys[(int) $next->id] : "equipment:external:{$externalId}";
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $target,
                    $nextKey,
                    'evolves_to',
                    '進化先',
                    'declared',
                    "items.{$column} → items.external_item_id",
                );
            }
        }
    }

    private function addRecipeEdges(
        Collection $items,
        Collection $materials,
        Collection $cities,
        array $itemNodeKeys,
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
    ): void {
        if (! Schema::hasTable('recipes')) {
            return;
        }

        $materialsByCode = $materials->keyBy(fn (Material $material): string => (string) $material->material_code);
        $citiesByName = $cities->keyBy('name');
        $itemsById = $items->keyBy('id');
        $itemsByName = $items->groupBy('name');

        foreach ($this->records(Recipe::class) as $recipe) {
            $resultItem = $recipe->result_item_id !== null
                ? $itemsById->get((int) $recipe->result_item_id)
                : $itemsByName->get($recipe->result_item_name)?->first();
            if (! $resultItem) {
                $this->addIssue($issues, $issueKeys, [
                    'type' => 'missing_reference',
                    'severity' => 'error',
                    'certainty' => 'confirmed',
                    'title' => 'レシピ完成品が存在しない',
                    'detail' => (string) $recipe->name.' → '.(string) $recipe->result_item_name,
                    'evidence' => 'recipes.result_item_id / result_item_name',
                    'node_key' => null,
                ]);
                continue;
            }
            $target = $itemNodeKeys[(int) $resultItem->id];
            $metadata = ['recipe' => $recipe->name, 'active' => (bool) $recipe->is_active];

            if ($recipe->area_id !== null) {
                $this->connect($nodes, $edges, $edgeKeys, $issues, $issueKeys,
                    $this->key('area', (int) $recipe->area_id), $target, 'recipe_result', '作成できる品',
                    'declared', 'recipes.area_id / result_item_id', $metadata);
            }
            if ($recipe->city_name && ($city = $citiesByName->get($recipe->city_name))) {
                $this->connect($nodes, $edges, $edgeKeys, $issues, $issueKeys,
                    $this->key('city', (int) $city->id), $target, 'recipe_result', '作成できる品',
                    'declared', 'recipes.city_name / result_item_id', $metadata);
            }

            $recipeMaterials = is_array($recipe->materials) ? $recipe->materials : [];
            $codes = collect($recipeMaterials)
                ->pluck('material_code')
                ->push($recipe->key_material_code)
                ->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
                ->unique();
            foreach ($codes as $code) {
                $material = $materialsByCode->get((string) $code);
                $source = $material ? $this->key('material', (int) $material->id) : 'material:code:'.(string) $code;
                $recipeMaterial = collect($recipeMaterials)->firstWhere('material_code', $code);
                $quantity = is_array($recipeMaterial) ? ($recipeMaterial['quantity'] ?? null) : null;
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $source,
                    $target,
                    'recipe_material',
                    '合成素材',
                    'declared',
                    'recipes.materials / key_material_code',
                    [...$metadata, 'quantity' => $quantity],
                );
            }
        }
    }

    private function addTitleEdges(
        Collection $titles,
        Collection $jobs,
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
    ): void {
        $jobByName = $jobs->keyBy('name');
        foreach ($titles as $title) {
            $source = match ((string) $title->target_type) {
                'city' => ctype_digit((string) $title->target_id)
                    ? $this->key('city', (int) $title->target_id)
                    : null,
                'dungeon' => ctype_digit((string) $title->target_id)
                    ? $this->key('area', (int) $title->target_id)
                    : null,
                'job_name' => ($job = $jobByName->get((string) $title->target_id))
                    ? $this->key('job', (int) $job->id)
                    : ((string) $title->target_id !== '' ? 'job:name:'.(string) $title->target_id : null),
                default => null,
            };
            if ($source === null) {
                continue;
            }
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $source,
                $this->key('title', (int) $title->id),
                'unlocks_title',
                '称号解放対象',
                'declared',
                'titles.target_type / target_id'.($title->source_master ? " ({$title->source_master})" : ''),
            );
        }
    }

    private function addFerdiaConfigEdges(
        array $nodes,
        array &$edges,
        array &$edgeKeys,
        array &$issues,
        array &$issueKeys,
    ): void {
        $config = config('ferdia_world_map', []);
        if (! is_array($config) || ! is_array($config['nodes'] ?? null)) {
            return;
        }

        $refs = [];
        foreach ($config['nodes'] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $nodeKey = (string) ($node['key'] ?? '');
            if ($nodeKey === '') {
                continue;
            }
            if (! empty($node['area_id'])) {
                $refs[$nodeKey] = $this->key('area', (int) $node['area_id']);
            } elseif (! empty($node['city_id'])) {
                $refs[$nodeKey] = $this->key('city', (int) $node['city_id']);
            }
        }

        foreach ($config['nodes'] as $node) {
            $nodeKey = is_array($node) ? (string) ($node['key'] ?? '') : '';
            if ($nodeKey === '' || ! isset($refs[$nodeKey]) || ! is_array($node['unlock'] ?? null)) {
                continue;
            }
            $unlock = $node['unlock'];
            $sources = match ((string) ($unlock['type'] ?? '')) {
                'region_unlocked' => ! empty($config['entry_requirement']['area_id'])
                    ? [$this->key('area', (int) $config['entry_requirement']['area_id'])]
                    : [],
                'node_development', 'node_boss_defeated' => isset($refs[(string) ($unlock['node_key'] ?? '')])
                    ? [$refs[(string) $unlock['node_key']]]
                    : ['config-node:'.(string) ($unlock['node_key'] ?? '')],
                'city_discovered' => isset($refs[(string) ($unlock['node_key'] ?? '')])
                    ? [$refs[(string) $unlock['node_key']]]
                    : ['config-node:'.(string) ($unlock['node_key'] ?? '')],
                'all_nodes_completed' => collect($unlock['node_keys'] ?? [])
                    ->map(fn ($key): string => $refs[(string) $key] ?? 'config-node:'.(string) $key)
                    ->all(),
                default => [],
            };
            foreach ($sources as $source) {
                $this->connect(
                    $nodes,
                    $edges,
                    $edgeKeys,
                    $issues,
                    $issueKeys,
                    $source,
                    $refs[$nodeKey],
                    'ferdia_unlock',
                    '大陸マップ解放条件',
                    'declared',
                    "config/ferdia_world_map.php nodes.{$nodeKey}.unlock",
                    ['condition' => $unlock],
                    progression: true,
                );
            }
        }

        foreach ($config['routes'] ?? [] as $index => $route) {
            if (! is_array($route)) {
                continue;
            }
            $fromKey = (string) ($route['from'] ?? '');
            $toKey = (string) ($route['to'] ?? '');
            if ($fromKey === '' || $toKey === '') {
                continue;
            }
            $this->connect(
                $nodes,
                $edges,
                $edgeKeys,
                $issues,
                $issueKeys,
                $refs[$fromKey] ?? "config-node:{$fromKey}",
                $refs[$toKey] ?? "config-node:{$toKey}",
                'ferdia_route',
                '大陸マップ経路',
                'declared',
                "config/ferdia_world_map.php routes.{$index}",
                ['group' => $route['group'] ?? null],
                progression: true,
            );
        }
    }

    private function addPathIssues(
        array $nodes,
        array $edges,
        array &$issues,
        array &$issueKeys,
    ): void {
        $activeEdges = collect($edges)->filter(fn (array $edge): bool => ($edge['metadata']['active'] ?? true) !== false);
        $incomingAcquisition = $activeEdges
            ->filter(fn (array $edge): bool => in_array($edge['relation'], [
                'drops_item', 'drops_material', 'material_source', 'sold_in_city',
                'evolves_to', 'recipe_result',
            ], true))
            ->groupBy('to');
        $materialUsage = $activeEdges->where('relation', 'recipe_material')->groupBy('from');

        foreach ($nodes as $key => $node) {
            if (in_array($node['type'], ['equipment', 'item'], true)
                && ($node['flags']['active'] ?? false)
                && ! $incomingAcquisition->has($key)
                && ! ($node['flags']['shop'] ?? false)
                && ! ($node['flags']['supply'] ?? false)
                && trim((string) ($node['flags']['source_type'] ?? '')) === ''
            ) {
                $this->addIssue($issues, $issueKeys, [
                    'type' => 'no_acquisition_path',
                    'severity' => 'info',
                    'certainty' => 'candidate',
                    'title' => '入手経路が見つからない候補',
                    'detail' => "{$node['type_label']}「{$node['name']}」に、drop・店・合成・進化・供給の明示経路がありません。",
                    'evidence' => 'items + enemy_drops + recipes + evolution columns（候補判定）',
                    'node_key' => $key,
                ]);
            }

            if ($node['type'] === 'material') {
                if (! $incomingAcquisition->has($key) && ! ($node['flags']['has_acquisition_text'] ?? false)) {
                    $this->addIssue($issues, $issueKeys, [
                        'type' => 'no_acquisition_path',
                        'severity' => 'info',
                        'certainty' => 'candidate',
                        'title' => '素材の入手経路が見つからない候補',
                        'detail' => "素材「{$node['name']}」にdrop・入手元・入手説明がありません。",
                        'evidence' => 'materials + material_drops（候補判定）',
                        'node_key' => $key,
                    ]);
                }
                if (! $materialUsage->has($key) && ! ($node['flags']['has_usage_text'] ?? false)) {
                    $this->addIssue($issues, $issueKeys, [
                        'type' => 'no_usage_path',
                        'severity' => 'info',
                        'certainty' => 'candidate',
                        'title' => '素材の使用経路が見つからない候補',
                        'detail' => "素材「{$node['name']}」にrecipe・主用途・用途説明がありません。",
                        'evidence' => 'materials + recipes.materials（候補判定）',
                        'node_key' => $key,
                    ]);
                }
            }
        }
    }

    private function addProgressionIssues(
        array $nodes,
        array $edges,
        array &$issues,
        array &$issueKeys,
    ): void {
        $roots = collect($nodes)
            ->filter(fn (array $node): bool => $node['type'] === 'city' && ($node['flags']['initial'] ?? false))
            ->keys()
            ->all();
        $initialCityIds = collect($nodes)
            ->filter(fn (array $node): bool => $node['type'] === 'city' && ($node['flags']['initial'] ?? false))
            ->pluck('master_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        foreach ($nodes as $key => $node) {
            if ($node['type'] === 'area'
                && ($node['flags']['published'] ?? false)
                && ($node['flags']['required_area_id'] ?? null) === null
                && in_array($node['flags']['city_id'] ?? null, $initialCityIds, true)
            ) {
                $roots[] = $key;
            }
        }

        $adjacency = collect($edges)
            ->where('progression', true)
            ->groupBy('from')
            ->map(fn (Collection $group): array => $group->pluck('to')->unique()->values()->all())
            ->all();
        $reachable = [];
        $queue = array_values(array_unique($roots));
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reachable[$current])) {
                continue;
            }
            $reachable[$current] = true;
            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reachable[$next])) {
                    $queue[] = $next;
                }
            }
        }

        foreach ($nodes as $key => $node) {
            if ($node['type'] !== 'area'
                || ! ($node['flags']['published'] ?? false)
                || isset($reachable[$key])
            ) {
                continue;
            }
            $this->addIssue($issues, $issueKeys, [
                'type' => 'unreachable_progression',
                'severity' => 'info',
                'certainty' => 'candidate',
                'title' => '明示進行リンクで到達できない候補',
                'detail' => "公開エリア「{$node['name']}」へ、初期街・初期エリアからの明示解放リンクがつながりません。直接開放など別経路の確認が必要です。",
                'evidence' => 'areas.unlock_required_area_id + area_discovery_links + cities.unlock_condition + ferdia config（候補判定）',
                'node_key' => $key,
            ]);
        }
    }

    private function addIssue(array &$issues, array &$issueKeys, array $issue): void
    {
        $key = sha1(implode('|', [
            $issue['type'],
            $issue['title'],
            $issue['detail'],
            $issue['evidence'],
        ]));
        if (isset($issueKeys[$key])) {
            return;
        }
        $issueKeys[$key] = true;
        $issue['id'] = $key;
        $issue['type_label'] = self::ISSUE_TYPE_LABELS[$issue['type']] ?? $issue['type'];
        $issues[] = $issue;
    }

    private function polymorphicWorldKey(string $type, int $id): ?string
    {
        return match ($type) {
            'city' => $this->key('city', $id),
            'area', 'dungeon' => $this->key('area', $id),
            default => null,
        };
    }

    private function key(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }

    private function levelRange(mixed $min, mixed $max): string
    {
        return 'Lv'.max(1, (int) $min).'〜'.max(1, (int) $max);
    }

    private function hasAnyValue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (! is_array($value) && trim((string) ($value ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, object> */
    private function records(string $model): Collection
    {
        $instance = new $model;
        $table = $instance->getTable();
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $available = $this->tableColumns[$table] ??= Schema::getColumnListing($table);
        $columns = array_values(array_intersect(
            self::RECORD_COLUMNS[$model] ?? [$instance->getKeyName()],
            $available,
        ));

        return $model::query()->get($columns === [] ? [$instance->getKeyName()] : $columns);
    }
}
