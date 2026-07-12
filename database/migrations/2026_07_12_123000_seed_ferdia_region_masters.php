<?php

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FerdiaRegionSeeder::class)->run();
    }

    public function down(): void
    {
        // 既存プレイヤーの探索進行を持ちうるため、マスタを自動削除しない。
    }
};
