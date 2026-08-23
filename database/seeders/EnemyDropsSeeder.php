<?php

namespace Database\Seeders;

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Seeder;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\Material;
use App\Models\MaterialDrop;
use Illuminate\Support\Facades\Schema;

class EnemyDropsSeeder extends Seeder
{
    private const EQUIPMENT_FRAGMENT_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const EQUIPMENT_FRAGMENT_NAME = '装備の欠片';
    private const FINE_EQUIPMENT_FRAGMENT_CODE = 'MAT_FINE_EQUIPMENT_FRAGMENT';
    private const FINE_EQUIPMENT_FRAGMENT_NAME = '上質な装備の欠片';
    private const STRONG_EQUIPMENT_FRAGMENT_CODE = 'MAT_STRONG_EQUIPMENT_FRAGMENT';
    private const STRONG_EQUIPMENT_FRAGMENT_NAME = '強装備の欠片';
    private const LEGACY_COMMON_FRAGMENT_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];
    private const LEGACY_COMMON_FRAGMENT_NAMES = ['武器の欠片', '防具の欠片', '装飾の欠片'];
    private const DIRECT_DROP_DISABLED_ENHANCE_MATERIAL_CODES = [
        'MAT_ENHANCE_STONE',
        '5008',
        'ACC0008',
        'MAT_ENHANCE_HIGH_STONE',
        '5009',
        'ACC0009',
        'MAT_REFINING_CORE_LOW_A',
        'MAT_REFINING_CORE_LOW_B',
        'MAT_REFINING_CORE_LOW',
        'MAT_REFINING_CORE_PART_A',
        'MAT_REFINING_CORE_PART_B',
        'MAT_REFINING_CORE_PART_C',
        'MAT_REFINING_CORE',
    ];
    private const DIRECT_DROP_DISABLED_ENHANCE_MATERIAL_NAMES = [
        '強化石',
        '守護石',
        '装飾強化石',
        '調律石',
        '高純度強化石',
        '高純度守護石',
        '高純度装飾強化石',
        '高純度調律石',
        '織成核殻',
        '晶糸核芯',
        '粗精錬核',
        '覇王黒晶',
        '蒼炉魔晶',
        '星樹氷晶',
        '精錬核',
    ];
    private const REMOVED_UNUSED_MATERIAL_CODES = ['CITY_08_MATERIAL', 'WEV0029', 'WEV0030'];
    private const REMOVED_UNUSED_MATERIAL_NAMES = ['瘴気の骨片'];
    private const STALE_BRANCH_PATH_CODES = [
        'MAT_BR_WPN_HOLY_PATH',
        'MAT_BR_WPN_DARK_PATH',
        'MAT_BR_WPN_GALE_PATH',
        'MAT_BR_ARM_HEAVY_PATH',
        'MAT_BR_ARM_ARCANE_PATH',
        'MAT_BR_ARM_LIGHT_PATH',
        'MAT_BR_ARM_TRAVELER_PATH',
    ];
    private const BRANCH_PATH_OBTAIN_METHODS = [
        'MAT_BR_WPN_HOLY_PATH' => '素材交換所、または王都アークレアの最深層のレア報酬枠で入手します。',
        'MAT_BR_WPN_GALE_PATH' => '素材交換所、または精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_WPN_DARK_PATH' => '素材交換所、または鍛冶街グランベルグ周辺の目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => '素材交換所、または鍛冶街グランベルグ周辺の目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => '素材交換所、または精霊の森エルフィアの目安戦力1,631〜2,411帯表層探索のレア報酬枠で入手します。',
    ];
    private const SUPPLEMENTAL_COMMON_MATERIAL_DROPS = [
        'MAT_BREW_HERB' => [
            ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 15],
            ['area_id' => 1, 'enemy_name' => '草むらウルフ', 'drop_rate' => 15],
            ['area_id' => 1, 'enemy_name' => '草原コウモリ', 'drop_rate' => 15],
        ],
        'MAT_COMMON_BEAST_FANG' => [
            ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 25],
            ['area_id' => 1, 'enemy_name' => '草むらウルフ', 'drop_rate' => 45],
            ['area_id' => 4, 'enemy_name' => '丘ウルフ', 'drop_rate' => 45],
            ['area_id' => 4, 'enemy_name' => '銀毛ウルフ', 'drop_rate' => 45],
            ['area_id' => 4, 'enemy_name' => '群れの長', 'drop_rate' => 35],
            ['area_id' => 15, 'enemy_name' => '若葉ウルフ', 'drop_rate' => 35],
            ['area_id' => 16, 'enemy_name' => '妖精森ウルフ', 'drop_rate' => 35],
            ['area_id' => 29, 'enemy_name' => '白狼', 'drop_rate' => 35],
            ['area_id' => 30, 'enemy_name' => '氷牙ウルフ', 'drop_rate' => 35],
            ['area_id' => 32, 'enemy_name' => '白銀ウルフ', 'drop_rate' => 35],
            ['area_id' => 34, 'enemy_name' => '竜牙兵', 'drop_rate' => 35],
        ],
        'MAT_REGION_ARKREA_RAW' => [
            ['area_id' => 1, 'enemy_name' => 'スライム', 'drop_rate' => 20],
            ['area_id' => 1, 'enemy_name' => '野うさぎ', 'drop_rate' => 20],
            ['area_id' => 1, 'enemy_name' => '見習い盗賊', 'drop_rate' => 20],
            ['area_id' => 2, 'enemy_name' => 'ゴブリン', 'drop_rate' => 25],
            ['area_id' => 2, 'enemy_name' => 'ゴブリン弓兵', 'drop_rate' => 20],
            ['area_id' => 2, 'enemy_name' => 'ゴブリン隊長', 'drop_rate' => 20],
        ],
        'MAT_COMMON_DARK_CRYSTAL' => [
            ['area_id' => 2, 'enemy_name' => '小鬼シャーマン', 'drop_rate' => 15],
            ['area_id' => 3, 'enemy_name' => 'スケルトン見習い', 'drop_rate' => 15],
            ['area_id' => 5, 'enemy_name' => 'ゾンビ', 'drop_rate' => 20],
            ['area_id' => 5, 'enemy_name' => '呪いガラス', 'drop_rate' => 22],
        ],
        'MAT_COMMON_MONSTER_CORE' => [
            ['area_id' => 2, 'enemy_name' => '小鬼シャーマン', 'drop_rate' => 15],
            ['area_id' => 6, 'enemy_name' => 'いたずらピクシー', 'drop_rate' => 12],
            ['area_id' => 6, 'enemy_name' => '泉の番人', 'drop_rate' => 15],
        ],
        'MAT_COMMON_MONSTER_FRAGMENT' => [
            ['area_id' => 2, 'enemy_name' => '森スライム', 'drop_rate' => 10],
            ['area_id' => 3, 'enemy_name' => '古洞ワーム', 'drop_rate' => 15],
            ['area_id' => 3, 'enemy_name' => 'スケルトン見習い', 'drop_rate' => 15],
        ],
        'MAT_REGION_WORLD_TREE_LEAF' => [
            ['area_id' => 15, 'enemy_name' => '若葉ツリースピリット', 'drop_rate' => 20],
            ['area_id' => 16, 'enemy_name' => '妖精森ツリースピリット', 'drop_rate' => 20],
            ['area_id' => 17, 'enemy_name' => '絡み草', 'drop_rate' => 20],
            ['area_id' => 18, 'enemy_name' => '中層世界樹精', 'drop_rate' => 20],
            ['area_id' => 19, 'enemy_name' => '上層世界樹精', 'drop_rate' => 20],
            ['area_id' => 20, 'enemy_name' => '精霊封印守', 'drop_rate' => 15],
            ['area_id' => 21, 'enemy_name' => '月光星草スライム', 'drop_rate' => 20],
        ],
        'MAT_COMMON_FAIRY_DUST' => [
            ['area_id' => 6,  'enemy_name' => 'いたずらピクシー', 'drop_rate' => 20],
            ['area_id' => 6,  'enemy_name' => '泉の番人',         'drop_rate' => 15],
            ['area_id' => 15, 'enemy_name' => '若葉フェアリー',   'drop_rate' => 18],
            ['area_id' => 16, 'enemy_name' => '森フェアリー',     'drop_rate' => 22],
            ['area_id' => 18, 'enemy_name' => '中層風妖精',       'drop_rate' => 15],
            ['area_id' => 19, 'enemy_name' => '上層風妖精',       'drop_rate' => 18],
            ['area_id' => 21, 'enemy_name' => '月光ピクシー',     'drop_rate' => 18],
        ],
        'MAT_COMMON_FEATHER' => [
            ['area_id' => 15, 'enemy_name' => '若葉フェアリー', 'drop_rate' => 12],
            ['area_id' => 16, 'enemy_name' => '森フェアリー', 'drop_rate' => 15],
            ['area_id' => 18, 'enemy_name' => '中層風妖精', 'drop_rate' => 18],
            ['area_id' => 18, 'enemy_name' => '中層ハーピー', 'drop_rate' => 28],
            ['area_id' => 19, 'enemy_name' => '上層風妖精', 'drop_rate' => 22],
            ['area_id' => 19, 'enemy_name' => '上層ハーピー', 'drop_rate' => 35],
            ['area_id' => 21, 'enemy_name' => '月光ピクシー', 'drop_rate' => 15],
            ['area_id' => 21, 'enemy_name' => '月光光蝶', 'drop_rate' => 28],
            ['area_id' => 23, 'enemy_name' => '闇コウモリ', 'drop_rate' => 28],
            ['area_id' => 30, 'enemy_name' => '吹雪ハーピー', 'drop_rate' => 28],
            ['area_id' => 46, 'enemy_name' => '月光ピクシー', 'drop_rate' => 18],
        ],
        'MAT_REGION_BLACK_IRON_PART' => [
            ['area_id' => 22, 'enemy_name' => '鉄殻虫', 'drop_rate' => 25],
            ['area_id' => 22, 'enemy_name' => '鉄鉱ゴーレム', 'drop_rate' => 20],
            ['area_id' => 22, 'enemy_name' => '鉱山トロル', 'drop_rate' => 15],
        ],
        'MAT_COMMON_OLD_BADGE' => [
            ['area_id' => 1, 'enemy_name' => '草原コウモリ', 'drop_rate' => 18],
            ['area_id' => 1, 'enemy_name' => '見習い盗賊', 'drop_rate' => 20],
        ],
    ];

    public function run(): void
    {
        $path = base_path('docs/drop.csv');
        if (!file_exists($path)) {
            $this->command?->warn('docs/drop.csv が見つからないため、マスタCSV由来の敵ドロップ更新をスキップしました。補助素材ドロップのみ更新します。');
            $supplementalDrops = $this->applySupplementalCommonMaterialDrops();
            $this->applyEnhanceMaterialDrops();
            $this->applyGenericFallbackDrops();
            $this->refreshBranchPathObtainMethods();
            $this->deactivateGrasslandCityMaterialDrops();
            $this->command?->info("補助素材ドロップを {$supplementalDrops} 件更新しました。");
            return;
        }

        $rows = $this->readTsv($path);
        if (count($rows) < 2) {
            $this->command?->warn('docs/drop.csv に有効なデータがありません。補助素材ドロップのみ更新します。');
            $supplementalDrops = $this->applySupplementalCommonMaterialDrops();
            $this->applyEnhanceMaterialDrops();
            $this->applyGenericFallbackDrops();
            $this->refreshBranchPathObtainMethods();
            $this->deactivateGrasslandCityMaterialDrops();
            $this->command?->info("補助素材ドロップを {$supplementalDrops} 件更新しました。");
            return;
        }

        $header = array_shift($rows);
        $itemDropsUpdated = 0;
        $materialsUpdated = 0;

        // docs/drop.csv is the single source of truth. Disable every old
        // drop first, then re-enable only the rows present in the current master.
        EnemyDrop::query()->update(['is_active' => false]);
        MaterialDrop::query()->update(['is_active' => false]);
        Material::query()->update([
            'source_enemy_id' => null,
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
        ]);

        foreach ($rows as $row) {
            $data = $this->combineRow($header, $row);
            $enemy = $this->findEnemy($data);

            if (!$enemy) {
                $this->command?->warn('敵が見つかりません: ' . ($data['敵の名前'] ?? ''));
                continue;
            }

            $dropType = trim($data['ドロップ区分'] ?? '');
            $itemName = trim($data['レアドロップ品'] ?? '');
            if ($itemName === '' || $dropType === 'ドロップ無し') {
                continue;
            }

            $itemTypeRaw = trim($data['レアドロップ種別'] ?? '');
            if ($itemTypeRaw === '素材' || str_contains($dropType, '素材')) {
                $this->upsertMaterialDrop($data, $enemy);
                $materialsUpdated++;
                continue;
            }

            $effect = $this->effectText($data);
            $bonuses = $this->parseBonuses($effect);
            $price = (int) str_replace(',', '', trim($data['売却価格案'] ?? '0'));
            $itemType = $this->normalizeItemType($itemTypeRaw);

            if (in_array($itemType, ['weapon', 'armor'], true) || $this->isLegacyEquippedMark($itemTypeRaw, $itemName)) {
                continue;
            }

            $item = $this->upsertDropItem($itemName, $itemTypeRaw, $data, $price, $bonuses);

            EnemyDrop::updateOrCreate(
                [
                    'enemy_id' => $enemy->id,
                    'item_id' => $item->id,
                ],
                [
                    'drop_rate' => (float) ($data['ドロップ率(%)'] ?? 0),
                    'min_character_level' => 1,
                    'max_character_level' => null,
                    'is_active' => true,
                ]
            );

            $itemDropsUpdated++;
        }

        $supplementalDrops = $this->applySupplementalCommonMaterialDrops();
        $this->applyEnhanceMaterialDrops();
        $this->applyGenericFallbackDrops();
        $this->refreshBranchPathObtainMethods();
        $this->deactivateGrasslandCityMaterialDrops();

        $this->command?->info("敵レアドロップを {$itemDropsUpdated} 件、素材ドロップを {$materialsUpdated} 件、補助素材ドロップを {$supplementalDrops} 件更新しました。");
    }

    private function readTsv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(fn ($line) => explode("\t", $line), $lines);
    }

    private function combineRow(array $header, array $row): array
    {
        $data = [];
        foreach ($header as $index => $name) {
            $data[$name] = $row[$index] ?? '';
        }
        return $data;
    }

    private function findEnemy(array $data): ?Enemy
    {
        $enemyId = (int) ($data['enemy_id'] ?? 0);
        if ($enemyId > 0) {
            $enemy = Enemy::find($enemyId);
            if ($enemy) {
                return $enemy;
            }
        }

        $name = trim($data['敵の名前'] ?? '');
        $areaId = (int) ($data['dungeon_id'] ?? 0);
        if ($name !== '' && $areaId > 0) {
            $enemy = Enemy::where('name', $name)->where('area_id', $areaId)->first();
            if ($enemy) {
                return $enemy;
            }
        }

        return $name !== '' ? Enemy::where('name', $name)->first() : null;
    }

    private function normalizeItemType(string $raw): string
    {
        return match (true) {
            str_contains($raw, '武器') => 'weapon',
            str_contains($raw, '防具') => 'armor',
            default => 'accessory',
        };
    }

    private function normalizeRarity(string $raw): string
    {
        return match (true) {
            str_contains($raw, '神印') => 'mythic',
            str_contains($raw, '王印') => 'legend',
            str_contains($raw, '刻印') => 'epic',
            str_contains($raw, '印') => 'rare',
            default => 'rare',
        };
    }

    private function upsertDropItem(string $itemName, string $itemTypeRaw, array $data, int $price, array $bonuses): Item
    {
        $itemType = $this->normalizeItemType($itemTypeRaw);

        if (in_array($itemType, ['weapon', 'armor'], true)) {
            $item = Item::where('name', $itemName)->where('type', $itemType)->first();
            if ($item) {
                return $item;
            }
        }

        return Item::updateOrCreate(
            ['name' => $itemName],
            array_merge([
                'type' => $itemType,
                'description' => $this->buildDescription($data),
                'rarity' => $this->normalizeRarity($itemTypeRaw),
                'price' => $price,
                'required_level' => max(1, (int) ($data['Lv'] ?? 1)),
                'is_shop_item' => false,
                'is_active' => true,
                'sub_type' => $itemTypeRaw !== '' ? $itemTypeRaw : null,
                'element' => trim($data['属性'] ?? '') ?: null,
            ], $bonuses)
        );
    }

    private function isLegacyEquippedMark(string $itemTypeRaw, string $itemName): bool
    {
        return str_contains($itemTypeRaw, '印')
            || str_ends_with($itemName, 'の印')
            || str_ends_with($itemName, 'の刻印')
            || str_ends_with($itemName, 'の王印')
            || str_ends_with($itemName, 'の神印');
    }

    private function parseBonuses(string $effect): array
    {
        $bonuses = [
            'hp_bonus' => 0,
            'mp_bonus' => 0,
            'str_bonus' => 0,
            'def_bonus' => 0,
            'agi_bonus' => 0,
            'mag_bonus' => 0,
            'spr_bonus' => 0,
            'luk_bonus' => 0,
        ];

        foreach (preg_split('/[、,]/u', $effect) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/HP\+(-?\d+)/u', $part, $m)) {
                $bonuses['hp_bonus'] += (int) $m[1];
            }
            if (preg_match('/MP\+(-?\d+)/u', $part, $m)) {
                $bonuses['mp_bonus'] += (int) $m[1];
            }
            if (preg_match('/(?:ATK|STR)\+(-?\d+)/u', $part, $m)) {
                $bonuses['str_bonus'] += (int) $m[1];
            }
            if (preg_match('/DEF\+(-?\d+)/u', $part, $m)) {
                $bonuses['def_bonus'] += (int) $m[1];
            }
            if (preg_match('/(?:AGI|SPD)\+(-?\d+)/u', $part, $m)) {
                $bonuses['agi_bonus'] += (int) $m[1];
            }
            if (preg_match('/MAG\+(-?\d+)/u', $part, $m)) {
                $bonuses['mag_bonus'] += (int) $m[1];
            }
            if (preg_match('/SPR\+(-?\d+)/u', $part, $m)) {
                $bonuses['spr_bonus'] += (int) $m[1];
            }
            if (preg_match('/LUK\+(-?\d+)/u', $part, $m)) {
                $bonuses['luk_bonus'] += (int) $m[1];
            }
            if (preg_match('/全能力\+(-?\d+)/u', $part, $m)) {
                $value = (int) $m[1];
                foreach (['str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus'] as $key) {
                    $bonuses[$key] += $value;
                }
            }
            if (preg_match('/主能力\+(-?\d+)/u', $part, $m)) {
                $value = (int) $m[1];
                foreach (['str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus'] as $key) {
                    $bonuses[$key] += $value;
                }
            }
        }

        return $bonuses;
    }

    private function effectText(array $data): string
    {
        return trim($data['効果案'] ?? $data['効果案＿改善後カイゼンゴ'] ?? '');
    }

    private function upsertMaterialDrop(array $data, Enemy $enemy): void
    {
        $materialCode = trim($data['素材ID'] ?? '');
        if ($materialCode === '') {
            $sourceId = trim($data['元drop_id'] ?? '');
            if (str_starts_with($sourceId, 'MAT')) {
                $materialCode = $sourceId;
            }
        }

        if ($materialCode === '') {
            return;
        }

        $materialName = trim($data['レアドロップ品'] ?? '');
        if (in_array($materialCode, self::REMOVED_UNUSED_MATERIAL_CODES, true)
            || in_array($materialName, self::REMOVED_UNUSED_MATERIAL_NAMES, true)) {
            return;
        }
        if ($this->isDirectDropDisabledEnhanceMaterial($materialCode, $materialName)) {
            return;
        }

        $equipmentFragment = $this->normalizeEquipmentFragment($materialCode, $materialName);
        if ($equipmentFragment) {
            return;
        }

        $consolidatedPayload = null;
        if (!$equipmentFragment && NormalDropMaterialConsolidator::isLegacyNormalCode($materialCode)) {
            $materialCode = NormalDropMaterialConsolidator::targetCodeFor(
                $materialName,
                (int) ($data['街ID'] ?? 0) ?: null
            );
            $consolidatedPayload = NormalDropMaterialConsolidator::payload($materialCode);
            $materialName = $consolidatedPayload['name'];
        }

        $isTradableRaw = strtoupper(trim($data['市場取引可'] ?? ''));
        $isTradable = in_array($isTradableRaw, ['YES', 'TRUE', '1'], true);
        $dropType = trim($data['ドロップ区分'] ?? '');
        $firstClearOnly = strtoupper(trim($data['初回確定入手'] ?? '')) === 'YES'
            || str_contains($dropType, '初回');
        $dropRate = (float) ($data['ドロップ率(%)'] ?? 0);

        if ($this->isRemovedUnusedBossGuaranteedDrop($data, $enemy, $materialName, $dropRate, $firstClearOnly)) {
            return;
        }

        $materialValues = [
            'name' => $materialName,
            'category' => trim($data['素材カテゴリ'] ?? '素材'),
            'rarity' => trim($data['素材レア度'] ?? 'N'),
            'element' => trim($data['属性'] ?? '') ?: null,
            'main_use' => trim($data['主用途'] ?? '') ?: null,
            'npc_sale_price' => (int) str_replace(',', '', trim($data['NPC売却価格'] ?? $data['売却価格案'] ?? '0')),
            'is_tradable' => $isTradable,
            'city_id' => (int) ($data['街ID'] ?? 0) ?: null,
            'dungeon_id' => (int) ($data['dungeon_id'] ?? 0) ?: null,
            'source_enemy_id' => null,
            'drop_rate' => 0,
            'drop_first_clear_only' => false,
            'drop_timing' => null,
        ];

        if ($consolidatedPayload) {
            $materialValues = array_merge($materialValues, $consolidatedPayload, [
                'city_id' => $this->cityIdForConsolidatedMaterial($materialCode),
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
            ]);
        }

        $material = Material::updateOrCreate(
            ['material_code' => $materialCode],
            $materialValues
        );

        $existingDrop = MaterialDrop::where('enemy_id', $enemy->id)
            ->where('material_id', $material->id)
            ->first();

        MaterialDrop::updateOrCreate(
            [
                'enemy_id' => $enemy->id,
                'material_id' => $material->id,
            ],
            [
                'drop_rate' => $existingDrop ? max((float) $existingDrop->drop_rate, $dropRate) : $dropRate,
                'drop_first_clear_only' => $existingDrop ? ((bool) $existingDrop->drop_first_clear_only || $firstClearOnly) : $firstClearOnly,
                'drop_timing' => $existingDrop?->drop_timing ?: (trim($data['ドロップタイミング'] ?? '') ?: null),
                'is_active' => true,
            ]
        );
    }

    private function isRemovedUnusedBossGuaranteedDrop(
        array $data,
        Enemy $enemy,
        string $materialName,
        float $dropRate,
        bool $firstClearOnly
    ): bool
    {
        if (! (bool) $enemy->is_boss || ! $firstClearOnly || $dropRate < 100) {
            return false;
        }

        $category = trim($data['素材カテゴリ'] ?? '');
        $mainUse = trim($data['主用途'] ?? '');
        $originalName = trim($data['レアドロップ品'] ?? '');

        return str_contains($materialName, '進化証')
            || str_contains($originalName, '刻印')
            || str_contains($originalName, '印')
            || str_contains($category, '討伐証素材')
            || str_contains($category, 'ボス特異素材')
            || str_contains($category, '進化解放キー')
            || str_contains($mainUse, '進化解放');
    }

    private function applySupplementalCommonMaterialDrops(): int
    {
        $updated = 0;

        foreach (self::SUPPLEMENTAL_COMMON_MATERIAL_DROPS as $materialCode => $drops) {
            $material = $this->ensureCommonDropMaterial($materialCode);
            if (!$material) {
                continue;
            }

            foreach ($drops as $drop) {
                $enemy = Enemy::where('area_id', (int) $drop['area_id'])
                    ->where('name', (string) $drop['enemy_name'])
                    ->where('is_boss', false)
                    ->first();

                if (!$enemy) {
                    continue;
                }

                $existingDrop = MaterialDrop::where('enemy_id', $enemy->id)
                    ->where('material_id', $material->id)
                    ->first();
                $dropRate = (float) $drop['drop_rate'];

                MaterialDrop::updateOrCreate(
                    [
                        'enemy_id' => $enemy->id,
                        'material_id' => $material->id,
                    ],
                    [
                        'drop_rate' => $existingDrop ? max((float) $existingDrop->drop_rate, $dropRate) : $dropRate,
                        'drop_first_clear_only' => false,
                        'drop_timing' => null,
                        'is_active' => true,
                    ]
                );

                $updated++;
            }
        }

        return $updated;
    }

    private function ensureCommonDropMaterial(string $materialCode): ?Material
    {
        if (!array_key_exists($materialCode, NormalDropMaterialConsolidator::definitions())) {
            return Material::where('material_code', $materialCode)->first();
        }

        return Material::updateOrCreate(
            ['material_code' => $materialCode],
            array_merge(NormalDropMaterialConsolidator::payload($materialCode), [
                'city_id' => $this->cityIdForConsolidatedMaterial($materialCode),
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
            ])
        );
    }

    private function cityIdForConsolidatedMaterial(string $materialCode): ?int
    {
        return match ($materialCode) {
            'MAT_REGION_ARKREA_RAW' => 1,
            'MAT_REGION_TIDAL_PIECE' => 2,
            'MAT_REGION_WORLD_TREE_LEAF' => 3,
            'MAT_REGION_BLACK_IRON_PART' => 4,
            'MAT_REGION_ICE_CRYSTAL' => 5,
            'MAT_REGION_ANCIENT_SAND' => 6,
            'MAT_REGION_MAGIC_CRYSTAL' => 7,
            'MAT_REGION_ABYSS_FRAGMENT' => 8,
            'MAT_REGION_HEAVEN_FEATHER' => 9,
            default => null,
        };
    }

    private function refreshBranchPathObtainMethods(): void
    {
        $payload = [
            'obtain_method' => '分岐進化用の導石。素材交換所や、対応地域の深層・最深層のレア報酬枠で入手します。',
        ];

        foreach (['city_id', 'dungeon_id', 'source_area_id', 'source_enemy_id'] as $column) {
            if (Schema::hasColumn('materials', $column)) {
                $payload[$column] = null;
            }
        }

        if (Schema::hasColumn('materials', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        Material::query()
            ->whereIn('material_code', self::STALE_BRANCH_PATH_CODES)
            ->where('material_type', 'branch_evolution')
            ->update($payload);

        Material::query()
            ->where('material_code', 'MAT_BR_WPN_HOLY_PATH')
            ->where('material_type', 'branch_evolution')
            ->update(array_merge($payload, [
                'obtain_method' => '素材交換所、または王都アークレアの最深層のレア報酬枠で入手します。',
            ]));

        foreach (self::BRANCH_PATH_OBTAIN_METHODS as $code => $method) {
            Material::query()
                ->where('material_code', $code)
                ->where('material_type', 'branch_evolution')
                ->update(array_merge($payload, [
                    'obtain_method' => $method,
                ]));
        }
    }

    private function applyEnhanceMaterialDrops(): void
    {
        // 都市ティア → [素材コード[], ウェイト]
        // 強化系の欠片は各通常ダンジョンの約半数の敵から低確率で落とす。
        $tierConfig = [
            1 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            2 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            3 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            4 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            5 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            6 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            7 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            8 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            9 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
            10 => [['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], 1],
        ];

        $allCodes = array_unique(array_merge(...array_map(fn ($c) => $c[0], $tierConfig)));
        $materials = Material::whereIn('material_code', $allCodes)->get()->keyBy('material_code');
        $materialIds = $materials->pluck('id')->all();
        if (empty($materialIds)) {
            return;
        }

        MaterialDrop::whereIn('material_id', $materialIds)
            ->where('drop_first_clear_only', false)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        // ルートエリアを除く全エリアの非ボス敵をエリアごとにグループ化
        $grouped = Enemy::where('is_boss', false)
            ->whereHas('area', fn ($q) => $q->where('is_route_area', false)->whereNotNull('city_id'))
            ->with('area')
            ->orderBy('area_id')
            ->orderBy('id')
            ->get()
            ->groupBy('area_id');

        foreach ($grouped as $areaEnemies) {
            $cityId = (int) ($areaEnemies->first()->area?->city_id ?? 0);
            if (!isset($tierConfig[$cityId])) {
                continue;
            }

            [$codes, $weight] = $tierConfig[$cityId];

            // 偶数インデックスの敵（約半数）に強化石系素材を登録
            $areaEnemies->values()->each(function (Enemy $enemy, int $idx) use ($codes, $weight, $materials) {
                if ($idx % 2 !== 0) {
                    return;
                }
                foreach ($codes as $code) {
                    $material = $materials->get($code);
                    if (!$material) {
                        continue;
                    }
                    MaterialDrop::updateOrCreate(
                        ['enemy_id' => $enemy->id, 'material_id' => $material->id],
                        ['drop_rate' => $weight, 'drop_first_clear_only' => false, 'drop_timing' => null, 'is_active' => true]
                    );
                }
            });
        }
    }

    private function applyGenericFallbackDrops(): void
    {
        // 旧システムではkind='generic'（50%）で全敵に魔物の欠片・古びた徽章がフォールバックドロップしていた。
        // 新システムでは敵ごとのdrops定義に統合するため、未登録の敵に汎用素材を一律登録する。
        // 既にSUPPLEMENTAL_COMMON_MATERIAL_DROPSなどで登録済みの場合はそのウェイトを尊重し変更しない。
        $genericCodes = [
            'MAT_COMMON_MONSTER_FRAGMENT' => 20,
            'MAT_COMMON_OLD_BADGE'        => 15,
        ];

        $materials = Material::whereIn('material_code', array_keys($genericCodes))
            ->get()
            ->keyBy('material_code');

        Enemy::where('is_boss', false)
            ->get()
            ->each(function (Enemy $enemy) use ($genericCodes, $materials) {
                foreach ($genericCodes as $code => $weight) {
                    $material = $materials->get($code);
                    if (!$material) {
                        continue;
                    }

                    $alreadyActive = MaterialDrop::where('enemy_id', $enemy->id)
                        ->where('material_id', $material->id)
                        ->where('is_active', true)
                        ->exists();

                    if ($alreadyActive) {
                        continue;
                    }

                    MaterialDrop::updateOrCreate(
                        ['enemy_id' => $enemy->id, 'material_id' => $material->id],
                        ['drop_rate' => $weight, 'drop_first_clear_only' => false, 'drop_timing' => null, 'is_active' => true]
                    );
                }
            });
    }

    private function deactivateGrasslandCityMaterialDrops(): void
    {
        // はじまりの草原（area_id=1）の敵には都市依存素材を落とさせない。
        // 敵ごと drops 定義方式では filterMaterialPool が存在しないため、データ側で制御する。
        MaterialDrop::query()
            ->where('drop_first_clear_only', false)
            ->where('is_active', true)
            ->whereIn('material_id', Material::query()->whereNotNull('city_id')->select('id'))
            ->whereIn('enemy_id', Enemy::query()->where('area_id', 1)->where('is_boss', false)->select('id'))
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function isLegacyCommonFragment(string $materialCode, string $materialName): bool
    {
        return in_array($materialCode, self::LEGACY_COMMON_FRAGMENT_CODES, true)
            || in_array($materialName, self::LEGACY_COMMON_FRAGMENT_NAMES, true);
    }

    private function isDirectDropDisabledEnhanceMaterial(string $materialCode, string $materialName): bool
    {
        return in_array($materialCode, self::DIRECT_DROP_DISABLED_ENHANCE_MATERIAL_CODES, true)
            || in_array($materialName, self::DIRECT_DROP_DISABLED_ENHANCE_MATERIAL_NAMES, true);
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

    private function buildDescription(array $data): string
    {
        $enemyName = trim($data['敵の名前'] ?? '');
        $itemType = trim($data['レアドロップ種別'] ?? 'ドロップ品');
        $dungeonName = trim($data['ダンジョン名'] ?? '');

        return trim("{$enemyName}からまれに手に入る{$itemType}。{$dungeonName}の魔力を帯びている。");
    }
}
