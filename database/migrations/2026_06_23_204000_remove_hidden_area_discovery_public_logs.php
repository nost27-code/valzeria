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
            ->where(function ($query) {
                $query->where('message', 'like', '%【隠しダンジョン発見】%')
                    ->orWhere('message', 'like', '%【裏ダンジョン発見】%');
            })
            ->delete();
    }

    public function down(): void
    {
        // 削除した公開ログは復元しません。
    }
};
