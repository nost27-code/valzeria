<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'profile_frame_theme')) {
                $table->string('profile_frame_theme', 40)->default('standard')->after('profile_ranch_background');
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'profile_frame_theme')) {
                $table->dropColumn('profile_frame_theme');
            }
        });
    }
};
