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
        // プレイヤー進行に参照されうるため、自動削除しない。
    }
};
