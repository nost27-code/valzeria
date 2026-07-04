<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_classes') || ! Schema::hasColumn('job_classes', 'rank')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE job_classes MODIFY rank VARCHAR(32) NOT NULL DEFAULT 'normal'");
        }
    }

    public function down(): void
    {
        // Keep the widened rank column; high-rank master data depends on non-legacy rank keys.
    }
};
