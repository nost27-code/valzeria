<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items') || ! Schema::hasColumn('items', 'affix_enabled')) {
            return;
        }

        DB::table('items')
            ->where('type', 'weapon')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('weapon_rank', 'EPIC')
                    ->orWhere('rarity', 'EPIC');
            })
            ->update(['affix_enabled' => true]);
    }

    public function down(): void
    {
        // EPIC weapon master records may be changed individually after this migration.
        // Do not reset those operator changes during a rollback.
    }
};
