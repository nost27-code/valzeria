<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BOSS_KEYS = [
        ['001', 'はじまりの草原', '草原の大スライム', '大粘核'],
        ['002', '小鬼の森', '小鬼の森の親分', '親分の牙飾り'],
        ['003', '古びた洞窟', '洞窟の番人スカルナイト', '番人の黒骨片'],
        ['004', '狼の丘', '銀狼ガルム', '銀狼の月牙'],
        ['005', '忘れられた墓地', '墓守リッチ', '墓守の冥布'],
        ['006', '妖精の泉', '泉の妖精王ニンフィア', '妖精王の泉晶'],
        ['007', '見習い訓練場', '訓練教官バルド', '教官の武勲章'],
        ['008', '潮風の海岸', '潮騒の大ガニ', '潮騒の大鋏'],
        ['009', '海蝕洞窟', '海蝕甲殻獣シェルバイト', '海蝕の甲殻片'],
        ['010', '難破船の残骸', '幽霊船長バルボス', '幽船長の錆羅針'],
        ['011', '人魚の入り江', '人魚姫セイレーン', '人魚姫の涙珠'],
        ['012', '海賊の隠れ家', '海賊頭ドレイク', '海賊頭の古地図片'],
        ['013', '珊瑚迷宮', '珊瑚の迷宮主コーラルゴーレム', '珊瑚核'],
        ['014', '深海神殿', '深海神官ネレウス', '深海神官の祈珠'],
        ['015', '若葉の森', '若葉の守護獣フォレストボア', '若葉守りの翠牙'],
        ['016', '妖精の森', '妖精女王リリア', '妖精女王の花冠片'],
        ['017', '世界樹の根', '世界樹の根喰いワーム', '根喰いの樹殻'],
        ['018', '世界樹中層', '樹霊エルドラン', '樹霊の古枝'],
        ['019', '世界樹上層', '天空鳥シルフィード', '天空鳥の風羽'],
        ['020', '精霊神殿', '精霊神殿の番人アルヴァン', '精霊番人の聖環'],
        ['021', '月光庭園', '月光鹿アルテミス', '月鹿の銀角'],
        ['022', '鉄鉱山', '鉄鉱巨人アイアンゴーレム', '鉄巨人の心鉱'],
        ['023', '廃坑', '廃坑の黒ドワーフ', '黒ドワーフの煤槌片'],
        ['024', '溶鉱炉跡', '溶鉱炉の炎獣ブレイズマンティス', '炎獣の灼刃殻'],
        ['025', '蒸気工場', '蒸気機関兵スチームナイト', '蒸気騎士の圧力弁'],
        ['026', '機械兵工場', '機械兵隊長ギアコマンダー', '隊長機の命令歯車'],
        ['027', '地底発電所', '雷動コア・ヴォルト', '雷動コア片'],
        ['028', '古代兵器庫', '古代兵器オメガ', 'オメガ機構核'],
        ['029', '氷雪平原', '雪原の白熊王', '白熊王の氷爪'],
        ['030', '吹雪の峡谷', '吹雪の魔狼ブリザードガルム', '吹雪狼の凍牙'],
        ['031', '氷結洞窟', '氷結甲竜グレイシャルドン', '氷甲竜の凍鱗'],
        ['032', '白銀の森', '白銀の魔女スノウベル', '白銀魔女の雪晶'],
        ['033', '凍てつく神殿', '凍神官グレイシア', '凍神官の氷祈珠'],
        ['034', '氷竜の巣', '氷竜フロストヴルム', '氷竜の霜心鱗'],
        ['035', '極寒山脈', '極寒巨人ヨトゥン', '極寒巨人の霜髄'],
        ['036', '砂海', '砂海の大サソリ', '砂海蠍の毒針'],
        ['037', '流砂地帯', '流砂の暗殺者サンドシェイド', '流砂影の黒刃片'],
        ['038', '古代遺跡', '古代守護兵アヌビス', '守護兵の黄金仮面片'],
        ['039', '王家の墓', '王墓のファラオ', '王墓の封印布'],
        ['040', '砂嵐の神殿', '砂嵐の魔人ザハーク', '砂嵐魔人の宝珠'],
        ['041', '地下水路', '地下水路の水蛇ナーガ', '水蛇の碧鱗'],
        ['042', '太陽神殿', '太陽神獣ラーガ', '太陽神獣の光鬣'],
        ['043', '魔導図書館', '禁書の魔導書グリモア', '禁書の魔導革'],
        ['044', '禁書庫', '封印司書モルガン', '封印司書の銀鍵'],
        ['045', '魔法研究所', '人造魔獣ホムンクルス', '人造魔獣の培養核'],
        ['046', '浮遊庭園', '風庭のグリフォン', '風庭獣の翼爪'],
        ['047', '星見の塔', '星見の占星術師ステラ', '星見の星盤片'],
        ['048', '異界観測所', '異界の観測者オブザーバー', '異界観測眼'],
        ['049', '次元回廊', '次元竜クロノス', '次元竜の時鱗'],
        ['050', '死者の荒野', '死者の王ネクロロード', '死王の冥冠片'],
        ['051', '呪われた城', '呪城伯爵ヴラド', '呪城伯爵の血晶'],
        ['052', '冥界門', '冥界門番ケルベロス', '冥界門番の三牙'],
        ['053', '悪魔神殿', '悪魔司祭バフォメット', '悪魔司祭の黒角'],
        ['054', '魔王軍要塞', '魔王軍将軍ガイゼル', '魔将軍の軍旗片'],
        ['055', '瘴気の谷', '瘴気竜ナハシュ', '瘴気竜の毒鱗'],
        ['056', '奈落への階段', '奈落の黒騎士アビス', '奈落騎士の黒鎧片'],
        ['057', '雲海平原', '雲海の天馬王ペガサス', '天馬王の雲鬣'],
        ['058', '天空回廊', '天空剣士セラフ', '天空剣士の羽剣片'],
        ['059', '雷鳴神殿', '雷鳴獣トールガル', '雷鳴獣の轟角'],
        ['060', '浮遊遺跡', '古代天使兵長メタトロン', '天使兵長の光翼片'],
        ['061', '天使の庭園', '庭園の癒天使ラファエラ', '癒天使の聖涙'],
        ['062', '星辰の塔', '星辰竜アステリオス', '星辰竜の星鱗'],
        ['063', '神々の祭壇', '神々の代行者プロヴィデンス', '代行者の神印片'],
        ['064', '魔王領外郭', '魔王領門番グラオザム', '魔王門番の黒鍵'],
        ['065', '絶望の回廊', '絶望の幻影ディスペア', '絶望幻影の残滓'],
        ['066', '魔神の間', '魔神バルゼア', '魔神の紅核'],
        ['067', '深淵の牢獄', '深淵の獄卒タルタロス', '深淵獄卒の鎖片'],
        ['068', '黒き玉座', '黒玉座の親衛隊長レオンハルト', '親衛隊長の黒紋章'],
        ['069', '魔王城中枢', '魔王城の心臓コア・ヴァルゼリア', '魔王城の心臓片'],
        ['070', '終焉の祭壇', '魔王ヴァルゼリア', '終焉王の黒冠片'],
        ['071', '深淵の裂け目', '深淵王アビスロード', '深淵王の裂核'],
        ['072', '古代錬成炉', '錬成炉守護者オルディオン', '錬成守護者の炉心'],
        ['073', '竜王の聖域', '竜王バハムート', '竜王の覇鱗'],
        ['074', '次元回廊中枢', '次元回廊の支配者クロノアーク', 'クロノアークの時核'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies') || !Schema::hasTable('areas')) {
            return;
        }

        $now = now();

        foreach (self::BOSS_KEYS as [$number, $areaName, $bossName, $materialName]) {
            $area = DB::table('areas')->where('name', $areaName)->first();
            $enemy = DB::table('enemies')
                ->where('name', $bossName)
                ->where('is_boss', true)
                ->when($area, fn ($query) => $query->where('area_id', $area->id))
                ->first();

            if (!$area || !$enemy) {
                continue;
            }

            $code = 'BOSS_KEY_' . $number;
            $payload = [
                'name' => $materialName,
                'category' => 'ボス特異素材',
                'rarity' => 'KEY',
                'element' => null,
                'main_use' => '消費しない解放キー',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => $area->city_id ?? null,
                'dungeon_id' => $area->id,
                'source_enemy_id' => $enemy->id,
                'updated_at' => $now,
            ];

            foreach ([
                'drop_rate' => 100,
                'drop_first_clear_only' => true,
                'drop_timing' => 'boss_clear',
                'material_type' => 'boss_unique',
                'category_id' => 'boss_unique',
                'rank_tier' => 1,
                'is_consumable' => false,
                'obtain_method' => "{$bossName}の撃破で入手。消費・売却・取引はできません。",
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                array_merge($payload, ['created_at' => $now])
            );

            $materialId = DB::table('materials')->where('material_code', $code)->value('id');
            if (!$materialId) {
                continue;
            }

            DB::table('material_drops')->updateOrInsert(
                ['enemy_id' => $enemy->id, 'material_id' => $materialId],
                [
                    'drop_rate' => 100,
                    'drop_first_clear_only' => true,
                    'drop_timing' => 'boss_clear',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->backfillClearedBossKeys($now);
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $codes = array_map(fn (array $row): string => 'BOSS_KEY_' . $row[0], self::BOSS_KEYS);
        $ids = DB::table('materials')->whereIn('material_code', $codes)->pluck('id')->all();

        if (Schema::hasTable('material_drops') && $ids !== []) {
            DB::table('material_drops')->whereIn('material_id', $ids)->delete();
        }

        if (Schema::hasTable('character_materials') && $ids !== []) {
            DB::table('character_materials')->whereIn('material_id', $ids)->delete();
        }

        DB::table('materials')->whereIn('material_code', $codes)->delete();
    }

    private function backfillClearedBossKeys($now): void
    {
        if (!Schema::hasTable('character_area_progresses') || !Schema::hasTable('character_materials')) {
            return;
        }

        foreach (self::BOSS_KEYS as [$number, $areaName]) {
            $areaId = DB::table('areas')->where('name', $areaName)->value('id');
            $materialId = DB::table('materials')->where('material_code', 'BOSS_KEY_' . $number)->value('id');

            if (!$areaId || !$materialId) {
                continue;
            }

            $clearedCharacterIds = DB::table('character_area_progresses')
                ->where('area_id', $areaId)
                ->where('boss_defeated', true)
                ->pluck('character_id');

            foreach ($clearedCharacterIds as $characterId) {
                $exists = DB::table('character_materials')
                    ->where('character_id', $characterId)
                    ->where('material_id', $materialId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('character_materials')->insert([
                    'character_id' => $characterId,
                    'material_id' => $materialId,
                    'quantity' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
