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
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        DB::table('npc_master')
            ->where('npc_id', 8)
            ->update([
                'talk_text' => '「やられると、敵に手持ちのゴールドを10%も奪われてしまいます。大金はあらかじめ銀行に預けるか、冒険者協会に寄付をしておくと安心ですよ。」',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
