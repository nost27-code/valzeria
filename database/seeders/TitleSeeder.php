<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Title;
use Illuminate\Support\Facades\DB;

class TitleSeeder extends Seeder
{
    public function run()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('titles')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $titles = [
            [1, 'system', 'common', '駆け出し冒険者', '初期所持', '冒険者登録を済ませて、最初の一歩を踏み出そう。', 'initial', null, null, '初期設計', 1, true],
            [2, 'battle', 'common', 'はじめての勝利', '通常戦闘で初勝利する', 'まずは目の前の敵に勝利してみよう。', 'battle_win_count', 'count', '1', '戦闘ログ', 2, true],
            [3, 'boss', 'common', '初めての討伐者', '初めてボスを撃破する', '通常の敵とは違う、強大な相手に挑んでみよう。', 'boss_clear_count', 'count', '1', 'ボスマスタ', 3, true],
            [15, 'dungeon_clear', 'common', '草原を歩む者', 'はじまりの草原のボスを撃破する', '王都アークレアの「はじまりの草原」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '1', 'ダンジョンマスタ', 4, true],
            [16, 'dungeon_clear', 'common', '小鬼の森を鎮めし者', '小鬼の森のボスを撃破する', '王都アークレアの「小鬼の森」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '2', 'ダンジョンマスタ', 5, true],
            [17, 'dungeon_clear', 'common', '洞窟を越えし者', '古びた洞窟のボスを撃破する', '王都アークレアの「古びた洞窟」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '3', 'ダンジョンマスタ', 6, true],
            [18, 'dungeon_clear', 'common', '銀狼の丘を越えし者', '狼の丘のボスを撃破する', '王都アークレアの「狼の丘」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '4', 'ダンジョンマスタ', 7, true],
            [19, 'dungeon_clear', 'common', '墓守を退けし者', '忘れられた墓地のボスを撃破する', '王都アークレアの「忘れられた墓地」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '5', 'ダンジョンマスタ', 8, true],
            [20, 'dungeon_clear', 'common', '泉に認められし者', '妖精の泉のボスを撃破する', '王都アークレアの「妖精の泉」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '6', 'ダンジョンマスタ', 9, true],
            [21, 'dungeon_clear', 'common', '王都の卒業者', '見習い訓練場のボスを撃破する', '王都アークレアの「見習い訓練場」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '7', 'ダンジョンマスタ', 10, true],
            [22, 'dungeon_clear', 'common', '潮風を浴びし者', '潮風の海岸のボスを撃破する', '港町マリネスの「潮風の海岸」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '8', 'ダンジョンマスタ', 11, true],
            [23, 'dungeon_clear', 'common', '海蝕洞窟の踏破者', '海蝕洞窟のボスを撃破する', '港町マリネスの「海蝕洞窟」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '9', 'ダンジョンマスタ', 12, true],
            [24, 'dungeon_clear', 'common', '幽霊船を越えし者', '難破船の残骸のボスを撃破する', '港町マリネスの「難破船の残骸」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '10', 'ダンジョンマスタ', 13, true],
            [25, 'dungeon_clear', 'common', '入り江に歌を残す者', '人魚の入り江のボスを撃破する', '港町マリネスの「人魚の入り江」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '11', 'ダンジョンマスタ', 14, true],
            [26, 'dungeon_clear', 'common', '海賊砦を制した者', '海賊の隠れ家のボスを撃破する', '港町マリネスの「海賊の隠れ家」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '12', 'ダンジョンマスタ', 15, true],
            [27, 'dungeon_clear', 'common', '珊瑚迷宮の踏破者', '珊瑚迷宮のボスを撃破する', '港町マリネスの「珊瑚迷宮」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '13', 'ダンジョンマスタ', 16, true],
            [28, 'dungeon_clear', 'common', '深海神殿を越えし者', '深海神殿のボスを撃破する', '港町マリネスの「深海神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '14', 'ダンジョンマスタ', 17, true],
            [29, 'dungeon_clear', 'common', '若葉の森を守る者', '若葉の森のボスを撃破する', '精霊の森エルフィアの「若葉の森」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '15', 'ダンジョンマスタ', 18, true],
            [30, 'dungeon_clear', 'common', '妖精森の友', '妖精の森のボスを撃破する', '精霊の森エルフィアの「妖精の森」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '16', 'ダンジョンマスタ', 19, true],
            [31, 'dungeon_clear', 'common', '世界樹の根に触れし者', '世界樹の根のボスを撃破する', '精霊の森エルフィアの「世界樹の根」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '17', 'ダンジョンマスタ', 20, true],
            [32, 'dungeon_clear', 'common', '世界樹を登る者', '世界樹中層のボスを撃破する', '精霊の森エルフィアの「世界樹中層」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '18', 'ダンジョンマスタ', 21, true],
            [33, 'dungeon_clear', 'common', '世界樹の高みへ至る者', '世界樹上層のボスを撃破する', '精霊の森エルフィアの「世界樹上層」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '19', 'ダンジョンマスタ', 22, true],
            [34, 'dungeon_clear', 'common', '精霊神殿の認定者', '精霊神殿のボスを撃破する', '精霊の森エルフィアの「精霊神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '20', 'ダンジョンマスタ', 23, true],
            [35, 'dungeon_clear', 'common', '月光を歩む者', '月光庭園のボスを撃破する', '精霊の森エルフィアの「月光庭園」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '21', 'ダンジョンマスタ', 24, true],
            [36, 'dungeon_clear', 'rare', '鉄鉱山の開拓者', '鉄鉱山のボスを撃破する', '鍛冶街グランベルグの「鉄鉱山」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '22', 'ダンジョンマスタ', 25, true],
            [37, 'dungeon_clear', 'rare', '廃坑を照らす者', '廃坑のボスを撃破する', '鍛冶街グランベルグの「廃坑」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '23', 'ダンジョンマスタ', 26, true],
            [38, 'dungeon_clear', 'rare', '炉跡を越えし者', '溶鉱炉跡のボスを撃破する', '鍛冶街グランベルグの「溶鉱炉跡」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '24', 'ダンジョンマスタ', 27, true],
            [39, 'dungeon_clear', 'rare', '蒸気工場の制圧者', '蒸気工場のボスを撃破する', '鍛冶街グランベルグの「蒸気工場」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '25', 'ダンジョンマスタ', 28, true],
            [40, 'dungeon_clear', 'rare', '機械兵を止めし者', '機械兵工場のボスを撃破する', '鍛冶街グランベルグの「機械兵工場」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '26', 'ダンジョンマスタ', 29, true],
            [41, 'dungeon_clear', 'rare', '地底の光を取り戻す者', '地底発電所のボスを撃破する', '鍛冶街グランベルグの「地底発電所」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '27', 'ダンジョンマスタ', 30, true],
            [42, 'dungeon_clear', 'rare', '古代兵器庫の封印者', '古代兵器庫のボスを撃破する', '鍛冶街グランベルグの「古代兵器庫」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '28', 'ダンジョンマスタ', 31, true],
            [43, 'dungeon_clear', 'rare', '氷雪を進む者', '氷雪平原のボスを撃破する', '雪原の町フロストリアの「氷雪平原」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '29', 'ダンジョンマスタ', 32, true],
            [44, 'dungeon_clear', 'rare', '吹雪を裂く者', '吹雪の峡谷のボスを撃破する', '雪原の町フロストリアの「吹雪の峡谷」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '30', 'ダンジョンマスタ', 33, true],
            [45, 'dungeon_clear', 'rare', '氷結洞窟の踏破者', '氷結洞窟のボスを撃破する', '雪原の町フロストリアの「氷結洞窟」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '31', 'ダンジョンマスタ', 34, true],
            [46, 'dungeon_clear', 'rare', '白銀の森を越えし者', '白銀の森のボスを撃破する', '雪原の町フロストリアの「白銀の森」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '32', 'ダンジョンマスタ', 35, true],
            [47, 'dungeon_clear', 'rare', '凍てつく神殿の勝者', '凍てつく神殿のボスを撃破する', '雪原の町フロストリアの「凍てつく神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '33', 'ダンジョンマスタ', 36, true],
            [48, 'dungeon_clear', 'rare', '氷竜の巣を越えし者', '氷竜の巣のボスを撃破する', '雪原の町フロストリアの「氷竜の巣」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '34', 'ダンジョンマスタ', 37, true],
            [49, 'dungeon_clear', 'rare', '極寒を踏破せし者', '極寒山脈のボスを撃破する', '雪原の町フロストリアの「極寒山脈」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '35', 'ダンジョンマスタ', 38, true],
            [50, 'dungeon_clear', 'rare', '砂海を渡る者', '砂海のボスを撃破する', '砂漠の宿場サンドラの「砂海」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '36', 'ダンジョンマスタ', 39, true],
            [51, 'dungeon_clear', 'rare', '流砂を抜けし者', '流砂地帯のボスを撃破する', '砂漠の宿場サンドラの「流砂地帯」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '37', 'ダンジョンマスタ', 40, true],
            [52, 'dungeon_clear', 'rare', '古代遺跡の解読者', '古代遺跡のボスを撃破する', '砂漠の宿場サンドラの「古代遺跡」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '38', 'ダンジョンマスタ', 41, true],
            [53, 'dungeon_clear', 'rare', '王家の眠りを守る者', '王家の墓のボスを撃破する', '砂漠の宿場サンドラの「王家の墓」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '39', 'ダンジョンマスタ', 42, true],
            [54, 'dungeon_clear', 'rare', '砂嵐を鎮めし者', '砂嵐の神殿のボスを撃破する', '砂漠の宿場サンドラの「砂嵐の神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '40', 'ダンジョンマスタ', 43, true],
            [55, 'dungeon_clear', 'rare', '地下水路の探索者', '地下水路のボスを撃破する', '砂漠の宿場サンドラの「地下水路」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '41', 'ダンジョンマスタ', 44, true],
            [56, 'dungeon_clear', 'rare', '太陽神殿に至る者', '太陽神殿のボスを撃破する', '砂漠の宿場サンドラの「太陽神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '42', 'ダンジョンマスタ', 45, true],
            [57, 'dungeon_clear', 'epic', '魔導図書館の閲覧者', '魔導図書館のボスを撃破する', '魔導学院ルミナスの「魔導図書館」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '43', 'ダンジョンマスタ', 46, true],
            [58, 'dungeon_clear', 'epic', '禁書庫の封印者', '禁書庫のボスを撃破する', '魔導学院ルミナスの「禁書庫」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '44', 'ダンジョンマスタ', 47, true],
            [59, 'dungeon_clear', 'epic', '魔法研究所の調査員', '魔法研究所のボスを撃破する', '魔導学院ルミナスの「魔法研究所」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '45', 'ダンジョンマスタ', 48, true],
            [60, 'dungeon_clear', 'epic', '浮遊庭園を歩く者', '浮遊庭園のボスを撃破する', '魔導学院ルミナスの「浮遊庭園」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '46', 'ダンジョンマスタ', 49, true],
            [61, 'dungeon_clear', 'epic', '星を見上げし者', '星見の塔のボスを撃破する', '魔導学院ルミナスの「星見の塔」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '47', 'ダンジョンマスタ', 50, true],
            [62, 'dungeon_clear', 'epic', '異界を覗きし者', '異界観測所のボスを撃破する', '魔導学院ルミナスの「異界観測所」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '48', 'ダンジョンマスタ', 51, true],
            [63, 'dungeon_clear', 'epic', '次元回廊の踏破者', '次元回廊のボスを撃破する', '魔導学院ルミナスの「次元回廊」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '49', 'ダンジョンマスタ', 52, true],
            [64, 'dungeon_clear', 'epic', '死者の荒野を越えし者', '死者の荒野のボスを撃破する', '死霊街ネクロムの「死者の荒野」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '50', 'ダンジョンマスタ', 53, true],
            [65, 'dungeon_clear', 'epic', '呪城を破りし者', '呪われた城のボスを撃破する', '死霊街ネクロムの「呪われた城」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '51', 'ダンジョンマスタ', 54, true],
            [66, 'dungeon_clear', 'epic', '冥界門を開きし者', '冥界門のボスを撃破する', '死霊街ネクロムの「冥界門」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '52', 'ダンジョンマスタ', 55, true],
            [67, 'dungeon_clear', 'epic', '悪魔神殿の破壊者', '悪魔神殿のボスを撃破する', '死霊街ネクロムの「悪魔神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '53', 'ダンジョンマスタ', 56, true],
            [68, 'dungeon_clear', 'epic', '魔王軍を砕く者', '魔王軍要塞のボスを撃破する', '死霊街ネクロムの「魔王軍要塞」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '54', 'ダンジョンマスタ', 57, true],
            [69, 'dungeon_clear', 'epic', '瘴気を越えし者', '瘴気の谷のボスを撃破する', '死霊街ネクロムの「瘴気の谷」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '55', 'ダンジョンマスタ', 58, true],
            [70, 'dungeon_clear', 'epic', '奈落へ降りし者', '奈落への階段のボスを撃破する', '死霊街ネクロムの「奈落への階段」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '56', 'ダンジョンマスタ', 59, true],
            [71, 'dungeon_clear', 'epic', '雲海を渡る者', '雲海平原のボスを撃破する', '天空神殿セレスティアの「雲海平原」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '57', 'ダンジョンマスタ', 60, true],
            [72, 'dungeon_clear', 'epic', '天空回廊の踏破者', '天空回廊のボスを撃破する', '天空神殿セレスティアの「天空回廊」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '58', 'ダンジョンマスタ', 61, true],
            [73, 'dungeon_clear', 'epic', '雷鳴を制する者', '雷鳴神殿のボスを撃破する', '天空神殿セレスティアの「雷鳴神殿」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '59', 'ダンジョンマスタ', 62, true],
            [74, 'dungeon_clear', 'epic', '浮遊遺跡の解放者', '浮遊遺跡のボスを撃破する', '天空神殿セレスティアの「浮遊遺跡」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '60', 'ダンジョンマスタ', 63, true],
            [75, 'dungeon_clear', 'epic', '天使の庭園に招かれし者', '天使の庭園のボスを撃破する', '天空神殿セレスティアの「天使の庭園」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '61', 'ダンジョンマスタ', 64, true],
            [76, 'dungeon_clear', 'epic', '星辰の塔を越えし者', '星辰の塔のボスを撃破する', '天空神殿セレスティアの「星辰の塔」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '62', 'ダンジョンマスタ', 65, true],
            [77, 'dungeon_clear', 'epic', '神々の祭壇に立つ者', '神々の祭壇のボスを撃破する', '天空神殿セレスティアの「神々の祭壇」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '63', 'ダンジョンマスタ', 66, true],
            [78, 'dungeon_clear', 'legendary', '魔王領へ踏み込む者', '魔王領外郭のボスを撃破する', '魔王城ヴァルゼリアの「魔王領外郭」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '64', 'ダンジョンマスタ', 67, true],
            [79, 'dungeon_clear', 'legendary', '絶望を越えし者', '絶望の回廊のボスを撃破する', '魔王城ヴァルゼリアの「絶望の回廊」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '65', 'ダンジョンマスタ', 68, true],
            [80, 'dungeon_clear', 'legendary', '魔神の間の勝者', '魔神の間のボスを撃破する', '魔王城ヴァルゼリアの「魔神の間」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '66', 'ダンジョンマスタ', 69, true],
            [81, 'dungeon_clear', 'legendary', '牢獄を破りし者', '深淵の牢獄のボスを撃破する', '魔王城ヴァルゼリアの「深淵の牢獄」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '67', 'ダンジョンマスタ', 70, true],
            [82, 'dungeon_clear', 'legendary', '黒き玉座に迫る者', '黒き玉座のボスを撃破する', '魔王城ヴァルゼリアの「黒き玉座」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '68', 'ダンジョンマスタ', 71, true],
            [83, 'dungeon_clear', 'legendary', '魔王城中枢を制した者', '魔王城中枢のボスを撃破する', '魔王城ヴァルゼリアの「魔王城中枢」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '69', 'ダンジョンマスタ', 72, true],
            [84, 'dungeon_clear', 'legendary', '終焉に抗う者', '終焉の祭壇のボスを撃破する', '魔王城ヴァルゼリアの「終焉の祭壇」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '70', 'ダンジョンマスタ', 73, true],
            [85, 'extra_dungeon_clear', 'mythic', '深淵に名を刻む者', '深淵の裂け目のボスを撃破する', '死霊街ネクロムの「深淵の裂け目」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '71', 'ダンジョンマスタ', 74, true],
            [86, 'extra_dungeon_clear', 'mythic', '古代炉を鎮めし者', '古代錬成炉のボスを撃破する', '鍛冶街グランベルグの「古代錬成炉」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '72', 'ダンジョンマスタ', 75, true],
            [87, 'extra_dungeon_clear', 'mythic', '竜域の踏破者', '竜王の聖域のボスを撃破する', '天空神殿セレスティアの「竜王の聖域」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '73', 'ダンジョンマスタ', 76, true],
            [88, 'extra_dungeon_clear', 'mythic', '次元回廊の踏破者', '次元回廊のボスを撃破する', '魔導学院ルミナスの「次元回廊」でボスに挑み、勝利しよう。', 'dungeon_boss_clear', 'dungeon', '74', 'ダンジョンマスタ', 77, true],
            [5, 'city_clear', 'common', 'アークレアの守り手', '王都アークレアに属する全ダンジョンのボスを撃破する', '王都アークレア周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '1', '街マスタ/ダンジョンマスタ', 78, true],
            [6, 'city_clear', 'rare', 'マリネスの潮風を越えし者', '港町マリネスに属する全ダンジョンのボスを撃破する', '港町マリネス周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '2', '街マスタ/ダンジョンマスタ', 79, true],
            [7, 'city_clear', 'rare', 'エルフィアに認められし者', '精霊の森エルフィアに属する全ダンジョンのボスを撃破する', '精霊の森エルフィア周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '3', '街マスタ/ダンジョンマスタ', 80, true],
            [8, 'city_clear', 'epic', 'グランベルグの制覇者', '鍛冶街グランベルグに属する全ダンジョンのボスを撃破する', '鍛冶街グランベルグ周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '4', '街マスタ/ダンジョンマスタ', 81, true],
            [9, 'city_clear', 'epic', 'フロストリアの白銀踏破者', '雪原の町フロストリアに属する全ダンジョンのボスを撃破する', '雪原の町フロストリア周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '5', '街マスタ/ダンジョンマスタ', 82, true],
            [10, 'city_clear', 'epic', 'サンドラの砂塵を征した者', '砂漠の宿場サンドラに属する全ダンジョンのボスを撃破する', '砂漠の宿場サンドラ周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '6', '街マスタ/ダンジョンマスタ', 83, true],
            [11, 'city_clear', 'epic', 'ルミナスの深奥に触れし者', '魔導学院ルミナスに属する全ダンジョンのボスを撃破する', '魔導学院ルミナス周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '7', '街マスタ/ダンジョンマスタ', 84, true],
            [12, 'city_clear', 'legendary', 'ネクロムの闇を越えし者', '死霊街ネクロムに属する全ダンジョンのボスを撃破する', '死霊街ネクロム周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '8', '街マスタ/ダンジョンマスタ', 85, true],
            [13, 'city_clear', 'legendary', 'セレスティアの試練を越えし者', '天空神殿セレスティアに属する全ダンジョンのボスを撃破する', '天空神殿セレスティア周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '9', '街マスタ/ダンジョンマスタ', 86, true],
            [14, 'city_clear', 'legendary', '黒き城を踏破せし者', '魔王城ヴァルゼリアに属する全ダンジョンのボスを撃破する', '魔王城ヴァルゼリア周辺のダンジョンをすべて踏破しよう。', 'city_all_dungeons_clear', 'city', '10', '街マスタ/ダンジョンマスタ', 87, true],
            [4, 'world_clear', 'mythic', 'ヴァルゼリアの覇者', '全街に属する全ダンジョンのボスを撃破する', 'この世界に存在するすべてのダンジョンを制覇しよう。', 'all_dungeons_clear', 'world', '0', 'ダンジョンマスタ', 88, true],
            [89, 'job_master', 'rare', '剣を極めし者', '剣士をマスターする', '剣士として修練を積み、職業を最後まで鍛えよう。', 'job_master', 'job_name', '剣士', '職業マスタ', 89, true],
            [90, 'job_master', 'rare', '武勇を刻む者', '戦士をマスターする', '戦士として戦い続け、武勇を磨ききろう。', 'job_master', 'job_name', '戦士', '職業マスタ', 90, true],
            [91, 'job_master', 'rare', '影を駆ける者', '盗賊をマスターする', '盗賊として素早さと運を活かし、職業を極めよう。', 'job_master', 'job_name', '盗賊', '職業マスタ', 91, true],
            [92, 'job_master', 'rare', '狙いを外さぬ者', '弓使いをマスターする', '弓使いとして狙いを磨き、職業を最後まで育てよう。', 'job_master', 'job_name', '弓使い', '職業マスタ', 92, true],
            [93, 'job_master', 'rare', '拳で語る者', '格闘家をマスターする', '格闘家として拳を鍛え、職業を極めよう。', 'job_master', 'job_name', '格闘家', '職業マスタ', 93, true],
            [94, 'job_master', 'rare', '魔導を歩む者', '魔法使いをマスターする', '魔法使いとして魔力を磨き、職業を極めよう。', 'job_master', 'job_name', '魔法使い', '職業マスタ', 94, true],
            [95, 'job_master', 'rare', '癒やしの導き手', '僧侶をマスターする', '僧侶として祈りと守りを磨き、職業を極めよう。', 'job_master', 'job_name', '僧侶', '職業マスタ', 95, true],
            [96, 'job_master', 'rare', '商いを知る冒険者', '商人をマスターする', '商人として冒険と商いを重ね、職業を極めよう。', 'job_master', 'job_name', '商人', '職業マスタ', 96, true],
            [97, 'job_rank', 'epic', '上級職の門を開く者', '初めて上級職に転職する', '複数の職業を極め、上級職への道を開こう。', 'first_rank_job', 'rank', 'Advanced', '職業マスタ/転職条件', 97, true],
            [98, 'job_rank', 'legendary', '伝説職の継承者', '初めて伝説職に転職する', '限られた冒険者だけが進める伝説職へ到達しよう。', 'first_rank_job', 'rank', 'Legend', '職業マスタ/伝説職条件', 98, true],
            [99, 'job_master', 'legendary', '職を極めし冒険者', '累計10職業をマスターする', 'さまざまな職業を経験し、10の職業を極めよう。', 'job_master_count', 'count', '10', '職業マスタ', 99, true],
            [100, 'job_master', 'mythic', '万職不敗の求道者', '全職業をマスターする', 'ヴァルゼリアに存在するすべての職業を極めよう。', 'all_jobs_master', 'all_jobs', '0', '職業マスタ', 100, true],
        ];

        $insertData = [];
        foreach ($titles as $title) {
            $insertData[] = [
                'id' => $title[0],
                'category' => $title[1],
                'rarity' => $title[2],
                'name' => $title[3],
                'description' => $title[4],
                'hint' => $title[5],
                'unlock_type' => $title[6],
                'target_type' => $title[7],
                'target_id' => $title[8],
                'source_master' => $title[9],
                'display_order' => $title[10],
                'is_hidden' => $title[11],
            ];
        }

        DB::table('titles')->insert($insertData);
    }
}
