<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('items')
            ->whereIn('name', [
                '魔除けの護符',
                '旅人のお守り',
                '俊足の指輪',
                '力の腕輪',
                '魔力の首飾り',
            ])
            ->where('type', 'accessory')
            ->update([
                'unlock_city_id' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('items')
            ->whereIn('name', [
                '魔除けの護符',
                '旅人のお守り',
                '俊足の指輪',
                '力の腕輪',
                '魔力の首飾り',
            ])
            ->where('type', 'accessory')
            ->update([
                'unlock_city_id' => null,
                'updated_at' => now(),
            ]);
    }
};
