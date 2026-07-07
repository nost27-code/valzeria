<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_IDS = [95, 96, 97, 98, 99];

    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->whereIn('id', self::JOB_IDS)
            ->update([
                'is_active' => false,
                'is_hidden' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->whereIn('id', self::JOB_IDS)
            ->update([
                'is_active' => true,
                'is_hidden' => true,
                'updated_at' => now(),
            ]);
    }
};
