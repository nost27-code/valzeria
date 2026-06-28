<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'material_storage_limit')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE characters MODIFY material_storage_limit INT UNSIGNED NOT NULL DEFAULT 500');
        }

        DB::table('characters')
            ->where('material_storage_limit', '<', 500)
            ->update(['material_storage_limit' => 500]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'material_storage_limit')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE characters MODIFY material_storage_limit INT UNSIGNED NOT NULL DEFAULT 300');
        }
    }
};
