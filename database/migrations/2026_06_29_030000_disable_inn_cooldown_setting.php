<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('game_settings')
            ->where('setting_key', 'cooldown.inn_seconds')
            ->update([
                'value' => '0',
                'description' => '宿屋でHP/SPを全回復した後の探索待機は廃止済みです。',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('game_settings')
            ->where('setting_key', 'cooldown.inn_seconds')
            ->update([
                'value' => '40',
                'description' => '宿屋でHP/SPを全回復した後、次の探索まで待機する秒数です。0で待機なし。',
                'updated_at' => now(),
            ]);
    }
};
