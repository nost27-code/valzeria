<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Material;

class MaterialSeeder extends Seeder
{
    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const EQUIPMENT_FRAGMENT_NAME = '装備の欠片';
    private const FINE_EQUIPMENT_FRAGMENT_CODE = 'MAT_FINE_EQUIPMENT_FRAGMENT';
    private const FINE_EQUIPMENT_FRAGMENT_NAME = '上質な装備の欠片';
    private const STRONG_EQUIPMENT_FRAGMENT_CODE = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
    private const STRONG_EQUIPMENT_FRAGMENT_NAME = '強装備の欠片';
    private const LEGACY_COMMON_FRAGMENT_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];
    private const LEGACY_COMMON_FRAGMENT_NAMES = ['武器の欠片', '防具の欠片', '装飾の欠片'];
    private const BREWING_MATERIALS = [
        'MAT_BREW_BEAST_FANG' => ['name' => '獣牙素材', 'rarity' => 'N'],
        'MAT_BREW_TOXIN' => ['name' => '毒素材', 'rarity' => 'N'],
        'MAT_BREW_HERB' => ['name' => '薬草の若葉', 'rarity' => 'N'],
        'MAT_BREW_MAGIC_POWDER' => ['name' => '魔粉素材', 'rarity' => 'N+'],
        'MAT_BREW_LOW_MONSTER' => ['name' => '低級魔物素材', 'rarity' => 'N'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFile = base_path('docs/sozai.md');
        if (!file_exists($csvFile)) {
            $this->command?->error('sozai.md not found.');
            return;
        }

        $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = true;
        $validMaterialCodes = [];

        DB::beginTransaction();
        try {
            foreach ($lines as $line) {
                if ($header) {
                    $header = false;
                    continue;
                }

                $data = explode("\t", $line);
                if (count($data) < 13) continue;

                $materialCode = trim($data[0]);
                if (empty($materialCode)) continue;

                $materialName = trim($data[1]);
                $equipmentFragment = $this->normalizeEquipmentFragment($materialCode, $materialName);
                if ($equipmentFragment) {
                    [$materialCode, $materialName, $fragmentTier] = $equipmentFragment;
                }

                $sourceEnemyName = trim($data[13] ?? '');
                $sourceEnemyId = null;

                $cityId = trim($data[8]) !== '' ? (int)trim($data[8]) : null;
                if ($cityId === 0) $cityId = null;
                $dungeonId = trim($data[10]) !== '' ? (int)trim($data[10]) : null;
                if ($dungeonId === 0) $dungeonId = null;
                if ($equipmentFragment) {
                    $cityId = null;
                    $dungeonId = null;
                }

                if ($sourceEnemyName !== '') {
                    $query = \App\Models\Enemy::where('name', $sourceEnemyName);
                    if ($dungeonId) {
                        $query->where('area_id', $dungeonId);
                    }
                    $enemy = $query->first();

                    if (!$enemy) {
                        $query = \App\Models\Enemy::where('name', 'LIKE', "%{$sourceEnemyName}%");
                        if ($dungeonId) {
                            $query->where('area_id', $dungeonId);
                        }
                        $enemy = $query->first();
                    }
                    
                    if ($enemy) {
                        $sourceEnemyId = $enemy->id;
                    }
                }

                $isTradable = strtoupper(trim($data[7])) === 'TRUE' || trim($data[7]) === '1';

                Material::updateOrCreate(
                    ['material_code' => $materialCode],
                    [
                        'name' => $materialName,
                        'category' => $equipmentFragment ? '装備共通素材' : trim($data[2]),
                        'rarity' => $equipmentFragment ? ['N', 'R', 'SR'][$fragmentTier - 1] : trim($data[3]),
                        'element' => trim($data[4]),
                        'main_use' => $equipmentFragment ? '装備の進化・強化' : trim($data[5]),
                        'npc_sale_price' => (int)trim($data[6]),
                        'is_tradable' => $isTradable,
                        'city_id' => $cityId,
                        'dungeon_id' => $dungeonId,
                        'source_enemy_id' => $sourceEnemyId,
                    ]
                );
                $validMaterialCodes[] = $materialCode;
            }

            $this->ensureUnifiedEquipmentFragments();
            $this->ensureBrewingMaterials();

            DB::commit();
            $this->command?->info('Materials seeded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command?->error('Error seeding materials: ' . $e->getMessage());
        }
    }

    private function isLegacyCommonFragment(string $materialCode, string $materialName): bool
    {
        return in_array($materialCode, self::LEGACY_COMMON_FRAGMENT_CODES, true)
            || in_array($materialName, self::LEGACY_COMMON_FRAGMENT_NAMES, true);
    }

    private function normalizeEquipmentFragment(string $materialCode, string $materialName): ?array
    {
        if ($this->isLegacyCommonFragment($materialCode, $materialName)) {
            return [self::EQUIPMENT_FRAGMENT_CODE, self::EQUIPMENT_FRAGMENT_NAME, 1];
        }

        if (in_array($materialCode, ['WEV0002', '5002', 'ACC0002'], true)
            || in_array($materialName, ['武器の結晶', '防具の結晶', '装飾の結晶'], true)) {
            return [self::FINE_EQUIPMENT_FRAGMENT_CODE, self::FINE_EQUIPMENT_FRAGMENT_NAME, 2];
        }

        if (in_array($materialCode, ['WEV0003', '5003', 'ACC0003'], true)
            || in_array($materialName, ['武器の核', '防具の核', '装飾の核'], true)
            || $this->isDomainEquipmentFragment($materialCode, $materialName)) {
            return [self::STRONG_EQUIPMENT_FRAGMENT_CODE, self::STRONG_EQUIPMENT_FRAGMENT_NAME, 3];
        }

        return null;
    }

    private function isDomainEquipmentFragment(string $materialCode, string $materialName): bool
    {
        if (preg_match('/^WEV00(0[8-9]|1[0-9]|2[0-2])$/', $materialCode)) {
            return true;
        }

        if (preg_match('/^(501[0-9]|502[0-4]|ACC00[1-3][0-9])$/', $materialCode)) {
            return true;
        }

        foreach (['斬撃', '刺突', '打撃', '射撃', '魔導', '軽装', '重装', '魔布', '聖布', '闘具', '腕力', '守護', '魔力', '祈祷', '疾風', '幸運', '生命', '精神', '均衡', '冒険'] as $prefix) {
            if (str_starts_with($materialName, $prefix . 'の')) {
                return true;
            }
        }

        return false;
    }

    private function ensureUnifiedEquipmentFragments(): void
    {
        foreach ([
            [self::EQUIPMENT_FRAGMENT_CODE, self::EQUIPMENT_FRAGMENT_NAME, 1, 'N'],
            [self::FINE_EQUIPMENT_FRAGMENT_CODE, self::FINE_EQUIPMENT_FRAGMENT_NAME, 2, 'R'],
            [self::STRONG_EQUIPMENT_FRAGMENT_CODE, self::STRONG_EQUIPMENT_FRAGMENT_NAME, 3, 'SR'],
        ] as [$code, $name, $tier, $rarity]) {
            $payload = [
                'name' => $name,
                'category' => '装備共通素材',
                'rarity' => $rarity,
                'element' => null,
                'main_use' => '装備の進化・強化',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
            ];

            if (Schema::hasColumn('materials', 'material_type')) {
                $payload['material_type'] = 'equipment_common';
            }
            if (Schema::hasColumn('materials', 'category_id')) {
                $payload['category_id'] = 'equipment_common';
            }
            if (Schema::hasColumn('materials', 'rank_tier')) {
                $payload['rank_tier'] = $tier;
            }
            if (Schema::hasColumn('materials', 'is_consumable')) {
                $payload['is_consumable'] = true;
            }
            if (Schema::hasColumn('materials', 'obtain_method')) {
                $payload['obtain_method'] = '探索・装備分解・素材交換所で入手。細分化された装備素材を統合した共通素材。';
            }

            Material::updateOrCreate(['material_code' => $code], $payload);
        }
    }

    private function ensureBrewingMaterials(): void
    {
        foreach (self::BREWING_MATERIALS as $code => $material) {
            $payload = [
                'name' => $material['name'],
                'category' => '調合素材',
                'rarity' => $material['rarity'],
                'element' => null,
                'main_use' => $code === 'MAT_BREW_HERB' ? '薬草の調合' : '回復アイテム調合',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
            ];

            if (Schema::hasColumn('materials', 'material_type')) {
                $payload['material_type'] = 'brewing';
            }
            if (Schema::hasColumn('materials', 'category_id')) {
                $payload['category_id'] = 'brewing';
            }
            if (Schema::hasColumn('materials', 'rank_tier')) {
                $payload['rank_tier'] = 1;
            }
            if (Schema::hasColumn('materials', 'is_consumable')) {
                $payload['is_consumable'] = true;
            }
            if (Schema::hasColumn('materials', 'obtain_method')) {
                $payload['obtain_method'] = $code === 'MAT_BREW_HERB'
                    ? 'はじまりの草原の自然系の敵から入手。'
                    : '素材交換所で敵が落とした部位素材を渡して入手。';
            }

            Material::updateOrCreate(['material_code' => $code], $payload);
        }
    }
}
