<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLOTH_MATERIALS = [
        '5025' => ['name' => '王都の織布', 'price' => 25],
        '5026' => ['name' => '王都の守護布', 'price' => 35],
        '5027' => ['name' => '潮風の布片', 'price' => 25],
        '5028' => ['name' => '海守りの織布', 'price' => 35],
        '5029' => ['name' => '精霊樹の繊維', 'price' => 30],
        '5030' => ['name' => '精霊王の絹糸', 'price' => 40],
        '5031' => ['name' => '黒鉄の装甲片', 'price' => 30],
        '5032' => ['name' => '炉心の耐熱布', 'price' => 40],
        '5033' => ['name' => '氷晶の織糸', 'price' => 35],
        '5034' => ['name' => '氷帝の守護布', 'price' => 45],
        '5035' => ['name' => '砂金繊維', 'price' => 35],
        '5036' => ['name' => '砂王の宝布', 'price' => 45],
        '5037' => ['name' => '魔導繊維', 'price' => 40],
        '5038' => ['name' => '大魔導の星布', 'price' => 50],
        '5039' => ['name' => '瘴気の革片', 'price' => 40],
        '5040' => ['name' => '深魔の黒布', 'price' => 45],
        '5041' => ['name' => '天空の羽布', 'price' => 45],
        '5042' => ['name' => '天空竜の織布', 'price' => 50],
        '5043' => ['name' => '魔王城の黒布', 'price' => 50],
        '5044' => ['name' => '魔王の黒装片', 'price' => 50],
    ];

    private const PROCUREMENT_TEMPLATES = [
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

    public function up(): void
    {
        $this->makeClothMaterialsMarketable();
        $this->upsertProcurementTemplates();
    }

    public function down(): void
    {
        if (Schema::hasTable('npc_procurement_request_templates')) {
            foreach (self::PROCUREMENT_TEMPLATES as $template) {
                $row = DB::table('npc_procurement_request_templates')
                    ->where('title', $template['title'])
                    ->where('requester_name', $template['requester_name'])
                    ->first();

                if (! $row) {
                    continue;
                }

                if (Schema::hasTable('npc_procurement_request_template_materials')) {
                    DB::table('npc_procurement_request_template_materials')
                        ->where('npc_procurement_request_template_id', $row->id)
                        ->delete();
                }

                DB::table('npc_procurement_request_templates')
                    ->where('id', $row->id)
                    ->delete();
            }
        }

        if (Schema::hasTable('materials')) {
            DB::table('materials')
                ->whereIn('material_code', array_keys(self::CLOTH_MATERIALS))
                ->update([
                    'is_tradable' => false,
                    'trade_policy' => 'unmarketable',
                    'npc_sell_price' => 0,
                    'market_min_price' => null,
                    'market_max_price' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function makeClothMaterialsMarketable(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        foreach (self::CLOTH_MATERIALS as $materialCode => $data) {
            $price = (int) $data['price'];

            DB::table('materials')
                ->where('material_code', $materialCode)
                ->update([
                    'is_tradable' => true,
                    'trade_policy' => 'marketable',
                    'market_category' => 'normal',
                    'npc_sell_price' => $price,
                    'market_min_price' => $price,
                    'market_max_price' => $price * 5,
                    'market_hint' => '旅装ギルドや調査隊の準備で需要が高まっています。NPC調達に納品でき、NPC市場にも流通します。',
                    'updated_at' => now(),
                ]);
        }
    }

    private function upsertProcurementTemplates(): void
    {
        if (
            ! Schema::hasTable('npc_procurement_request_templates')
            || ! Schema::hasTable('npc_procurement_request_template_materials')
            || ! Schema::hasTable('materials')
        ) {
            return;
        }

        foreach (self::PROCUREMENT_TEMPLATES as $template) {
            $materials = $template['materials'];
            unset($template['materials']);

            DB::table('npc_procurement_request_templates')->updateOrInsert(
                [
                    'title' => $template['title'],
                    'requester_name' => $template['requester_name'],
                ],
                array_merge($template, [
                    'city_id' => null,
                    'npc_id' => null,
                    'min_character_level' => 1,
                    'max_character_level' => null,
                    'reward_gold_on_complete' => 0,
                    'reward_association_point_on_complete' => 0,
                    'reward_items_json' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );

            $templateId = (int) DB::table('npc_procurement_request_templates')
                ->where('title', $template['title'])
                ->where('requester_name', $template['requester_name'])
                ->value('id');

            if ($templateId <= 0) {
                continue;
            }

            $keptMaterialIds = [];
            foreach ($materials as [$materialCode, $requiredQuantity, $rewardGoldPerUnit]) {
                $materialId = (int) DB::table('materials')
                    ->where('material_code', $materialCode)
                    ->value('id');

                if ($materialId <= 0) {
                    continue;
                }

                DB::table('npc_procurement_request_template_materials')->updateOrInsert(
                    [
                        'npc_procurement_request_template_id' => $templateId,
                        'material_id' => $materialId,
                    ],
                    [
                        'required_quantity' => $requiredQuantity,
                        'reward_gold_per_unit' => $rewardGoldPerUnit,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $keptMaterialIds[] = $materialId;
            }

            if ($keptMaterialIds !== []) {
                DB::table('npc_procurement_request_template_materials')
                    ->where('npc_procurement_request_template_id', $templateId)
                    ->whereNotIn('material_id', $keptMaterialIds)
                    ->delete();
            }
        }
    }
};
