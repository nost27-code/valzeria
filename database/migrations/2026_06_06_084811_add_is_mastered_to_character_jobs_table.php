<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('character_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('character_jobs', 'is_mastered')) {
                $table->boolean('is_mastered')->default(false)->after('job_exp');
            }
            if (!Schema::hasColumn('character_jobs', 'mastered_at')) {
                $table->timestamp('mastered_at')->nullable()->after('is_mastered');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('character_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('character_jobs', 'is_mastered')) {
                $table->dropColumn('is_mastered');
            }
            if (Schema::hasColumn('character_jobs', 'mastered_at')) {
                $table->dropColumn('mastered_at');
            }
        });
    }
};
