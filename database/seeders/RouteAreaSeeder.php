<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Enemy;
use App\Services\Enemy\EnemyStatGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class RouteAreaSeeder extends Seeder
{
    public function run(): void
    {
        $routes = [
            [75, 1, 'アークレア西街道', 'Lv15〜16', '王都の西へ続く街道。草原の先に潮風の気配が漂う。'],
            [76, 2, '海風の森道', 'Lv29〜30', '浮上した石橋から内陸の森へ続く道。'],
            [77, 3, '木漏れ日の山麓路', 'Lv43〜44', '月光庭園の外れから鍛冶街方面へ下る山麓路。'],
            [78, 4, '炉煙の北峠', 'Lv57〜58', '兵器庫の北門から雪国へ続く峠道。'],
            [79, 5, '雪解けの交易路', 'Lv71〜72', '極寒山脈の南斜面に埋もれていた交易路。'],
            [80, 6, '星砂の学術街道', 'Lv85〜86', '太陽神殿の星図が示す学術街道。'],
            [81, 7, '禁呪の境界路', 'Lv99〜100', '次元回廊の外れに封じられた境界路。'],
            [82, 8, '白き巡礼階段', 'Lv113〜114', '奈落の階段のさらに上へ続く白い巡礼路。'],
            [83, 9, '黒雲の征路', 'Lv127〜128', '神々の祭壇の空に現れた魔王城への征路。'],
        ];

        foreach ($routes as [$id, $cityId, $name, $levelText, $description]) {
            [$levelMin, $levelMax] = $this->levels($levelText);
            $area = Area::updateOrCreate(
                ['id' => $id],
                [
                    'city_id' => $cityId,
                    'name' => $name,
                    'slug' => 'route_' . $id,
                    'description' => '【街道】 ' . $description,
                    'recommended_level_min' => $levelMin,
                    'recommended_level_max' => $levelMax,
                    'unlock_order' => 10,
                    'unlock_required_area_id' => null,
                    'area_kind' => 'route',
                    'clear_condition_type' => 'development_point',
                    'development_required_point' => 100,
                    'is_route_area' => true,
                    'sort_order' => ($cityId * 100) + 85,
                ]
            );

            $this->seedRouteEnemies($area);
        }

        $this->command?->info('街道ダンジョンを登録・更新しました。');
    }

    private function levels(string $levelText): array
    {
        if (preg_match('/Lv(\d+)〜(\d+)/u', $levelText, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [1, 1];
    }

    private function seedRouteEnemies(Area $area): void
    {
        $baseLevel = max(1, (int) $area->recommended_level_min);
        $routeEnemies = $this->routeEnemyDefinitions($area);

        foreach ($routeEnemies as $index => $enemyDefinition) {
            $enemyLevel = max(1, $baseLevel + (int) ($enemyDefinition['level_offset'] ?? 0));
            $values = $this->routeEnemyValues($area, $enemyLevel, $enemyDefinition, $index + 1);

            Enemy::updateOrCreate(
                ['id' => (9000 + (int) $area->id) + ($index * 100)],
                $values
            );
        }
    }

    private function routeEnemyDefinitions(Area $area): array
    {
        $sets = [
            75 => [
                ['name' => '西街道スライム', 'family_key' => 'slime', 'variant_key' => 'none', 'role_key' => 'normal_weak', 'role' => '街道の小魔物', 'type_name' => 'スライム', 'element' => '無', 'weight' => 44, 'level_offset' => 0],
                ['name' => '草原の追いはぎ', 'family_key' => 'soldier', 'variant_key' => 'none', 'role_key' => 'normal', 'role' => '街道の賊', 'type_name' => '人型', 'element' => '無', 'weight' => 30, 'level_offset' => 0],
                ['name' => '街道ウルフ', 'family_key' => 'beast', 'variant_key' => 'none', 'role_key' => 'normal', 'role' => '街道の獣', 'type_name' => '獣', 'element' => '無', 'weight' => 20, 'level_offset' => 1],
                ['name' => '荷馬車荒らしの頭', 'family_key' => 'soldier', 'variant_key' => 'none', 'role_key' => 'strong', 'role' => '街道の強敵', 'type_name' => '人型', 'element' => '無', 'weight' => 6, 'level_offset' => 1],
            ],
            76 => [
                ['name' => '潮風ラット', 'family_key' => 'beast', 'variant_key' => 'water', 'role_key' => 'normal_weak', 'role' => '森道の小魔物', 'type_name' => '獣', 'element' => '水', 'weight' => 42, 'level_offset' => 0],
                ['name' => '森道の絡み蔦', 'family_key' => 'standard', 'variant_key' => 'forest', 'role_key' => 'normal', 'role' => '森道の魔物', 'type_name' => '植物', 'element' => '森', 'weight' => 30, 'level_offset' => 0],
                ['name' => '海風の斥候', 'family_key' => 'soldier', 'variant_key' => 'water', 'role_key' => 'normal', 'role' => '街道の賊', 'type_name' => '人型', 'element' => '水', 'weight' => 22, 'level_offset' => 1],
                ['name' => '森道の大牙獣', 'family_key' => 'beast', 'variant_key' => 'forest', 'role_key' => 'strong', 'role' => '森道の強敵', 'type_name' => '獣', 'element' => '森', 'weight' => 6, 'level_offset' => 1],
            ],
            77 => [
                ['name' => '山麓コボルト', 'family_key' => 'goblin', 'variant_key' => 'earth', 'role_key' => 'normal_weak', 'role' => '山道の小魔物', 'type_name' => '小鬼', 'element' => '土', 'weight' => 40, 'level_offset' => 0],
                ['name' => '木漏れ日の山賊', 'family_key' => 'soldier', 'variant_key' => 'forest', 'role_key' => 'normal', 'role' => '山道の賊', 'type_name' => '人型', 'element' => '森', 'weight' => 31, 'level_offset' => 0],
                ['name' => '山麓ハーピー', 'family_key' => 'flying', 'variant_key' => 'forest', 'role_key' => 'normal', 'role' => '山道の魔物', 'type_name' => '飛行', 'element' => '風', 'weight' => 23, 'level_offset' => 1],
                ['name' => '峠見張りの重戦士', 'family_key' => 'soldier', 'variant_key' => 'earth', 'role_key' => 'strong', 'role' => '山道の強敵', 'type_name' => '人型', 'element' => '土', 'weight' => 6, 'level_offset' => 1],
            ],
            78 => [
                ['name' => '煤けた火の粉', 'family_key' => 'spirit', 'variant_key' => 'fire', 'role_key' => 'normal_weak', 'role' => '峠の小魔物', 'type_name' => '精霊', 'element' => '火', 'weight' => 42, 'level_offset' => 0],
                ['name' => '炉煙の山賊', 'family_key' => 'soldier', 'variant_key' => 'fire', 'role_key' => 'normal', 'role' => '峠の賊', 'type_name' => '人型', 'element' => '火', 'weight' => 30, 'level_offset' => 0],
                ['name' => '峠の鉄殻虫', 'family_key' => 'insect', 'variant_key' => 'metal', 'role_key' => 'normal', 'role' => '峠の魔物', 'type_name' => '虫', 'element' => '金属', 'weight' => 22, 'level_offset' => 1],
                ['name' => '黒煙の番人', 'family_key' => 'giant', 'variant_key' => 'fire', 'role_key' => 'strong', 'role' => '峠の強敵', 'type_name' => '巨人', 'element' => '火', 'weight' => 6, 'level_offset' => 1],
            ],
            79 => [
                ['name' => '雪解けスライム', 'family_key' => 'slime', 'variant_key' => 'water', 'role_key' => 'normal_weak', 'role' => '交易路の小魔物', 'type_name' => 'スライム', 'element' => '水', 'weight' => 42, 'level_offset' => 0],
                ['name' => '交易路の白狼', 'family_key' => 'beast', 'variant_key' => 'ice', 'role_key' => 'normal', 'role' => '交易路の獣', 'type_name' => '獣', 'element' => '氷', 'weight' => 30, 'level_offset' => 0],
                ['name' => '雪道の盗掘者', 'family_key' => 'soldier', 'variant_key' => 'ice', 'role_key' => 'normal', 'role' => '交易路の賊', 'type_name' => '人型', 'element' => '氷', 'weight' => 22, 'level_offset' => 1],
                ['name' => '氷荷を背負う大熊', 'family_key' => 'giant', 'variant_key' => 'ice', 'role_key' => 'strong', 'role' => '交易路の強敵', 'type_name' => '獣', 'element' => '氷', 'weight' => 6, 'level_offset' => 1],
            ],
            80 => [
                ['name' => '星砂スライム', 'family_key' => 'slime', 'variant_key' => 'earth', 'role_key' => 'normal_weak', 'role' => '学術街道の小魔物', 'type_name' => 'スライム', 'element' => '土', 'weight' => 40, 'level_offset' => 0],
                ['name' => '古地図を狙う盗賊', 'family_key' => 'soldier', 'variant_key' => 'earth', 'role_key' => 'normal', 'role' => '学術街道の賊', 'type_name' => '人型', 'element' => '土', 'weight' => 31, 'level_offset' => 0],
                ['name' => '星読みの幻影', 'family_key' => 'mage', 'variant_key' => 'arcane', 'role_key' => 'normal', 'role' => '学術街道の魔物', 'type_name' => '魔法型', 'element' => '魔', 'weight' => 23, 'level_offset' => 1],
                ['name' => '砂時計の守護像', 'family_key' => 'giant', 'variant_key' => 'ancient', 'role_key' => 'strong', 'role' => '学術街道の強敵', 'type_name' => 'ゴーレム', 'element' => '土', 'weight' => 6, 'level_offset' => 1],
            ],
            81 => [
                ['name' => '境界路の影', 'family_key' => 'undead', 'variant_key' => 'dark', 'role_key' => 'normal_weak', 'role' => '境界路の小魔物', 'type_name' => '霊体', 'element' => '闇', 'weight' => 40, 'level_offset' => 0],
                ['name' => '禁呪の番書', 'family_key' => 'mage', 'variant_key' => 'arcane', 'role_key' => 'normal', 'role' => '境界路の魔物', 'type_name' => '魔法型', 'element' => '魔', 'weight' => 31, 'level_offset' => 0],
                ['name' => '境界を渡る刺客', 'family_key' => 'soldier', 'variant_key' => 'dark', 'role_key' => 'normal', 'role' => '境界路の賊', 'type_name' => '人型', 'element' => '闇', 'weight' => 23, 'level_offset' => 1],
                ['name' => '封印破りの異形', 'family_key' => 'demon', 'variant_key' => 'abyss', 'role_key' => 'strong', 'role' => '境界路の強敵', 'type_name' => '悪魔', 'element' => '闇', 'weight' => 6, 'level_offset' => 1],
            ],
            82 => [
                ['name' => '巡礼階段の光蝶', 'family_key' => 'flying', 'variant_key' => 'holy', 'role_key' => 'normal_weak', 'role' => '巡礼路の小魔物', 'type_name' => '飛行', 'element' => '光', 'weight' => 40, 'level_offset' => 0],
                ['name' => '白階の巡礼兵', 'family_key' => 'soldier', 'variant_key' => 'holy', 'role_key' => 'normal', 'role' => '巡礼路の兵', 'type_name' => '人型', 'element' => '光', 'weight' => 31, 'level_offset' => 0],
                ['name' => '祈りを失った神官', 'family_key' => 'mage', 'variant_key' => 'holy', 'role_key' => 'normal', 'role' => '巡礼路の魔物', 'type_name' => '魔法型', 'element' => '光', 'weight' => 23, 'level_offset' => 1],
                ['name' => '白き階段の守護者', 'family_key' => 'giant', 'variant_key' => 'holy', 'role_key' => 'strong', 'role' => '巡礼路の強敵', 'type_name' => '巨人', 'element' => '光', 'weight' => 6, 'level_offset' => 1],
            ],
            83 => [
                ['name' => '黒雲の小悪魔', 'family_key' => 'demon', 'variant_key' => 'dark', 'role_key' => 'normal_weak', 'role' => '征路の小魔物', 'type_name' => '悪魔', 'element' => '闇', 'weight' => 40, 'level_offset' => 0],
                ['name' => '征路の黒鎧兵', 'family_key' => 'soldier', 'variant_key' => 'dark', 'role_key' => 'normal', 'role' => '征路の兵', 'type_name' => '人型', 'element' => '闇', 'weight' => 31, 'level_offset' => 0],
                ['name' => '黒雲を裂く飛竜', 'family_key' => 'dragon', 'variant_key' => 'dark', 'role_key' => 'normal', 'role' => '征路の魔物', 'type_name' => '竜型', 'element' => '闇', 'weight' => 23, 'level_offset' => 1],
                ['name' => '魔城門の先遣隊長', 'family_key' => 'demon', 'variant_key' => 'abyss', 'role_key' => 'strong', 'role' => '征路の強敵', 'type_name' => '悪魔', 'element' => '闇', 'weight' => 6, 'level_offset' => 1],
            ],
        ];

        return $sets[(int) $area->id] ?? [
            ['name' => $area->name . 'のならず者', 'family_key' => 'soldier', 'variant_key' => 'none', 'role_key' => 'normal', 'role' => '街道の魔物', 'type_name' => '人型', 'element' => '無', 'weight' => 100, 'level_offset' => 0],
        ];
    }

    private function routeEnemyValues(Area $area, int $enemyLevel, array $definition, int $sortOrder): array
    {
        $generated = app(EnemyStatGenerationService::class)->generate(
            $enemyLevel,
            $definition['family_key'] ?? 'standard',
            $definition['variant_key'] ?? 'none',
            $definition['role_key'] ?? 'normal'
        );
        $stats = $generated['stats'];
        $roleKey = (string) ($definition['role_key'] ?? 'normal');
        $rewardMultiplier = $roleKey === 'strong' ? 1.2 : ($roleKey === 'normal_weak' ? 0.88 : 1.0);

        $values = [
            'area_id' => $area->id,
            'name' => $definition['name'],
            'level' => $enemyLevel,
            'max_hp' => $stats['hp'],
            'str' => $stats['attack'],
            'def' => $stats['defense'],
            'agi' => $stats['speed'],
            'mag' => $stats['magic'],
            'luk' => $stats['luck'],
            'exp_reward' => max(1, (int) round((8 + ($enemyLevel * 3)) * $rewardMultiplier)),
            'gold_reward' => max(1, (int) round((10 + ($enemyLevel * 2)) * $rewardMultiplier)),
            'appearance_weight' => (int) ($definition['weight'] ?? 10),
            'is_boss' => false,
            'sort_order' => $sortOrder,
        ];

        if (Schema::hasColumn('enemies', 'spr')) {
            $values['spr'] = $stats['spirit'];
        }
        if (Schema::hasColumn('enemies', 'job_exp_reward')) {
            $values['job_exp_reward'] = $roleKey === 'strong' ? 2 : 1;
        }
        if (Schema::hasColumn('enemies', 'role')) {
            $values['role'] = (string) ($definition['role'] ?? '街道の魔物');
        }
        if (Schema::hasColumn('enemies', 'type_name')) {
            $values['type_name'] = (string) ($definition['type_name'] ?? '標準');
        }
        if (Schema::hasColumn('enemies', 'element')) {
            $values['element'] = (string) ($definition['element'] ?? '無');
        }
        if (Schema::hasColumn('enemies', 'action_pattern')) {
            $values['action_pattern'] = 'standard';
        }
        if (Schema::hasColumn('enemies', 'enemy_level')) {
            $values['enemy_level'] = $enemyLevel;
        }
        if (Schema::hasColumn('enemies', 'family_key')) {
            $values += [
                'family_key' => $generated['family_key'],
                'variant_key' => $generated['variant_key'],
                'role_key' => $generated['role_key'],
                'stat_generation_version' => $generated['stat_generation_version'],
                'is_stat_locked' => true,
                'manual_adjustment_note' => 'Route area transition enemy. Tuned between previous city final area and next city first area.',
            ];
        }

        return $values;
    }
}
