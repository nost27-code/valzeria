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
            || !Schema::hasColumn('public_logs', 'id')
            || !Schema::hasColumn('public_logs', 'message')
        ) {
            return;
        }

        DB::table('public_logs')
            ->where(function ($query) {
                $query->where('message', 'like', '%【伝説】%')
                    ->orWhere('message', 'like', '%Legendランク装備%')
                    ->orWhere('message', 'like', '%LEGENDランク装備%')
                    ->orWhere('message', 'like', '%legendランク装備%');
            })
            ->orderBy('id')
            ->chunkById(100, function ($logs) {
                foreach ($logs as $log) {
                    $message = str_replace(
                        ['【伝説】', 'Legendランク装備', 'LEGENDランク装備', 'legendランク装備'],
                        ['【獲得】', 'EPICランク装備', 'EPICランク装備', 'EPICランク装備'],
                        (string) $log->message
                    );

                    DB::table('public_logs')
                        ->where('id', $log->id)
                        ->update(['message' => $message]);
                }
            });
    }

    public function down(): void
    {
        // 正規化した公開ログ本文は戻しません。
    }
};
