<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('weapon_enhancement_recipes')) {
            return;
        }

        DB::table('weapon_enhancement_recipes')
            ->where('materials', 'like', '%武具の欠片%')
            ->update([
                'materials' => DB::raw("REPLACE(materials, " . DB::getPdo()->quote('武具の欠片') . ', ' . DB::getPdo()->quote('装備の欠片') . ')'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Name cleanup only; keep the unified equipment fragment wording.
    }
};
