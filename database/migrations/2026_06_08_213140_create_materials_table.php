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
        if (!Schema::hasTable('materials')) {
            Schema::create('materials', function (Blueprint $table) {
                $table->id();
                $table->string('material_code')->unique()->comment('MAT0001等');
                $table->string('name');
                $table->string('category')->comment('素材カテゴリ');
                $table->string('rarity')->comment('レア度(N, N+, R, SR, SSR等)');
                $table->string('element')->nullable()->comment('属性');
                $table->string('main_use')->nullable()->comment('主用途');
                $table->integer('npc_sale_price')->default(0)->comment('NPC売却価格');
                $table->boolean('is_tradable')->default(true)->comment('市場取引可');
                $table->unsignedBigInteger('city_id')->nullable();
                $table->unsignedBigInteger('dungeon_id')->nullable();
                $table->unsignedBigInteger('source_enemy_id')->nullable()->comment('主な入手敵のID');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
