<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'map_exploration_item_carries';
        $uniqueIndex = 'map_item_carries_char_reg_item_uq';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($uniqueIndex) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->foreignId('registration_id')->constrained('town_map_registrations')->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('carried_count')->default(0);
                $table->unsignedSmallInteger('used_count')->default(0);
                $table->timestamps();

                $table->unique(['character_id', 'registration_id', 'item_id'], $uniqueIndex);
            });

            return;
        }

        // MySQLではDDL途中のcreate tableが残るため、本番で一意キー作成だけ失敗した場合も復旧できる。
        if (! Schema::hasIndex($tableName, $uniqueIndex)) {
            Schema::table($tableName, function (Blueprint $table) use ($uniqueIndex) {
                $table->unique(['character_id', 'registration_id', 'item_id'], $uniqueIndex);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('map_exploration_item_carries');
    }
};
