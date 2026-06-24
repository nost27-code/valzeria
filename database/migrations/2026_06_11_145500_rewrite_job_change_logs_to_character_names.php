<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $logs = DB::table('public_logs')
            ->join('characters', 'characters.id', '=', 'public_logs.character_id')
            ->where('public_logs.type', 'job_change')
            ->whereNotNull('public_logs.character_id')
            ->select('public_logs.id', 'public_logs.message', 'characters.name as character_name')
            ->get();

        foreach ($logs as $log) {
            $message = (string) $log->message;
            $characterName = (string) $log->character_name;
            $rewritten = preg_replace('/^【転職】.*?さん/u', "【転職】{$characterName}さん", $message, 1);

            if ($rewritten && $rewritten !== $message) {
                DB::table('public_logs')
                    ->where('id', $log->id)
                    ->update([
                        'message' => $rewritten,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // ログ本文の過去補正は不可逆のため戻しません。
    }
};
