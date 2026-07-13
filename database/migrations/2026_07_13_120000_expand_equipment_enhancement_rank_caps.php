<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'max_enhance')) {
            return;
        }

        $now = now();
        foreach (config('equipment_enhancement.rank_caps', []) as $rank => $cap) {
            DB::table('items')
                ->whereIn('type', ['weapon', 'armor', 'accessory'])
                ->where(function ($query) use ($rank) {
                    $query->where('weapon_rank', $rank)
                        ->orWhere('armor_rank', $rank)
                        ->orWhere('accessory_rank', $rank);
                })
                ->update([
                    'max_enhance' => $cap,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', 'max_enhance')) {
            return;
        }

        DB::table('items')
            ->whereIn('type', ['weapon', 'armor', 'accessory'])
            ->where('max_enhance', '>', 0)
            ->update([
                'max_enhance' => 5,
                'updated_at' => now(),
            ]);
    }
};
