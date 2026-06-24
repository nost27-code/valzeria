<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'profile_comment')) {
                $table->string('profile_comment', 160)->nullable()->after('home_display_mode');
            }

            if (!Schema::hasColumn('characters', 'profile_ranch_background')) {
                $table->string('profile_ranch_background', 120)->default('images/valmon/ranch_bg.webp')->after('profile_comment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'profile_ranch_background')) {
                $table->dropColumn('profile_ranch_background');
            }

            if (Schema::hasColumn('characters', 'profile_comment')) {
                $table->dropColumn('profile_comment');
            }
        });
    }
};
