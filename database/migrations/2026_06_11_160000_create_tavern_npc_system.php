<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('npc_master', function (Blueprint $table) {
            $table->unsignedInteger('npc_id')->primary();
            $table->string('npc_name', 100);
            $table->string('npc_rank', 20);
            $table->string('npc_title', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('appear_condition_type', 50)->default('always');
            $table->string('appear_condition_value', 100)->default('0');
            $table->unsignedInteger('base_weight')->default(100);
            $table->text('talk_text');
            $table->text('hint_text')->nullable();
            $table->text('relation_text')->nullable();
            $table->unsignedInteger('related_npc_id')->nullable();
            $table->string('reward_type', 50)->nullable();
            $table->string('reward_value', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('player_npc_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('npc_id');
            $table->timestamp('first_encountered_at')->useCurrent();
            $table->unsignedInteger('encounter_count')->default(1);
            $table->timestamp('last_encountered_at')->useCurrent();
            $table->timestamps();
            $table->unique(['character_id', 'npc_id']);
            $table->index('character_id');
            $table->index('npc_id');
        });

        Schema::create('player_tavern_daily_npcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->date('tavern_date');
            $table->unsignedInteger('npc_id');
            $table->unsignedTinyInteger('slot_no');
            $table->timestamps();
            $table->unique(['character_id', 'tavern_date', 'slot_no']);
            $table->index(['character_id', 'tavern_date']);
        });

        Schema::create('player_tavern_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('visit_count')->default(0);
            $table->timestamp('last_visited_at')->nullable();
            $table->timestamps();
        });

        $this->seedNpcMaster();
    }

    public function down(): void
    {
        Schema::dropIfExists('player_tavern_visits');
        Schema::dropIfExists('player_tavern_daily_npcs');
        Schema::dropIfExists('player_npc_encounters');
        Schema::dropIfExists('npc_master');
    }

    private function seedNpcMaster(): void
    {
        $rows = [
            [1, '疾風のコジロウ', 'common', 'total_exploration_count', '10', '探索の基本ヒント', '豪腕のムサシを一方的にライバル視'],
            [2, '豪腕のムサシ', 'common', 'total_exploration_count', '20', '戦士系ヒント', '疾風のコジロウを軽くあしらう'],
            [3, '放浪のヤマト', 'common', 'total_exploration_count', '50', '次都市の噂', '深淵歩きのセツナの古い知人'],
            [4, '小銭拾いのミミック', 'common', 'gold', '1000', '金策ヒント', '商魂のゴードンを尊敬'],
            [5, '草原帰りのニコ', 'common', 'cleared_area_id', '1', '初心者導線', '疾風のコジロウに憧れている'],
            [6, '森歩きのルッカ', 'common', 'cleared_area_id', '2', '森系敵のヒント', '精霊都市エルフィアの噂に詳しい'],
            [7, '洞窟好きのボルト', 'common', 'cleared_area_id', '3', '探索度ヒント', '鉄槌のガンツの弟子'],
            [8, '見習い僧侶マノン', 'common', 'defeat_count', '1', '救助費の説明', '盾持ちのバルドに憧れている'],
            [9, '駆け出し剣士レオン', 'common', 'player_level', '10', '剣士系ヒント', '剣聖ハヤトを目標にしている'],
            [10, '泣き虫魔法使いピノ', 'common', 'player_level', '12', 'MP管理ヒント', '大賢者エルメリアの弟子志望'],
            [11, '港風のセイル', 'common', 'reached_city_id', '2', '港町の噂', '海賊船長ロイドの部下'],
            [12, '潮騒のマリィ', 'common', 'reached_city_id', '2', '素材売買ヒント', '港風のセイルの幼なじみ'],
            [13, '森笛のフィオ', 'common', 'reached_city_id', '3', '精霊系ヒント', '精霊騎士フィリアを姉のように慕う'],
            [14, '鉄槌のガンツ', 'common', 'reached_city_id', '4', '鍛冶屋ヒント', '洞窟好きのボルトの師匠'],
            [15, '雪見のノエル', 'common', 'reached_city_id', '5', '氷耐性ヒント', '雪狼のエイルに助けられた過去がある'],
            [16, '砂読みのサーラ', 'common', 'reached_city_id', '6', '砂漠探索ヒント', '砂王カリムの娘'],
            [17, '魔導書売りリゼ', 'common', 'reached_city_id', '7', '魔法系装備ヒント', '大賢者エルメリアを尊敬'],
            [18, '墓守りのグレイ', 'common', 'reached_city_id', '8', '魔界系ヒント', '暗黒騎士ノクトを恐れている'],
            [19, '空渡りのティル', 'common', 'reached_city_id', '9', '天空都市ヒント', '竜騎士ヴァイスの案内役'],
            [20, '火山見張りのダン', 'common', 'reached_city_id', '10', '終盤ヒント', '魔王城から生還した数少ない冒険者'],
            [21, '片目のレン', 'skilled', 'total_exploration_count', '100', '連戦・危険度ヒント', '放浪のヤマトと昔パーティを組んだ'],
            [22, '黒猫のシオン', 'skilled', 'rare_drop_count', '1', 'レアドロップヒント', '小銭拾いのミミックと情報交換する'],
            [23, '銀弓のアリサ', 'skilled', 'job_rank', '3:5', '弓職ヒント', '狙撃手クロードの妹弟子'],
            [24, '盾持ちのバルド', 'skilled', 'defeat_count', '3', '救助隊・防御ヒント', '見習い僧侶マノンの憧れ'],
            [25, '双剣のカイ', 'skilled', 'job_rank', '2:5', 'SPD・回避ヒント', '忍びのゲンジの弟子'],
            [26, '白衣のセレナ', 'skilled', 'job_rank', '6:5', '回復・SPRヒント', '司祭オルフェの弟子'],
            [27, '紅蓮のラウル', 'skilled', 'job_rank', '5:5', '火属性ヒント', '氷華のレイナと競争中'],
            [28, '氷華のレイナ', 'skilled', 'reached_city_id', '5', '氷属性ヒント', '紅蓮のラウルと競争中'],
            [29, '商魂のゴードン', 'skilled', 'gold', '10000', '金策ヒント', '小銭拾いのミミックに慕われる'],
            [30, '静寂のミナト', 'skilled', 'chain_count', '20', '帰還判断ヒント', '片目のレンと危険度論争をする'],
            [31, '狙撃手クロード', 'skilled', 'mastered_job_id', '3', '狙撃手解放ヒント', '銀弓のアリサの師匠'],
            [32, '忍びのゲンジ', 'skilled', 'total_job_master_count', '2', '忍者解放ヒント', '双剣のカイに試練を与える'],
            [33, '司祭オルフェ', 'skilled', 'total_job_master_count', '2', '司祭解放ヒント', '白衣のセレナの師匠'],
            [34, '魔剣のフェリクス', 'skilled', 'total_job_master_count', '2', '魔法剣士ヒント', '剣聖ハヤトに敗れた過去がある'],
            [35, '聖盾のクラウス', 'skilled', 'total_job_master_count', '2', '聖騎士ヒント', '盾持ちのバルドの元上官'],
            [36, '狂牙のバズ', 'skilled', 'total_job_master_count', '2', '狂戦士ヒント', '豪腕のムサシを勧誘したが断られた'],
            [37, '地図屋のパメラ', 'skilled', 'hidden_area_count', '1', '秘境地図ヒント', '放浪のヤマトに古い地図を渡した'],
            [38, '酒場娘リリィ', 'skilled', 'tavern_visit_count', '10', 'NPC図鑑ヒント', '全NPCの噂を少しずつ知っている'],
            [39, '老冒険者ロウガ', 'skilled', 'player_level', '50', '昔話・称号ヒント', '終焉帰りのレギウスの旧友'],
            [40, '無口な傭兵ゼイン', 'skilled', 'arena_win_count', '10', '模擬戦ヒント', '武神ゴウライの元門下'],
            [41, '剣聖ハヤト', 'hero', 'mastered_job_id', '4', '剣聖・勇者導線', '駆け出し剣士レオンの憧れ'],
            [42, '大賢者エルメリア', 'hero', 'mastered_job_id', '5', '大賢者導線', 'ピノとリゼに慕われる'],
            [43, '武神ゴウライ', 'hero', 'mastered_job_id', '7', '武神導線', '無口な傭兵ゼインの元師匠'],
            [44, '竜騎士ヴァイス', 'hero', 'reached_city_id', '9', '竜騎士導線', '空渡りのティルの恩人'],
            [45, '黄金商人ミダス', 'hero', 'mastered_job_id', '8', '黄金商人導線', '商魂のゴードンの目標'],
            [46, '幻影王シグレ', 'hero', 'total_job_master_count', '3', '幻影王導線', '忍びのゲンジの兄弟子'],
            [47, '暗黒騎士ノクト', 'hero', 'reached_city_id', '8', '暗黒騎士導線', '墓守りのグレイに恐れられている'],
            [48, '聖女アリア', 'hero', 'mastered_job_id', '6', '回復系導線', '司祭オルフェが守護している'],
            [49, '機工王バルカン', 'hero', 'cleared_city_id', '4', '機工王導線', '鉄槌のガンツの工房仲間'],
            [50, '賢商王ロレンス', 'hero', 'total_job_master_count', '3', '賢商王導線', '黄金商人ミダスの好敵手'],
            [51, '深淵狩りのリンド', 'hero', 'reached_city_id', '8', '深淵素材ヒント', '暗黒騎士ノクトを警戒している'],
            [52, '砂王カリム', 'hero', 'cleared_city_id', '6', '砂漠秘境ヒント', '砂読みのサーラの父'],
            [53, '雪狼のエイル', 'hero', 'cleared_city_id', '5', '氷秘境ヒント', '雪見のノエルの恩人'],
            [54, '精霊騎士フィリア', 'hero', 'cleared_city_id', '3', '精霊素材ヒント', '森笛のフィオの姉的存在'],
            [55, '海賊船長ロイド', 'hero', 'cleared_city_id', '2', '航路ヒント', '港風のセイルの船長'],
            [56, '王都騎士団長アルベルト', 'hero', 'cleared_city_id', '1', '王都称号ヒント', '聖盾のクラウスの元同僚'],
            [57, '監察官ミレイユ', 'hero', 'cleared_city_id', '7', '魔導研究ヒント', '大賢者エルメリアの友人'],
            [58, '天翼のセラフィナ', 'hero', 'cleared_city_id', '9', '天空秘境ヒント', '竜騎士ヴァイスと共闘経験あり'],
            [59, '魔界渡りのカロン', 'hero', 'reached_city_id', '8', 'ネクロム深部ヒント', '暗黒騎士ノクトを知る案内人'],
            [60, '終焉帰りのレギウス', 'hero', 'reached_city_id', '10', '魔王城ヒント', '老冒険者ロウガの旧友'],
            [61, '始まりの英雄アレス', 'legend', 'total_job_master_count', '3', '伝説職ヒント', '三英雄の師'],
            [62, '深淵歩きのセツナ', 'legend', 'hidden_area_count', '2', '深淵歩き導線', '放浪のヤマトの旧友'],
            [63, '古代錬成王ドワルド', 'legend', 'hidden_area_count', '3', '古代錬成王導線', '機工王バルカンの師匠格'],
            [64, '竜神の巫女リュシア', 'legend', 'cleared_city_id', '9', '蒼竜王導線', '竜騎士ヴァイスが守護している'],
            [65, '時空王クロノス', 'legend', 'hidden_area_count', '4', '時空王導線', '幻影王シグレが追っている存在'],
            [66, '名もなき勇者', 'legend', 'defeated_final_boss', '1', 'エンド後導線', '始まりの英雄アレスの最後の弟子'],
            [67, '魔王軍離反者ヴェルン', 'legend', 'defeated_final_boss', '1', '魔王軍情報', '終焉帰りのレギウスと因縁がある'],
            [68, '星詠みのノア', 'legend', 'title_count', '50', '隠し称号ヒント', '時空王クロノスの行方を知っている'],
            [69, '宝物庫の番人グリム', 'legend', 'rare_drop_count', '30', 'レア装備導線', '小銭拾いのミミックの秘密を知る'],
            [70, 'ヴァルゼリアの古老エルド', 'legend', 'npc_encounter_count', '60', '世界の真相ヒント', '全NPC関係図の中心人物'],
        ];

        $now = now();
        $rankWeight = ['common' => 100, 'skilled' => 60, 'hero' => 25, 'legend' => 8];
        $rankLabel = ['common' => '一般冒険者', 'skilled' => '熟練冒険者', 'hero' => '英雄冒険者', 'legend' => '伝説の冒険者'];

        $insert = array_map(function (array $row) use ($now, $rankWeight, $rankLabel) {
            [$id, $name, $rank, $conditionType, $conditionValue, $role, $relation] = $row;

            return [
                'npc_id' => $id,
                'npc_name' => $name,
                'npc_rank' => $rank,
                'npc_title' => $rankLabel[$rank],
                'description' => "{$role}を語る{$rankLabel[$rank]}。酒場で旅人たちに短い助言を残している。",
                'appear_condition_type' => $conditionType,
                'appear_condition_value' => $conditionValue,
                'base_weight' => $rankWeight[$rank],
                'talk_text' => "「{$role}なら、無理に急がないことだ。装備、職業、引き際。この三つを見誤らなければ、冒険はまだ続けられる。」",
                'hint_text' => "{$role}。今の冒険で詰まったら、探索度、危険度、職業熟練度、装備の相性を見直してみよう。",
                'relation_text' => $relation,
                'is_active' => true,
                'sort_order' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        DB::table('npc_master')->upsert(
            $insert,
            ['npc_id'],
            ['npc_name', 'npc_rank', 'npc_title', 'description', 'appear_condition_type', 'appear_condition_value', 'base_weight', 'talk_text', 'hint_text', 'relation_text', 'is_active', 'sort_order', 'updated_at']
        );
    }
};

