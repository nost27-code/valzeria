<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('public_logs') || !Schema::hasColumn('public_logs', 'message')) {
            return;
        }

        DB::table('public_logs')
            ->where('type', 'drop')
            ->where('message', 'like', '%nekoさんがAランク装備「聖騎士の槍」を手に入れました！%')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 削除した公開ログは復元しません。
    }
};
