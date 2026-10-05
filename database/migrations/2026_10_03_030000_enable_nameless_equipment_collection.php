<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_nameless_equipments', function (Blueprint $table) {
            // 既存個体の銘・強化・遺物は保持し、初期配布として扱う。
            $table->string('acquisition_source', 16)->default('starter');
            $table->boolean('is_locked')->default(false);
            $table->index(['character_id', 'kind'], 'nameless_equipment_owner_kind');
        });
        Schema::table('player_nameless_equipments', function (Blueprint $table) {
            $table->dropUnique(['character_id', 'kind']);
        });
        Schema::create('nameless_equipment_discoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('equipment_type', 32);
            $table->timestamps();
            $table->unique(['character_id', 'equipment_type'], 'nameless_discovery_owner_type');
        });
    }

    public function down(): void
    {
        // 複数個体と発見記録を捨てて旧構造に戻す操作は許可しない。
        if (DB::table('nameless_equipment_discoveries')->exists()
            || DB::table('player_nameless_equipments')->where('acquisition_source', 'ruin')->exists()) {
            throw new RuntimeException('収集済みの武具・発見記録があります。データを保持する移行が必要です。');
        }
        Schema::dropIfExists('nameless_equipment_discoveries');
        Schema::table('player_nameless_equipments', function (Blueprint $table) {
            $table->unique(['character_id', 'kind']);
            $table->dropIndex('nameless_equipment_owner_kind');
            $table->dropColumn(['acquisition_source', 'is_locked']);
        });
    }
};
