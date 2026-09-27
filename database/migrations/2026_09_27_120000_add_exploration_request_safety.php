<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exploration_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->uuid('token');
            $table->string('request_hash', 64);
            $table->text('redirect_url');
            $table->longText('battle_data')->nullable();
            $table->timestamp('created_at')->index();
            $table->unique(['character_id', 'token']);
        });
        Schema::table('character_exploration_states', function (Blueprint $table) {
            $table->uuid('dungeon_lord_token')->nullable();
        });
    }

    public function down(): void
    {
        // 再送防止記録を失うため、プレイヤー利用後はコードだけを戻す。
        if (DB::table('exploration_requests')->exists()) {
            throw new RuntimeException('探索の実行記録が存在するため削除できません。');
        }
        Schema::table('character_exploration_states', fn (Blueprint $table) => $table->dropColumn('dungeon_lord_token'));
        Schema::dropIfExists('exploration_requests');
    }
};
