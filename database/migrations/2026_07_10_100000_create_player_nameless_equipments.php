<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_nameless_equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['weapon', 'armor']);
            $table->string('custom_name', 32)->nullable();
            $table->string('equipment_type', 32);
            $table->unsignedTinyInteger('forge_level')->default(0);
            $table->unsignedSmallInteger('base_power')->default(5);
            $table->unsignedSmallInteger('power_per_level')->default(5);
            $table->boolean('is_equipped')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'kind']);
        });

        Schema::create('nameless_equipment_material_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('min_level');
            $table->unsignedTinyInteger('max_level');
            $table->string('weapon_main_material_code');
            $table->string('weapon_fine_material_code');
            $table->string('armor_main_material_code');
            $table->string('armor_fine_material_code');
            $table->timestamps();

            $table->unique('min_level');
        });

        $now = now();
        DB::table('nameless_equipment_material_tiers')->insert(collect([
            [1, 10, 'WEV0023', 'MAT_REGION_ARKREA_RAW', '5025', '5026'],
            [11, 20, 'WEV0024', 'MAT_REGION_TIDAL_PIECE', '5027', '5028'],
            [21, 30, 'WEV0025', 'MAT_COMMON_NATURAL_FRAGMENT', '5029', '5030'],
            [31, 40, 'WEV0026', 'MAT_REGION_BLACK_IRON_PART', '5031', '5032'],
            [41, 50, 'WEV0027', 'MAT_REGION_ICE_CRYSTAL', '5033', '5034'],
            [51, 60, 'WEV0028', 'MAT_REGION_ANCIENT_SAND', '5035', '5036'],
            [61, 70, 'MAT_REGION_MAGIC_CRYSTAL', 'WEV0045', '5037', '5038'],
            [71, 80, 'MAT_REGION_ABYSS_FRAGMENT', 'WEV0047', '5039', '5040'],
            [81, 90, 'WEV0031', 'WEV0005', '5041', '5042'],
            [91, 99, 'WEV0032', 'WEV0051', '5043', '5044'],
        ])->map(fn (array $tier) => [
            'min_level' => $tier[0], 'max_level' => $tier[1],
            'weapon_main_material_code' => $tier[2], 'weapon_fine_material_code' => $tier[3],
            'armor_main_material_code' => $tier[4], 'armor_fine_material_code' => $tier[5],
            'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('nameless_equipment_material_tiers');
        Schema::dropIfExists('player_nameless_equipments');
    }
};
