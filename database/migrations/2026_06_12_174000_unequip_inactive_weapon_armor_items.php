<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('character_items')
            ->join('items', 'items.id', '=', 'character_items.item_id')
            ->where('character_items.is_equipped', true)
            ->whereIn('items.type', ['weapon', 'armor'])
            ->where('items.is_active', false)
            ->update([
                'character_items.is_equipped' => false,
                'character_items.equipped_slot' => null,
                'character_items.updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 装備解除は安全側へのデータ補正のため、rollbackでは戻しません。
    }
};
