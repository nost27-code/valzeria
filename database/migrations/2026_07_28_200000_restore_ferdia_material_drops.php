<?php

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FerdiaRegionSeeder::class)->seedMaterialDrops();
    }

    public function down(): void
    {
        // 本来存在すべきマスタ行の復旧なので、ロールバック時も自動削除しない。
    }
};
