<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // はじまりの草原（area_id=1）の敵に登録されている都市依存素材（city_id IS NOT NULL）を無効化する。
        // 旧システムでは grassland フィルタで弾いていたが、敵ごと drops 定義方式への移行に伴い
        // データ側で明示的に無効化する。
        DB::statement('
            UPDATE material_drops
            SET is_active = 0
            WHERE is_active = 1
              AND drop_first_clear_only = 0
              AND material_id IN (SELECT id FROM materials WHERE city_id IS NOT NULL)
              AND enemy_id IN (SELECT id FROM enemies WHERE area_id = 1 AND is_boss = 0)
        ');
    }

    public function down(): void
    {
        DB::statement('
            UPDATE material_drops
            SET is_active = 1
            WHERE is_active = 0
              AND drop_first_clear_only = 0
              AND material_id IN (SELECT id FROM materials WHERE city_id IS NOT NULL)
              AND enemy_id IN (SELECT id FROM enemies WHERE area_id = 1 AND is_boss = 0)
        ');
    }
};
