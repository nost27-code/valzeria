<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            '魔王軍歩兵の武器',
            '黒騎士将の剣',
            '奈落の階段剣',
        ];

        $characterItemIds = DB::table('character_items')
            ->join('items', 'items.id', '=', 'character_items.item_id')
            ->whereIn('items.name', $names)
            ->pluck('character_items.id');

        if ($characterItemIds->isNotEmpty()) {
            DB::table('character_items')
                ->whereIn('id', $characterItemIds)
                ->delete();
        }
    }

    public function down(): void
    {
        // Corrective cleanup for remapped inventory rows. No rollback.
    }
};
