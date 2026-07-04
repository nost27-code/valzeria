<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        DB::table('job_classes')
            ->whereIn('id', [44, 45, 46, 47, 48])
            ->update([
                'is_hidden' => true,
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Pending master-data visibility is controlled by future release tasks.
    }
};
