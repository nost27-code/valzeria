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
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('current_hp')->default(100)->after('current_job_id');
            $table->string('title')->nullable()->after('level');
            $table->string('pvp_rank', 10)->default('F')->after('title');
            $table->unsignedInteger('reincarnation_count')->default(0)->after('pvp_rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['current_hp', 'title', 'pvp_rank', 'reincarnation_count']);
        });
    }
};
