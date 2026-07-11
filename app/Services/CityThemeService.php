<?php

namespace App\Services;

use App\Support\CityVisualCatalog;

class CityThemeService
{
    public function backgroundColorForCityId(?int $cityId): string
    {
        return match ((int) $cityId) {
            1 => '#E8E2D2', // 王都アークレア
            2 => '#DCEEF4', // 港町マリナス
            3 => '#DDF2D9', // 精霊の森エルフィア
            4 => '#DFE3E9', // 鍛冶街グランベルグ
            5 => '#DDF0F8', // 雪原の町フロストリア
            6 => '#F2E4C4', // 砂漠の宿場サンドラ
            7 => '#E2DBF4', // 魔導学院ルミナス
            8 => '#E8DFEA', // 死霊街ネクロム
            9 => '#E0EFFF', // 天空神殿セレスティア
            10 => '#EEDADD', // 魔王城ヴァルゼリア
            default => '#F3F5F9',
        };
    }

    public function bgImageForCityId(?int $cityId): ?string
    {
        $path = CityVisualCatalog::cardBackground($cityId);

        return $path ? 'images/' . $path : null;
    }
}
