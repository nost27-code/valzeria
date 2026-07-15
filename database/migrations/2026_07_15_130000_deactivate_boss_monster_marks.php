<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monster_marks') || ! Schema::hasTable('enemies')) {
            return;
        }

        DB::table('monster_marks')
            ->whereIn('enemy_id', DB::table('enemies')->where('is_boss', true)->select('id'))
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // 既存の非アクティブ印を誤って再有効化しないため、ロールバック時も状態を維持する。
    }
};
