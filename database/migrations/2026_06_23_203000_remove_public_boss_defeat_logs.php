<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('public_logs')
            || !Schema::hasColumn('public_logs', 'message')
        ) {
            return;
        }

        DB::table('public_logs')
            ->where('message', 'like', '%【討伐】%主を討伐しました！%')
            ->delete();
    }

    public function down(): void
    {
        // Deleted public timeline noise is not restored on rollback.
    }
};
