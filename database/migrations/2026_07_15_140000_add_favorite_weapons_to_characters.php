<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters') && !Schema::hasColumn('characters', 'profile_favorite_weapon_ids')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->json('profile_favorite_weapon_ids')->nullable()->after('profile_valmon_case');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('characters') && Schema::hasColumn('characters', 'profile_favorite_weapon_ids')) {
            Schema::table('characters', function (Blueprint $table) {
                $table->dropColumn('profile_favorite_weapon_ids');
            });
        }
    }
};
