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
        Schema::table('characters', function (Blueprint $table) {
            $table->float('hp_fraction')->default(0.0)->after('hp_base');
            $table->float('mp_fraction')->default(0.0)->after('mp_base');
            $table->float('attack_fraction')->default(0.0)->after('attack_base');
            $table->float('defense_fraction')->default(0.0)->after('defense_base');
            $table->float('magic_fraction')->default(0.0)->after('magic_base');
            $table->float('speed_fraction')->default(0.0)->after('speed_base');
            $table->float('luck_fraction')->default(0.0)->after('luck_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn([
                'hp_fraction',
                'mp_fraction',
                'attack_fraction',
                'defense_fraction',
                'magic_fraction',
                'speed_fraction',
                'luck_fraction'
            ]);
        });
    }
};
