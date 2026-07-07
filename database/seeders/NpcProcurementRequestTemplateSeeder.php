<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\NpcProcurementRequestTemplate;
use Illuminate\Database\Seeder;

class NpcProcurementRequestTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'title' => '炉の補修素材募集',
                'requester_name' => '鉄鋼都市の鍛冶組合',
                'requester_type' => 'blacksmith_guild',
                'description' => '大型炉の補修に使う鉱石素材を集めています。',
                'purpose_label' => '炉の補修',
                'frequency_weight' => 120,
                'display_order' => 10,
                'materials' => [
                    ['MAT_COMMON_MAGIC_ORE', 30, 55],
                ],
            ],
            [
                'title' => '回復薬の仕込み素材募集',
                'requester_name' => '薬師見習いミリカ',
                'requester_type' => 'apothecary',
                'description' => '回復薬の仕込みに使う素材を集めています。',
                'purpose_label' => '回復薬の仕込み',
                'frequency_weight' => 110,
                'display_order' => 20,
                'materials' => [
                    ['MAT_REGION_WORLD_TREE_LEAF', 12, 75],
                ],
            ],
            [
                'title' => '防具補修素材募集',
                'requester_name' => '防具職人ギルド',
                'requester_type' => 'blacksmith_guild',
                'description' => '旅人の防具を直すため、毛皮素材を募集しています。',
                'purpose_label' => '防具補修',
                'frequency_weight' => 95,
                'display_order' => 30,
                'materials' => [
                    ['MAT_COMMON_BEAST_FUR', 25, 45],
                ],
            ],
            [
                'title' => '小鬼討伐の証明品募集',
                'requester_name' => '街道警備隊',
                'requester_type' => 'city_guard',
                'description' => '街道の安全確認のため、小鬼の素材を買い取っています。',
                'purpose_label' => '街道警備',
                'frequency_weight' => 85,
                'display_order' => 40,
                'materials' => [
                    ['MAT_COMMON_GOBLIN_FANG', 15, 120],
                ],
            ],
            [
                'title' => '討伐記録用の古びた徽章募集',
                'requester_name' => '冒険者協会の記録係',
                'requester_type' => 'association',
                'description' => '各地の討伐記録と照合するため、古びた徽章を集めています。',
                'purpose_label' => '討伐記録',
                'frequency_weight' => 80,
                'display_order' => 50,
                'materials' => [
                    ['MAT_COMMON_OLD_BADGE', 18, 70],
                ],
            ],
            [
                'title' => '獣牙の加工素材募集',
                'requester_name' => '細工師ロロ',
                'requester_type' => 'guild',
                'description' => '武具の留め具に使う獣牙を探しています。',
                'purpose_label' => '武具加工',
                'frequency_weight' => 80,
                'display_order' => 60,
                'materials' => [
                    ['MAT_COMMON_BEAST_FANG', 18, 70],
                ],
            ],
            [
                'title' => '魔物の欠片の基礎研究',
                'requester_name' => '王都研究室',
                'requester_type' => 'guild',
                'description' => '魔物の性質を調べるため、魔物の欠片を集めています。',
                'purpose_label' => '基礎研究',
                'frequency_weight' => 75,
                'display_order' => 70,
                'materials' => [
                    ['MAT_COMMON_MONSTER_FRAGMENT', 30, 25],
                ],
            ],
            [
                'title' => '妖精粉の調合素材募集',
                'requester_name' => '調香師エルナ',
                'requester_type' => 'apothecary',
                'description' => '香薬の調合に使う妖精粉を少量募集しています。',
                'purpose_label' => '香薬調合',
                'frequency_weight' => 70,
                'display_order' => 80,
                'materials' => [
                    ['MAT_COMMON_FAIRY_DUST', 10, 120],
                ],
            ],
            [
                'title' => '氷晶片の研究素材募集',
                'requester_name' => '雪都の魔導研究員',
                'requester_type' => 'apothecary',
                'description' => '氷属性研究のため、安定した氷晶片を必要としています。',
                'purpose_label' => '氷属性研究',
                'frequency_weight' => 45,
                'display_order' => 90,
                'materials' => [
                    ['MAT_REGION_ICE_CRYSTAL', 10, 300],
                ],
            ],
            [
                'title' => '鍛冶場の資材補充',
                'requester_name' => '鍛冶場の資材係',
                'requester_type' => 'blacksmith_guild',
                'description' => '鍛冶場で使う基礎素材をまとめて補充しています。',
                'purpose_label' => '資材補充',
                'frequency_weight' => 55,
                'display_order' => 100,
                'materials' => [
                    ['MAT_COMMON_MAGIC_ORE', 20, 50],
                    ['MAT_COMMON_BEAST_FUR', 10, 40],
                    ['MAT_COMMON_MONSTER_FRAGMENT', 8, 20],
                ],
            ],
            [
                'title' => '旅装ギルド設立準備',
                'requester_name' => '旅装ギルド準備係',
                'requester_type' => 'guild',
                'description' => '新しい遠征路に備え、軽くて丈夫な旅装を仕立てるための布材を集めています。',
                'purpose_label' => '新施設準備',
                'frequency_weight' => 65,
                'display_order' => 110,
                'duration_hours' => 87600,
                'materials' => [
                    ['5025', 1000, 25],
                    ['5027', 1000, 25],
                    ['5029', 1000, 30],
                    ['5031', 1000, 30],
                ],
            ],
            [
                'title' => '遠征外套の試作',
                'requester_name' => '裁縫職人ミラ',
                'requester_type' => 'artisan',
                'description' => '長い探索に耐える外套を試作しています。各地の繊維素材を譲ってください。',
                'purpose_label' => '外套試作',
                'frequency_weight' => 55,
                'display_order' => 120,
                'duration_hours' => 87600,
                'materials' => [
                    ['5033', 1200, 35],
                    ['5035', 1200, 35],
                    ['5037', 1200, 40],
                ],
            ],
            [
                'title' => '祭礼布と護符の修繕',
                'requester_name' => '神殿の司祭',
                'requester_type' => 'temple',
                'description' => '古い祭礼布と護符を修繕するため、守護や精霊にまつわる布材を探しています。',
                'purpose_label' => '儀式準備',
                'frequency_weight' => 45,
                'display_order' => 130,
                'duration_hours' => 87600,
                'materials' => [
                    ['5026', 900, 35],
                    ['5030', 900, 40],
                    ['5034', 900, 45],
                ],
            ],
            [
                'title' => '深層調査隊の防護布',
                'requester_name' => '深層調査隊',
                'requester_type' => 'expedition',
                'description' => '熱、瘴気、闇に耐える防護布の準備を進めています。まだ行き先は伏せられています。',
                'purpose_label' => '調査準備',
                'frequency_weight' => 40,
                'display_order' => 140,
                'duration_hours' => 87600,
                'materials' => [
                    ['5032', 1000, 40],
                    ['5039', 1000, 40],
                    ['5040', 1000, 45],
                ],
            ],
            [
                'title' => '空織り調査隊の装備準備',
                'requester_name' => '空織り調査隊',
                'requester_type' => 'expedition',
                'description' => '高空域の調査に向け、強い魔力や竜の気配に耐える布材を集めています。',
                'purpose_label' => '新ダンジョン準備',
                'frequency_weight' => 35,
                'display_order' => 150,
                'duration_hours' => 87600,
                'materials' => [
                    ['5041', 1500, 45],
                    ['5042', 1500, 50],
                    ['5043', 1500, 50],
                    ['5044', 1500, 50],
                ],
            ],
        ];

        foreach ($templates as $templateData) {
            $materials = $templateData['materials'];
            unset($templateData['materials']);

            $template = NpcProcurementRequestTemplate::updateOrCreate(
                [
                    'title' => $templateData['title'],
                    'requester_name' => $templateData['requester_name'],
                ],
                array_merge($templateData, [
                    'city_id' => null,
                    'min_character_level' => 1,
                    'max_character_level' => null,
                    'duration_hours' => (int) ($templateData['duration_hours'] ?? 24),
                    'reward_gold_on_complete' => 0,
                    'reward_association_point_on_complete' => 0,
                    'reward_items_json' => null,
                    'is_active' => true,
                ])
            );

            $keptMaterialIds = [];
            foreach ($materials as [$materialCode, $requiredQuantity, $rewardGoldPerUnit]) {
                $material = Material::where('material_code', $materialCode)->first();
                if (! $material) {
                    continue;
                }

                $template->materials()->updateOrCreate(
                    ['material_id' => $material->id],
                    [
                        'required_quantity' => $requiredQuantity,
                        'reward_gold_per_unit' => $rewardGoldPerUnit,
                    ]
                );
                $keptMaterialIds[] = $material->id;
            }

            if ($keptMaterialIds !== []) {
                $template->materials()
                    ->whereNotIn('material_id', $keptMaterialIds)
                    ->delete();
            }
        }
    }
}
