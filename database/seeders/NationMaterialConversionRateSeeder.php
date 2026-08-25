<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\NationMaterialConversionRate;
use Illuminate\Database\Seeder;

class NationMaterialConversionRateSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['points' => 1, 'development_exp' => 1, 'codes' => ['WEV0023', 'WEV0024', 'WEV0025', 'WEV0026', 'WEV0027', 'WEV0028', 'MAT_REGION_MAGIC_CRYSTAL', 'WEV0030', 'WEV0031', 'WEV0032', '5025', '5027', '5029', '5031', '5033', '5035', '5037', '5039', '5041', '5043']],
            ['points' => 3, 'development_exp' => 2, 'codes' => ['WEV0033', 'WEV0035', 'WEV0037', 'WEV0039', 'WEV0041', 'WEV0043', 'WEV0045', 'WEV0047', 'WEV0049', 'WEV0051', '5026', '5028', '5030', '5032', '5034', '5036', '5038', '5040', '5042', '5044']],
        ];
        $found = 0;
        foreach ($groups as $group) {
            foreach (Material::whereIn('material_code', $group['codes'])->get() as $material) {
                NationMaterialConversionRate::updateOrCreate(
                    ['material_id' => $material->id],
                    [
                        'points_per_unit' => $group['points'],
                        'development_exp_per_unit' => $group['development_exp'],
                        'is_active' => true,
                    ],
                );
                $found++;
            }
        }
        throw_unless($found === 40, \RuntimeException::class, "国家資材対象40種のうち{$found}種しか見つかりませんでした。");
    }
}
