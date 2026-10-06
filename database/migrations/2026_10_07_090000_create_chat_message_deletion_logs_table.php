<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('public_log_id')->unique();
            $table->string('type', 20);
            // 証跡は元ログやキャラクターの削除に連動させない。
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('sender_name');
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->unsignedBigInteger('receiver_user_id')->nullable();
            $table->string('receiver_name')->nullable();
            $table->text('message');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deleted_at');
            $table->index(['deleted_at', 'id']);
            $table->index(['character_id', 'deleted_at']);
            $table->index(['user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chat_message_deletion_logs') && DB::table('chat_message_deletion_logs')->exists()) {
            throw new RuntimeException('削除済みチャットの証跡があるため、履歴テーブルは削除できません。');
        }
        Schema::dropIfExists('chat_message_deletion_logs');
    }
};
