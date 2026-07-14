<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('battle_logs') || Schema::hasColumn('battle_logs', 'job_exp_gained')) {
            return;
        }

        Schema::table('battle_logs', function (Blueprint $table) {
            $table->unsignedInteger('job_exp_gained')->default(0)->after('exp_gained');
            $table->index(['job_exp_gained', 'created_at'], 'battle_logs_job_exp_created_at_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('battle_logs') || !Schema::hasColumn('battle_logs', 'job_exp_gained')) {
            return;
        }

        Schema::table('battle_logs', function (Blueprint $table) {
            $table->dropIndex('battle_logs_job_exp_created_at_idx');
            $table->dropColumn('job_exp_gained');
        });
    }
};
