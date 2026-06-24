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
        Schema::table('job_classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('bonus_hp')->default(0)->after('luck_rate');
            $table->unsignedSmallInteger('bonus_mp')->default(0)->after('bonus_hp');
            $table->unsignedSmallInteger('bonus_str')->default(0)->after('bonus_mp');
            $table->unsignedSmallInteger('bonus_def')->default(0)->after('bonus_str');
            $table->unsignedSmallInteger('bonus_mag')->default(0)->after('bonus_def');
            $table->unsignedSmallInteger('bonus_spr')->default(0)->after('bonus_mag');
            $table->unsignedSmallInteger('bonus_spd')->default(0)->after('bonus_spr');
            $table->unsignedSmallInteger('bonus_luk')->default(0)->after('bonus_spd');
            
            $table->unsignedSmallInteger('bonus_gold_rate')->default(0)->after('bonus_luk');
            $table->unsignedSmallInteger('bonus_drop_rate')->default(0)->after('bonus_gold_rate');
            $table->unsignedSmallInteger('bonus_critical_rate')->default(0)->after('bonus_drop_rate');
            
            $table->unsignedTinyInteger('special_skill_rate')->default(0)->after('bonus_critical_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            $table->dropColumn([
                'bonus_hp',
                'bonus_mp',
                'bonus_str',
                'bonus_def',
                'bonus_mag',
                'bonus_spr',
                'bonus_spd',
                'bonus_luk',
                'bonus_gold_rate',
                'bonus_drop_rate',
                'bonus_critical_rate',
                'special_skill_rate'
            ]);
        });
    }
};
