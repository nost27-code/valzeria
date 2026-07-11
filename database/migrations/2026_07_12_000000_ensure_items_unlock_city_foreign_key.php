<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items') || !Schema::hasTable('cities') || !Schema::hasColumn('items', 'unlock_city_id')) {
            return;
        }

        foreach (Schema::getForeignKeys('items') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === ['unlock_city_id']) {
                return;
            }
        }

        Schema::table('items', function (Blueprint $table) {
            $table->foreign('unlock_city_id')->references('id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Existing production databases may already own this historical constraint.
    }
};
