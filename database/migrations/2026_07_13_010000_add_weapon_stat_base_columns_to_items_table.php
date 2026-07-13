<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 武器STR/MAGの「倍率適用前の基準値」を保持する列を追加する。
     * WeaponStatRescaleSeeder が常にこの基準値から str_bonus/mag_bonus を再計算するため、
     * Seederを何度実行しても数値が重複して増えない（冪等）。
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->integer('str_bonus_base')->nullable()->after('str_bonus');
            $table->integer('mag_bonus_base')->nullable()->after('mag_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['str_bonus_base', 'mag_bonus_base']);
        });
    }
};
