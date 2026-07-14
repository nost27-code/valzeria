<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UPDATED_METHODS = [
        'MAT_BR_ARM_TRAVELER_ANCIENT' => 'フェルディア通常探索：フェルディア南岸、メイア河畔道',
        'MAT_BR_WPN_HOLY_ANCIENT' => 'フェルディア通常探索：アーデル遺跡、大樹の聖城',
        'MAT_BR_ARM_LIGHT_ANCIENT' => 'フェルディア通常探索：潮風の街道、静深き北森',
        'MAT_BR_WPN_GALE_ANCIENT' => 'フェルディア通常探索：見晴らしの丘道、大樹の聖城外縁',
        'MAT_BR_ARM_HEAVY_ANCIENT' => 'フェルディア通常探索：古道の石橋跡、王都グランフォード外郭路',
        'MAT_BR_WPN_DARK_ANCIENT' => 'フェルディア通常探索：北境の霊峰エルヴァン',
        'MAT_BR_ARM_ARCANE_ANCIENT' => 'フェルディア通常探索：清流リミュエール、水門街道',
    ];

    private const PREVIOUS_METHODS = [
        'MAT_BR_ARM_TRAVELER_ANCIENT' => '砂漠の宿場サンドラ高難度連戦 / 次元回廊。主地域では終盤ダンジョンの連戦300以上・ダンジョン主・試練に限定。。終盤レア敵4% / ダンジョン主35% / 高難度連戦宝箱12% / 古代宝箱10%',
        'MAT_BR_WPN_HOLY_ANCIENT' => '天空神殿セレスティア前半〜中盤 / 王都高難度試練。王都アークレアでは通常探索ドロップなし。高難度連戦/試練のみ例外的に入手可。。高位地域レア敵4% / 高位ダンジョン主35% / 主地域高難度試練20% / 古代宝箱10%',
        'MAT_BR_ARM_LIGHT_ANCIENT' => '天空神殿セレスティア前半〜中盤 / エルフィア高難度試練。精霊の森エルフィアでは通常探索ドロップなし。高難度連戦/試練のみ例外的に入手可。。高位地域レア敵4% / 高位ダンジョン主35% / 主地域高難度試練20% / 古代宝箱10%',
        'MAT_BR_WPN_GALE_ANCIENT' => '天空神殿セレスティア前半〜中盤 / エルフィア高難度試練。精霊の森エルフィアでは通常探索ドロップなし。高難度連戦/試練のみ例外的に入手可。。高位地域レア敵4% / 高位ダンジョン主35% / 主地域高難度試練20% / 古代宝箱10%',
        'MAT_BR_ARM_HEAVY_ANCIENT' => '古代錬成炉 / グランベルグ高難度連戦。主地域では終盤ダンジョンの連戦300以上・ダンジョン主・試練に限定。。終盤レア敵4% / ダンジョン主35% / 高難度連戦宝箱12% / 古代宝箱10%',
        'MAT_BR_WPN_DARK_ANCIENT' => '死霊街ネクロム高難度連戦 / 深淵の裂け目。主地域後半のため、終盤ダンジョンの高難度連戦・ダンジョン主から入手可。。後半レア敵4% / ダンジョン主35% / 高難度連戦宝箱12% / 古代宝箱10%',
        'MAT_BR_ARM_ARCANE_ANCIENT' => '魔導学院ルミナス高難度連戦 / 次元回廊。主地域後半のため、終盤ダンジョンの高難度連戦・ダンジョン主から入手可。。後半レア敵4% / ダンジョン主35% / 高難度連戦宝箱12% / 古代宝箱10%',
    ];

    public function up(): void
    {
        $this->updateMethods(self::UPDATED_METHODS);
    }

    public function down(): void
    {
        $this->updateMethods(self::PREVIOUS_METHODS);
    }

    private function updateMethods(array $methods): void
    {
        foreach ($methods as $code => $method) {
            DB::table('materials')
                ->where('material_code', $code)
                ->update(['obtain_method' => $method, 'updated_at' => now()]);
        }
    }
};
