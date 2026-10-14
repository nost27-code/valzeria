<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FerdiaStoryMaterialDropSeeder extends Seeder
{
    public const AREA_IDS = [1025, 1026, 1027, 1028, 1029];

    // Final per-defeat percentages; do not apply the main-route seed multipliers.
    private const MATERIAL_DROPS = [
        1025 => [
            'スライム' => ['MAT_FERDIA_GUARDTREE_RESIN' => 33.00],
            '獣' => ['MAT_FERDIA_BLUE_LIFE_LEAF' => 32.11],
            '人型' => ['MAT_BR_ARM_ARCANE_ANCIENT' => 0.86],
            '巨人' => ['MAT_FERDIA_LIFEROOT' => 8.03, 'MAT_BR_ARM_ARCANE_ANCIENT' => 0.68],
            '飛行' => ['MAT_FERDIA_LIFEROOT' => 12.04],
        ],
        1026 => [
            'スライム' => ['MAT_FERDIA_CLEARSTREAM_DROP' => 33.00],
            '獣' => ['MAT_FERDIA_HEMOSTATIC_MOSS' => 32.11],
            '人型' => ['MAT_BR_WPN_HOLY_ANCIENT' => 0.86],
            '巨人' => ['MAT_BR_WPN_HOLY_ANCIENT' => 0.68],
            '水棲' => ['MAT_FERDIA_CLEARSTREAM_DROP' => 33.00],
        ],
        1027 => [
            'スライム' => ['MAT_FERDIA_BLUE_LIFE_LEAF' => 32.11],
            '獣' => ['MAT_FERDIA_BLUE_LIFE_LEAF' => 33.00],
            '人型' => ['MAT_BR_ARM_HEAVY_ANCIENT' => 0.86],
            '巨人' => ['MAT_BR_ARM_HEAVY_ANCIENT' => 0.68],
            '機械' => ['MAT_FERDIA_HEMOSTATIC_MOSS' => 24.09],
        ],
        1028 => [
            'スライム' => ['MAT_FERDIA_CLEARSTREAM_DROP' => 33.00],
            '獣' => ['MAT_FERDIA_DETOX_GALL' => 20.07],
            '人型' => ['MAT_BR_ARM_TRAVELER_ANCIENT' => 0.86],
            '巨人' => ['MAT_BR_ARM_TRAVELER_ANCIENT' => 0.68],
            '飛行' => ['MAT_FERDIA_CLEARSTREAM_DROP' => 33.00, 'MAT_FERDIA_DETOX_GALL' => 12.04],
        ],
        1029 => [
            'スライム' => ['MAT_FERDIA_HEMOSTATIC_MOSS' => 32.11],
            '獣' => ['MAT_FERDIA_BLUE_LIFE_LEAF' => 24.09],
            '人型' => ['MAT_BR_WPN_DARK_ANCIENT' => 0.86],
            '巨人' => ['MAT_FERDIA_LIFEROOT' => 10.04, 'MAT_BR_WPN_DARK_ANCIENT' => 0.68],
            '悪魔' => ['MAT_FERDIA_LIFEROOT' => 12.04],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $materialCodes = collect(self::MATERIAL_DROPS)
                ->flatMap(fn (array $types): array => array_merge(...array_values(array_map('array_keys', $types))))
                ->unique()
                ->values();
            $materialIds = DB::table('materials')
                ->whereIn('material_code', $materialCodes)
                ->pluck('id', 'material_code');
            $missing = $materialCodes->diff($materialIds->keys());
            if ($missing->isNotEmpty()) {
                throw new RuntimeException('フェルディア物語分岐の素材マスタが不足しています: '.$missing->implode(', '));
            }

            $enemies = DB::table('enemies')
                ->whereIn('area_id', self::AREA_IDS)
                ->where('is_boss', false)
                ->get();
            $now = now();
            $rows = [];
            foreach (self::MATERIAL_DROPS as $areaId => $types) {
                foreach ($types as $type => $drops) {
                    $targets = $enemies->where('area_id', $areaId)->where('type_name', $type);
                    if ($targets->count() !== 1) {
                        throw new RuntimeException("フェルディア物語分岐の敵マスタを確認してください: area #{$areaId}, {$type}");
                    }
                    foreach ($drops as $code => $rate) {
                        $rows[] = [
                            'enemy_id' => $targets->first()->id,
                            'material_id' => $materialIds[$code],
                            'drop_rate' => $rate,
                            'drop_first_clear_only' => false,
                            'drop_timing' => null,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            DB::table('material_drops')->upsert(
                $rows,
                ['enemy_id', 'material_id'],
                ['drop_rate', 'drop_first_clear_only', 'drop_timing', 'is_active', 'updated_at'],
            );
        });
    }
}
