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
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            
            // アカウント連携用
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // 基本情報
            $table->string('name', 50);
            $table->string('gender', 20)->default('未設定');
            $table->string('icon_path')->nullable(); // アバター画像パス
            
            // レベル・経験値・通貨
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('exp')->default(0);
            $table->unsignedBigInteger('money')->default(100);
            
            // 職業
            $table->unsignedBigInteger('current_job_id')->nullable();
            
            // 基礎ステータス（装備抜きの素の能力）
            $table->unsignedInteger('hp_base')->default(100);
            $table->unsignedInteger('attack_base')->default(10);
            $table->unsignedInteger('defense_base')->default(8);
            $table->unsignedInteger('speed_base')->default(8);
            $table->unsignedInteger('magic_base')->default(8);
            $table->unsignedInteger('luck_base')->default(5);
            
            // 戦闘統計
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedBigInteger('total_score')->default(0); // 総合スコア（ランキング用）
            
            // 行動・AP関連（初期実装のAPの代わりに最終戦闘時刻）
            $table->dateTime('last_battle_at')->nullable();
            
            $table->timestamps();

            // --- パフォーマンス最適化のためのインデックス設定 ---
            $table->index('user_id');
            $table->index('level');
            $table->index('total_score'); // 総合ランキング用
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
