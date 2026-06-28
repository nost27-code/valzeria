<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enemy_drops') || !Schema::hasTable('items')) {
            return;
        }

        DB::table('enemy_drops')
            ->join('items', 'items.id', '=', 'enemy_drops.item_id')
            ->where(function ($query) {
                $query->where('items.external_item_id', 'like', 'DROP_WPN_%')
                    ->orWhere('items.external_item_id', 'like', 'DROP_ARM_%')
                    ->orWhere('items.external_item_id', 'like', 'DROP_ACC_%');
            })
            ->where('enemy_drops.drop_rate', '>', 0)
            ->update([
                'enemy_drops.drop_rate' => DB::raw('ROUND(enemy_drops.drop_rate * 0.5, 4)'),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('enemy_drops') || !Schema::hasTable('items')) {
            return;
        }

        DB::table('enemy_drops')
            ->join('items', 'items.id', '=', 'enemy_drops.item_id')
            ->where(function ($query) {
                $query->where('items.external_item_id', 'like', 'DROP_WPN_%')
                    ->orWhere('items.external_item_id', 'like', 'DROP_ARM_%')
                    ->orWhere('items.external_item_id', 'like', 'DROP_ACC_%');
            })
            ->where('enemy_drops.drop_rate', '>', 0)
            ->update([
                'enemy_drops.drop_rate' => DB::raw('ROUND(enemy_drops.drop_rate * 2, 4)'),
            ]);
    }
};
