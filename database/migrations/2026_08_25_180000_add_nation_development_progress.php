<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOW_EXP_MATERIAL_CODES = [
        'WEV0023', 'WEV0024', 'WEV0025', 'WEV0026', 'WEV0027', 'WEV0028', 'MAT_REGION_MAGIC_CRYSTAL', 'WEV0030', 'WEV0031', 'WEV0032',
        '5025', '5027', '5029', '5031', '5033', '5035', '5037', '5039', '5041', '5043',
    ];

    private const HIGH_EXP_MATERIAL_CODES = [
        'WEV0033', 'WEV0035', 'WEV0037', 'WEV0039', 'WEV0041', 'WEV0043', 'WEV0045', 'WEV0047', 'WEV0049', 'WEV0051',
        '5026', '5028', '5030', '5032', '5034', '5036', '5038', '5040', '5042', '5044',
    ];

    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->unsignedBigInteger('development_exp')->default(0)->after('treasury_points');
        });

        Schema::table('nation_material_conversion_rates', function (Blueprint $table): void {
            $table->unsignedInteger('development_exp_per_unit')->default(1)->after('points_per_unit');
        });

        Schema::table('nation_resource_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('development_exp_delta')->default(0)->after('points_delta');
            $table->index(
                ['nation_id', 'transaction_type', 'character_id', 'development_exp_delta'],
                'nation_resource_contribution_covering',
            );
        });

        $this->setDevelopmentRate(self::LOW_EXP_MATERIAL_CODES, 1);
        $this->setDevelopmentRate(self::HIGH_EXP_MATERIAL_CODES, 2);
    }

    public function down(): void
    {
        $hasDevelopmentLedger = Schema::hasColumn('nation_resource_transactions', 'development_exp_delta')
            && DB::table('nation_resource_transactions')->where('development_exp_delta', '<>', 0)->exists();
        $hasDevelopmentCache = Schema::hasColumn('nations', 'development_exp')
            && DB::table('nations')->where('development_exp', '<>', 0)->exists();
        if ($hasDevelopmentLedger || $hasDevelopmentCache) {
            throw new RuntimeException('国家発展EXPが記録済みのためrollbackできません。機能flagをOFFにし、forward migrationで復旧してください。');
        }

        Schema::table('nation_resource_transactions', function (Blueprint $table): void {
            $table->dropIndex('nation_resource_contribution_covering');
            $table->dropColumn('development_exp_delta');
        });

        Schema::table('nation_material_conversion_rates', function (Blueprint $table): void {
            $table->dropColumn('development_exp_per_unit');
        });

        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn('development_exp');
        });
    }

    /** @param list<string> $materialCodes */
    private function setDevelopmentRate(array $materialCodes, int $experience): void
    {
        $materialIds = DB::table('materials')
            ->whereIn('material_code', $materialCodes)
            ->pluck('id');

        DB::table('nation_material_conversion_rates')
            ->whereIn('material_id', $materialIds)
            ->update(['development_exp_per_unit' => $experience]);
    }
};
