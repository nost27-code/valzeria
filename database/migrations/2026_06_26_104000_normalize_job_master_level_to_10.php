<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_classes') && Schema::hasColumn('job_classes', 'max_job_level')) {
            DB::table('job_classes')->update(['max_job_level' => 10]);
        }

        if (!Schema::hasTable('character_jobs') || !Schema::hasColumn('character_jobs', 'job_level')) {
            return;
        }

        DB::table('character_jobs')
            ->where('job_level', '>', 10)
            ->update(['job_level' => 10]);

        if (Schema::hasColumn('character_jobs', 'is_mastered')) {
            $query = DB::table('character_jobs')->where('job_level', '>=', 10);

            if (Schema::hasColumn('character_jobs', 'mastered_at')) {
                $query->update([
                    'is_mastered' => true,
                    'mastered_at' => DB::raw('COALESCE(mastered_at, CURRENT_TIMESTAMP)'),
                ]);
            } else {
                $query->update(['is_mastered' => true]);
            }
        }
    }

    public function down(): void
    {
        // No rollback: restoring previous per-rank caps would make normalized player histories ambiguous.
    }
};
