<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('character_items', 'is_stored')) {
            Schema::table('character_items', function (Blueprint $table) {
                $table->boolean('is_stored')->default(false)->after('is_equipped');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('character_items', 'is_stored')) {
            Schema::table('character_items', function (Blueprint $table) {
                $table->dropColumn('is_stored');
            });
        }
    }
};
