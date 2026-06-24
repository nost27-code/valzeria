<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('public_logs', function (Blueprint $table) {
            // 個人宛チャット（手紙）用の受信者ID
            $table->unsignedBigInteger('receiver_id')->nullable()->after('character_id');
            // チャット取得の高速化のためインデックスを貼る（外部キーは必須ではないが検索効率のため）
            $table->index('receiver_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public_logs', function (Blueprint $table) {
            $table->dropIndex(['receiver_id']);
            $table->dropColumn('receiver_id');
        });
    }
};
