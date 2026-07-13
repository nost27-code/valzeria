<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\AreaDiscoveryLink;
use App\Models\City;
use App\Models\Enemy;
use App\Models\EnemyAction;
use App\Services\Enemy\EnemyStatGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FerdiaRegionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCities();
        $areas = $this->seedAreas();
        $this->seedEnemies($areas);
        $this->seedBosses($areas);
        $this->seedDiscoveryLinks();
        $this->seedStoryFinalLinks();

        $this->command?->info('フェルディア地方の街・探索地・敵・発見リンクを登録しました。');
    }

    public function seedStoryBranches(): void
    {
        $story = (array) config('ferdia_world_map.story_final_unlock', []);
        $keys = array_values(array_unique(array_filter([
            ...((array) ($story['required_node_keys'] ?? [])),
            (string) ($story['final_node_key'] ?? ''),
        ])));
        $nodes = collect(config('ferdia_world_map.nodes', []))
            ->filter(fn (array $node): bool => in_array((string) ($node['key'] ?? ''), $keys, true))
            ->values()
            ->all();

        $areas = $this->seedAreas($nodes);
        $this->seedEnemies($areas);
        $this->seedDiscoveryLinks($nodes);
        $this->seedStoryFinalLinks();
    }

    public function seedAreaMaster(int $areaId): void
    {
        $nodes = collect(config('ferdia_world_map.nodes', []))
            ->filter(fn (array $node): bool => (int) ($node['area_id'] ?? 0) === $areaId)
            ->values()
            ->all();

        if ($nodes === []) {
            return;
        }

        $areas = $this->seedAreas($nodes);
        $this->seedEnemies($areas);
    }

    private function seedCities(): void
    {
        foreach (config('ferdia_world_map.cities', []) as $city) {
            DB::table('cities')->updateOrInsert(
                ['id' => (int) $city['id']],
                [
                    'name' => $city['name'],
                    'description' => $city['description'],
                    'recommended_level_min' => (int) $city['recommended_level_min'],
                    'recommended_level_max' => (int) $city['recommended_level_max'],
                    'sort_order' => (int) $city['sort_order'],
                    'is_initial' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array<int, Area>
     */
    private function seedAreas(?array $nodes = null): array
    {
        $areas = [];
        $levels = $this->levelsByAreaId();

        foreach ($nodes ?? config('ferdia_world_map.nodes', []) as $node) {
            $areaId = (int) ($node['area_id'] ?? 0);
            if ($areaId <= 0) {
                continue;
            }

            [$min, $max] = $levels[$areaId] ?? [142, 144];
            $cityId = $this->cityIdForArea($areaId);
            $terrain = $this->terrainLabel((string) ($node['node_type'] ?? 'road'));
            $description = $this->descriptionForNode($node, $terrain);

            $areas[$areaId] = Area::updateOrCreate(
                ['id' => $areaId],
                [
                    'city_id' => $cityId,
                    'name' => (string) $node['name'],
                    'slug' => 'ferdia_' . (string) $node['key'],
                    'description' => $description,
                    'recommended_level_min' => $min,
                    'recommended_level_max' => $max,
                    'unlock_order' => (int) ($node['sequence'] ?? 99),
                    'unlock_required_area_id' => null,
                    'area_kind' => 'outer_world',
                    'clear_condition_type' => 'development_point',
                    'development_required_point' => (int) ($node['max_development_point'] ?? 100),
                    'is_route_area' => false,
                    'sort_order' => ($cityId * 100) + (int) ($node['sequence'] ?? 99),
                ]
            );
        }

        return $areas;
    }

    /**
     * @param array<int, Area> $areas
     */
    private function seedEnemies(array $areas): void
    {
        foreach ($areas as $area) {
            $definitions = $this->enemyDefinitionsFor($area);
            foreach ($definitions as $index => $definition) {
                $level = min(
                    (int) $area->recommended_level_max,
                    (int) $area->recommended_level_min + (int) ($definition['level_offset'] ?? 0)
                );

                $enemy = Enemy::updateOrCreate(
                    ['area_id' => (int) $area->id, 'name' => (string) $definition['name']],
                    $this->enemyValues($area, $definition, $level, $index + 1)
                );
                $this->seedEnemyActions($enemy, $area);
            }
        }
    }

    public function seedDiscoveryLinks(?array $nodes = null): void
    {
        foreach ($nodes ?? config('ferdia_world_map.nodes', []) as $node) {
            $this->seedUnlockLink($node);
        }
    }

    private function seedStoryFinalLinks(): void
    {
        $story = (array) config('ferdia_world_map.story_final_unlock', []);
        $target = $this->nodeByKey((string) ($story['final_node_key'] ?? ''));
        $targetAreaId = (int) ($target['area_id'] ?? 0);
        if ($targetAreaId <= 0) {
            return;
        }

        foreach (array_values((array) ($story['required_node_keys'] ?? [])) as $index => $sourceKey) {
            $source = $this->nodeByKey((string) $sourceKey);
            $sourceAreaId = (int) ($source['area_id'] ?? 0);
            if ($sourceAreaId <= 0) {
                continue;
            }

            $this->upsertLink(
                'area',
                $sourceAreaId,
                'area',
                $targetAreaId,
                'all_story_branches_cleared',
                null,
                null,
                2600 + (int) $index
            );
        }
    }

    private function seedUnlockLink(array $node): void
    {
        $targetType = !empty($node['city_id']) ? 'city' : (!empty($node['area_id']) ? 'area' : null);
        $targetId = (int) ($node['city_id'] ?? $node['area_id'] ?? 0);
        if (!$targetType || $targetId <= 0) {
            return;
        }

        $unlock = $node['unlock'] ?? [];
        $conditionType = (string) ($unlock['type'] ?? '');
        if ($conditionType === 'region_unlocked') {
            return;
        }

        if ($conditionType === 'city_discovered') {
            $sourceNode = $this->nodeByKey((string) ($unlock['node_key'] ?? ''));
            $sourceCityId = (int) ($sourceNode['city_id'] ?? 0);
            if ($sourceCityId <= 0) {
                return;
            }

            $this->upsertLink('city', $sourceCityId, $targetType, $targetId, 'city_discovered', null, null, (int) ($node['sequence'] ?? 0));
            return;
        }

        if (in_array($conditionType, ['node_development', 'node_boss_defeated'], true)) {
            $sourceNode = $this->nodeByKey((string) ($unlock['node_key'] ?? ''));
            $sourceAreaId = (int) ($sourceNode['area_id'] ?? 0);
            if ($sourceAreaId <= 0) {
                return;
            }

            $this->upsertLink(
                'area',
                $sourceAreaId,
                $targetType,
                $targetId,
                $conditionType === 'node_boss_defeated' ? 'boss_defeated' : 'development_point',
                $conditionType === 'node_boss_defeated' ? null : (int) ($unlock['required_point'] ?? 100),
                $this->rumorTextFor($node),
                2000 + (int) ($node['sequence'] ?? 0),
                $conditionType === 'node_boss_defeated'
            );
        }
    }

    private function upsertLink(
        string $fromType,
        int $fromId,
        string $toType,
        int $toId,
        string $conditionType,
        ?int $requiredPoint,
        ?string $rumorText,
        int $sortOrder,
        bool $requiresBossDefeated = false
    ): void {
        AreaDiscoveryLink::updateOrCreate(
            [
                'from_type' => $fromType,
                'from_id' => $fromId,
                'to_type' => $toType,
                'to_id' => $toId,
            ],
            [
                'condition_type' => $conditionType,
                'required_development_point' => $requiredPoint,
                'requires_boss_defeated' => $requiresBossDefeated,
                'rumor_text' => $rumorText,
                'implementation_phase' => 'Ferdia MVP',
                'sort_order' => $sortOrder,
            ]
        );
    }

    private function nodeByKey(string $key): ?array
    {
        foreach (config('ferdia_world_map.nodes', []) as $node) {
            if ((string) ($node['key'] ?? '') === $key) {
                return $node;
            }
        }

        return null;
    }

    private function cityIdForArea(int $areaId): int
    {
        if (in_array($areaId, [1001, 1002, 1003, 1004, 1005, 1006, 1007, 1025], true)) {
            return 101;
        }

        if (in_array($areaId, [1008, 1009, 1027], true)) {
            return 102;
        }

        return 103;
    }

    private function levelsByAreaId(): array
    {
        return [
            1001 => [142, 144],
            1002 => [144, 146],
            1003 => [146, 148],
            1004 => [148, 150],
            1005 => [150, 152],
            1006 => [152, 154],
            1007 => [154, 156],
            1008 => [156, 158],
            1009 => [158, 160],
            1010 => [160, 162],
            1011 => [162, 164],
            1012 => [164, 166],
            1013 => [166, 168],
            1025 => [152, 154],
            1026 => [160, 162],
            1027 => [158, 160],
            1028 => [166, 168],
            1029 => [175, 180],
        ];
    }

    private function terrainLabel(string $nodeType): string
    {
        return match ($nodeType) {
            'landing' => '上陸地点',
            'road' => '街道',
            'river' => '水辺',
            'ruin' => '遺跡',
            'forest' => '森',
            'castle' => '聖域',
            'mountain' => '霊峰',
            'branch' => '支線',
            default => '外大陸',
        };
    }

    private function descriptionForNode(array $node, string $terrain): string
    {
        $name = (string) ($node['name'] ?? 'フェルディアの道');

        return "【{$terrain}】 {$name}を進み、外大陸フェルディアの道筋を少しずつ開拓する。";
    }

    private function rumorTextFor(array $node): string
    {
        return match ((string) ($node['node_type'] ?? '')) {
            'city', 'port' => '街の屋根と灯りが遠くに見える。',
            'castle' => '木々の向こうに大樹の城影が見える。',
            'mountain' => '遥か北に白い霊峰が見える。',
            default => '道の先に新しい気配がある。',
        };
    }

    private function enemyDefinitionsFor(Area $area): array
    {
        $id = $area->id;
        $t = function($name, $element, $family, $variant, $roleKey, $role, $typeName, $weight, $offset) {
            return [
                'name' => $name, 'family_key' => $family, 'variant_key' => $variant,
                'role_key' => $roleKey, 'role' => $role, 'type_name' => $typeName,
                'element' => $element, 'weight' => $weight, 'level_offset' => $offset
            ];
        };

        $s = function($name, $element) use ($t) { return $t($name, $element, 'slime', 'forest', 'normal_weak', 'フェルディアの小魔物', 'スライム', 30, 0); };
        $b = function($name, $element) use ($t) { return $t($name, $element, 'beast', 'forest', 'normal', 'フェルディアの獣', '獣', 26, 0); };
        $h = function($name, $element) use ($t) { return $t($name, $element, 'soldier', 'ancient', 'normal', '古王国の影', '人型', 20, 1); };
        $g = function($name, $element) use ($t) { return $t($name, $element, 'giant', 'ancient', 'strong', 'フェルディアの強敵', '巨人', 8, 2); };

        $add = function($name, $element, $family, $typeName) use ($t) {
            return $t($name, $element, $family, 'forest', 'normal', 'フェルディアの魔物', $typeName, 16, 0);
        };

        return match ($id) {
            1001 => [
                $s('潮溜まりの粘体', '水'), $b('コースト・ハウンド', '水'),
                $h('漂着した古王国兵', '古代'), $g('砂浜の防人像', '古代'),
                $add('コースト・クラブ', '水', 'aquatic', '水棲')
            ],
            1002 => [
                $s('海風のウーズ', '風'), $b('街道の野犬', '風'),
                $h('迷い道の亡霊兵', '古代'), $g('風化せし土偶', '古代'),
                $add('シー・ガル', '風', 'flying', '飛行')
            ],
            1003 => [
                $s('サニー・スライム', '光'), $b('丘駆けの群れ狼', '風'),
                $h('丘陵の斥候影', '古代'), $g('境界の石兵', '土'),
                $add('ヒル・ホーク', '風', 'flying', '飛行')
            ],
            1004 => [
                $s('清流のゼリー', '水'), $b('アクア・ファング', '水'),
                $h('水晶の守護兵', '水'), $g('河流のゴーレム', '水'),
                $add('アクア・ウィスプ', '水', 'spirit', '精霊')
            ],
            1005 => [
                $s('苔むした粘液', '森'), $b('ブリッジ・ウルフ', '風'),
                $h('石橋の亡霊騎士', '古代'), $g('橋守の巨像', '土'),
                $add('ポイズン・モス', '毒', 'insect', '昆虫')
            ],
            1006 => [
                $s('古代遺跡のウーズ', '古代'), $b('ルインズ・ドッグ', '闇'),
                $h('アーデルの彷徨う影', '古代'), $g('古代兵器ゴーレム', '古代'),
                $add('ルイン・ギア', '古代', 'machine', '機械')
            ],
            1007 => [
                $s('堀底のスライム', '水'), $b('城壁の黒犬', '闇'),
                $h('崩れかけの衛兵', '古代'), $g('外郭の防人', '古代'),
                $add('外郭の浮遊霊', '闇', 'undead', '不死')
            ],
            1008 => [
                $s('メイア・ゼリー', '森'), $b('河畔の疾風狼', '風'),
                $h('迷子の亡霊兵', '古代'), $g('河畔の土塊', '土'),
                $add('河畔のキラービー', '風', 'insect', '昆虫')
            ],
            1009 => [
                $s('運河のウーズ', '水'), $b('水辺の野犬', '水'),
                $h('水門の斥候影', '古代'), $g('門番のゴーレム', '土'),
                $add('運河の大蛙', '水', 'aquatic', '水棲')
            ],
            1010 => [
                $s('ディープ・ウーズ', '闇'), $b('森奥の黒狼', '森'),
                $h('森林の亡霊', '古代'), $g('森の番人像', '森'),
                $add('森のピクシー', '森', 'spirit', '妖精')
            ],
            1011 => [
                $s('聖緑のスライム', '光'), $b('聖域の守護犬', '光'),
                $h('聖城の幻影兵', '光'), $g('城壁の白き番人', '光'),
                $add('聖城の光霊', '光', 'spirit', '精霊')
            ],
            1012 => [
                $s('聖樹のゼリー', '光'), $b('聖堂の銀狼', '光'),
                $h('大樹の精霊騎士', '光'), $g('聖域の巨神像', '光'),
                $add('セレスティアル', '光', 'flying', '飛行')
            ],
            1013 => [
                $s('スノー・スライム', '氷'), $b('アイス・ハウンド', '氷'),
                $h('氷結の亡霊', '氷'), $g('雪山の霜巨人', '氷'),
                $add('スノー・インプ', '氷', 'demon', '悪魔')
            ],
            1025 => [
                $s('星屑のウーズ', '光'), $b('廃塔の夜狼', '闇'),
                $h('星詠みの亡霊', '古代'), $g('星環の石巨人', '土'),
                $add('星灯りのフクロウ', '光', 'flying', '飛行')
            ],
            1026 => [
                $s('滝壺のゼリー', '水'), $b('瀑布の水狼', '水'),
                $h('水鏡の巫女影', '水'), $g('水脈の守護巨人', '水'),
                $add('神殿の水精', '水', 'aquatic', '水棲')
            ],
            1027 => [
                $s('列柱の砂塵', '土'), $b('石畳の狩猟犬', '風'),
                $h('オルドの幻影兵', '古代'), $g('崩塔の石巨人', '土'),
                $add('列柱の歯車兵', '古代', 'machine', '機械')
            ],
            1028 => [
                $s('白潮の泡魔', '水'), $b('灯台の海犬', '水'),
                $h('航路の亡霊船員', '古代'), $g('白潮の石巨人', '水'),
                $add('ランタン・ガル', '風', 'flying', '飛行')
            ],
            1029 => [
                $s('アビスの粘体', '闇'), array_merge($b('深穴の魔狼', '闇'), ['level_offset' => 1]),
                array_merge($h('深層の案内人影', '古代'), ['level_offset' => 2]), array_merge($g('奈落の巨兵', '闇'), ['level_offset' => 5]),
                array_merge($add('虚穴の飛魔', '闇', 'demon', '悪魔'), ['level_offset' => 4])
            ],
            default => [
                $s('フェルディアの粘体', '森'), $b('フェルディアの獣', '風'),
                $h('フェルディアの影', '古代'), $g('フェルディアの巨兵', '古代'),
                $add('フェルディアの羽虫', '森', 'insect', '昆虫')
            ],
        };
    }

    /**
     * @param array<int, Area>|null $areas
     */
    public function seedBosses(?array $areas = null): void
    {
        $bosses = (array) config('ferdia_world_map.bosses', []);
        if ($bosses === []) {
            return;
        }

        $areas ??= Area::whereIn('id', array_map('intval', array_keys($bosses)))->get()->keyBy('id')->all();
        foreach ($bosses as $areaId => $boss) {
            $area = $areas[(int) $areaId] ?? null;
            if (!$area instanceof Area) {
                continue;
            }

            $level = (int) $area->recommended_level_max;
            $definition = [
                'name' => (string) $boss['name'],
                'family_key' => (string) $boss['family_key'],
                'variant_key' => (string) $boss['variant_key'],
                'role_key' => 'boss',
                'role' => 'フェルディアの関門ボス',
                'type_name' => (string) $boss['type_name'],
                'element' => (string) $boss['element'],
                'weight' => 0,
            ];
            $values = $this->enemyValues($area, $definition, $level, 99);
            $values['is_boss'] = true;
            $values['appearance_weight'] = 0;
            $values['exp_reward'] = $this->expReward($level, 5.25);
            $values['gold_reward'] = max(1, (int) round((10 + ($level * 2)) * 45));
            if (Schema::hasColumn('enemies', 'job_exp_reward')) {
                $values['job_exp_reward'] = 7;
            }
            if (Schema::hasColumn('enemies', 'manual_adjustment_note')) {
                $values['manual_adjustment_note'] = 'Ferdia region gate boss. Generated from the standard boss curve.';
            }

            $enemy = Enemy::updateOrCreate(
                ['area_id' => (int) $area->id, 'name' => (string) $boss['name']],
                $values
            );
            $this->seedEnemyActions($enemy, $area);
        }
    }

    private function enemyValues(Area $area, array $definition, int $enemyLevel, int $sortOrder): array
    {
        $generated = app(EnemyStatGenerationService::class)->generate(
            $enemyLevel,
            (string) $definition['family_key'],
            (string) $definition['variant_key'],
            (string) $definition['role_key']
        );
        $stats = $generated['stats'];
        $roleKey = (string) $definition['role_key'];
        $rewardMultiplier = $roleKey === 'strong' ? 1.2 : ($roleKey === 'normal_weak' ? 0.88 : 1.0);

        $values = [
            'area_id' => (int) $area->id,
            'name' => (string) $definition['name'],
            'level' => $enemyLevel,
            'max_hp' => $stats['hp'],
            'str' => $stats['attack'],
            'def' => $stats['defense'],
            'agi' => $stats['speed'],
            'mag' => $stats['magic'],
            'luk' => $stats['luck'],
            'exp_reward' => $this->expReward($enemyLevel, $rewardMultiplier),
            'gold_reward' => max(1, (int) round((10 + ($enemyLevel * 2)) * $rewardMultiplier)),
            'appearance_weight' => (int) $definition['weight'],
            'is_boss' => false,
            'sort_order' => $sortOrder,
        ];

        foreach ([
            'spr' => $stats['spirit'],
            'job_exp_reward' => $roleKey === 'strong' ? 2 : 1,
            'role' => (string) $definition['role'],
            'type_name' => (string) $definition['type_name'],
            'element' => (string) $definition['element'],
            'action_pattern' => 'standard',
            'drop_type' => 'outer_world',
            'enemy_level' => $enemyLevel,
            'family_key' => $generated['family_key'],
            'variant_key' => $generated['variant_key'],
            'role_key' => $generated['role_key'],
            'stat_generation_version' => $generated['stat_generation_version'],
            'is_stat_locked' => true,
            'generated_at' => now(),
            'manual_adjustment_note' => 'Ferdia region enemy. Continues after Valzeria Castle difficulty.',
        ] as $column => $value) {
            if (Schema::hasColumn('enemies', $column)) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    private function seedEnemyActions(Enemy $enemy, Area $area): void
    {
        if ($enemy->is_boss && (int) $area->id === 1029) {
            foreach ($this->abyssVeilGatekeeperActions() as $sortOrder => $action) {
                EnemyAction::updateOrCreate(
                    ['enemy_id' => $enemy->id, 'action_key' => $action['action_key']],
                    array_merge($action, ['enemy_id' => $enemy->id, 'sort_order' => ($sortOrder + 1) * 10])
                );
            }

            return;
        }

        $type = (string) $enemy->type_name;
        $action = match ($type) {
            'スライム' => $this->statusAction(
                in_array((int) $area->id, [1001, 1006, 1011, 1012, 1013], true) ? 'burn' : 'poison'
            ),
            '獣' => $this->action('multi_hit', '連続攻撃', ['power_percent' => 80, 'hit_count' => 2]),
            '人型' => $this->action('def_down', '鎧砕き', ['effect_percent' => 20, 'duration_turns' => 3]),
            '巨人' => $this->action('charge', '大地を砕く突進', [
                'power_percent' => 200,
                'cooldown_turns' => 4,
                'can_use_on_first_turn' => false,
                'is_telegraphed' => true,
                'telegraph_turns' => 1,
                'can_be_guarded' => true,
                'guard_reduction_rate' => 0.50,
            ]),
            '水棲' => $this->action('current_hp_percent', '水圧の奔流', [
                'effect_percent' => 12,
                'max_uses_per_battle' => 1,
                'can_use_on_first_turn' => false,
            ]),
            '飛行' => $this->action('slow', '羽風の足止め', ['effect_percent' => 25, 'duration_turns' => 3]),
            '昆虫' => $this->action('bleed', '裂傷の毒針', ['duration_turns' => 3]),
            '機械' => $this->action('recovery_block', '魔導阻害波', ['effect_percent' => 35, 'duration_turns' => 3]),
            '精霊', '妖精' => $this->action('slow', '精霊の足止め', ['effect_percent' => 25, 'duration_turns' => 3]),
            '不死' => $this->statusAction('burn'),
            '悪魔' => $this->statusAction('burn'),
            default => $this->action('strong_strike', '強撃', ['power_percent' => 150]),
        };

        EnemyAction::updateOrCreate(
            ['enemy_id' => $enemy->id, 'action_key' => $action['action_key']],
            array_merge($action, ['enemy_id' => $enemy->id, 'sort_order' => 10])
        );
    }

    private function abyssVeilGatekeeperActions(): array
    {
        return [
            $this->action('burn', '深淵の灼炎', ['duration_turns' => 3]),
            $this->action('recovery_block', '封魔の鎖', ['effect_percent' => 35, 'duration_turns' => 3]),
            $this->action('charge', '深淵門の崩落', [
                'power_percent' => 200,
                'cooldown_turns' => 4,
                'can_use_on_first_turn' => false,
                'is_telegraphed' => true,
                'telegraph_turns' => 1,
                'can_be_guarded' => true,
                'guard_reduction_rate' => 0.50,
            ]),
        ];
    }

    private function statusAction(string $type): array
    {
        return match ($type) {
            'burn' => $this->action('burn', '灼熱の体当たり', ['duration_turns' => 3]),
            default => $this->action('poison', '毒液', ['duration_turns' => 5, 'cooldown_turns' => 2]),
        };
    }

    private function action(string $type, string $name, array $overrides = []): array
    {
        return array_merge([
            'action_key' => $type,
            'name' => $name,
            'action_type' => $type,
            'selection_weight' => 100,
            'power_percent' => 100,
            'hit_count' => 1,
            'effect_percent' => 0,
            'duration_turns' => 0,
            'cooldown_turns' => 0,
            'max_uses_per_battle' => null,
            'trigger_turn' => null,
            'trigger_key' => null,
            'trigger_value' => null,
            'can_use_on_first_turn' => true,
            'is_telegraphed' => false,
            'telegraph_turns' => 0,
            'can_be_guarded' => false,
            'guard_reduction_rate' => 0,
            'cancel_on_enemy_death' => true,
            'guarantee_first_use' => false,
        ], $overrides);
    }

    private function expReward(int $enemyLevel, float $rewardMultiplier): int
    {
        $base = (0.12 * $enemyLevel * $enemyLevel) + (15 * $enemyLevel) + 500;

        return max(1, (int) round($base * $rewardMultiplier));
    }
}
