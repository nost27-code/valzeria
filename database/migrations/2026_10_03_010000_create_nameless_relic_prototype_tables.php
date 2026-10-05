<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_nameless_equipments', function (Blueprint $table) {
            $table->unsignedBigInteger('growth_exp')->default(0);
            $table->unsignedInteger('revision')->default(0);
        });
        Schema::create('player_relics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('effect_key', 64);
            $table->unsignedTinyInteger('rank');
            $table->boolean('is_locked')->default(false);
            $table->foreignId('nameless_equipment_id')->nullable()->constrained('player_nameless_equipments')->nullOnDelete();
            $table->unsignedTinyInteger('slot_number')->nullable();
            $table->timestamps();
            $table->index(['character_id', 'effect_key', 'rank'], 'player_relic_owner_effect');
            $table->unique(['nameless_equipment_id', 'slot_number'], 'player_relic_socket_unique');
        });
        Schema::create('nameless_workshop_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_uuid');
            $table->string('action', 32);
            $table->string('payload_hash', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['character_id', 'request_uuid'], 'nameless_operation_request_unique');
        });
        Schema::create('nameless_ruin_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('zone_key', 32);
            $table->unsignedInteger('unlocked_depth')->default(1);
            $table->timestamps();
            $table->unique(['character_id', 'zone_key'], 'nameless_ruin_character_zone_unique');
        });
    }

    public function down(): void
    {
        // 遺物・消費履歴を含むため、運用開始後のdownはデータ消失を伴う。
        Schema::dropIfExists('nameless_ruin_progress');
        Schema::dropIfExists('nameless_workshop_operations');
        Schema::dropIfExists('player_relics');
        Schema::table('player_nameless_equipments', function (Blueprint $table) {
            $table->dropColumn(['growth_exp', 'revision']);
        });
    }
};
