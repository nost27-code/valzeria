<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $rankMultipliers = [
        'G' => 1.00,
        'F' => 1.35,
        'E' => 1.82,
        'D' => 2.46,
        'C' => 3.32,
        'B' => 4.48,
        'A' => 6.05,
        'S' => 8.17,
        'SS' => 11.03,
        'SSS' => 14.89,
        'EPIC' => 20.11,
    ];

    public function up(): void
    {
        $this->ensureSchema();

        $now = now();
        $this->importFamilies($now);
        $this->importMaterials($now);
        $this->importAccessories($now);
        $this->importRecipes($now);
    }

    public function down(): void
    {
        DB::table('accessory_evolution_recipe_ingredients')->whereIn('recipe_id', function ($query) {
            $query->select('recipe_id')->from('accessory_evolution_recipes');
        })->delete();
        DB::table('accessory_evolution_recipes')->delete();
        DB::table('accessory_families')->delete();

        DB::table('items')
            ->where('type', 'accessory')
            ->whereNotNull('accessory_family_id')
            ->delete();

        DB::table('materials')
            ->where('material_type', 'accessory_evolution')
            ->delete();
    }

    private function ensureSchema(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'accessory_family_id')) {
                $table->string('accessory_family_id')->nullable()->after('armor_rank_multiplier');
            }
            if (!Schema::hasColumn('items', 'accessory_family_name')) {
                $table->string('accessory_family_name')->nullable()->after('accessory_family_id');
            }
            if (!Schema::hasColumn('items', 'accessory_category_id')) {
                $table->string('accessory_category_id')->nullable()->after('accessory_family_name');
            }
            if (!Schema::hasColumn('items', 'accessory_category_name')) {
                $table->string('accessory_category_name')->nullable()->after('accessory_category_id');
            }
            if (!Schema::hasColumn('items', 'accessory_rank')) {
                $table->string('accessory_rank')->nullable()->after('accessory_category_name');
            }
            if (!Schema::hasColumn('items', 'accessory_rank_sort')) {
                $table->unsignedSmallInteger('accessory_rank_sort')->default(0)->after('accessory_rank');
            }
            if (!Schema::hasColumn('items', 'accessory_rank_multiplier')) {
                $table->decimal('accessory_rank_multiplier', 10, 4)->default(1)->after('accessory_rank_sort');
            }
            if (!Schema::hasColumn('items', 'next_accessory_external_id')) {
                $table->string('next_accessory_external_id')->nullable()->after('next_armor_external_id');
            }
        });

        if (!Schema::hasTable('accessory_families')) {
            Schema::create('accessory_families', function (Blueprint $table) {
                $table->id();
                $table->string('accessory_family_id')->unique();
                $table->string('accessory_family_name');
                $table->string('accessory_category_id');
                $table->string('accessory_category_name');
                $table->integer('base_hp')->default(0);
                $table->integer('base_mp')->default(0);
                $table->integer('base_atk')->default(0);
                $table->integer('base_def')->default(0);
                $table->integer('base_mag')->default(0);
                $table->integer('base_spr')->default(0);
                $table->integer('base_spd')->default(0);
                $table->integer('base_luk')->default(0);
                $table->string('role_description')->nullable();
                $table->unsignedSmallInteger('display_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('accessory_evolution_recipes')) {
            Schema::create('accessory_evolution_recipes', function (Blueprint $table) {
                $table->id();
                $table->string('recipe_id')->unique();
                $table->string('from_accessory_id');
                $table->string('from_accessory_name');
                $table->string('to_accessory_id');
                $table->string('to_accessory_name');
                $table->string('from_rank');
                $table->string('to_rank');
                $table->unsignedSmallInteger('required_same_accessory_count');
                $table->unsignedInteger('unlock_city_id')->nullable();
                $table->boolean('requires_city7_boss_cleared')->default(false);
                $table->boolean('requires_hidden_dungeon_unlocked')->default(false);
                $table->boolean('requires_hidden_boss_cleared')->default(false);
                $table->boolean('requires_demon_king_cleared')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('accessory_evolution_recipe_ingredients')) {
            Schema::create('accessory_evolution_recipe_ingredients', function (Blueprint $table) {
                $table->id();
                $table->string('recipe_id')->index();
                $table->string('ingredient_type')->default('material');
                $table->string('material_code')->nullable();
                $table->string('material_name')->nullable();
                $table->unsignedInteger('required_quantity');
                $table->boolean('is_consumed')->default(true);
                $table->timestamps();
            });
        }
    }

    private function importFamilies($now): void
    {
        foreach ($this->families() as $index => $family) {
            DB::table('accessory_families')->updateOrInsert(
                ['accessory_family_id' => $family['id']],
                [
                    'accessory_family_name' => $family['name'],
                    'accessory_category_id' => $family['category_id'],
                    'accessory_category_name' => $family['category_name'],
                    'base_hp' => $family['base']['hp'] ?? 0,
                    'base_mp' => $family['base']['mp'] ?? 0,
                    'base_atk' => $family['base']['str'] ?? 0,
                    'base_def' => $family['base']['def'] ?? 0,
                    'base_mag' => $family['base']['mag'] ?? 0,
                    'base_spr' => $family['base']['spr'] ?? 0,
                    'base_spd' => $family['base']['agi'] ?? 0,
                    'base_luk' => $family['base']['luk'] ?? 0,
                    'role_description' => $family['role'],
                    'display_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function importMaterials($now): void
    {
        $materials = [
            ['ACC0001', '装飾の欠片', 'common', 'N'],
            ['ACC0002', '装飾の結晶', 'common', 'R'],
            ['ACC0003', '装飾の核', 'common', 'SR'],
            ['ACC0004', '古代装飾片', 'common', 'SSR'],
            ['ACC0005', '星屑の宝材', 'common', 'SSSR'],
            ['ACC0006', '秘境素材の欠片', 'common', 'EPIC'],
            ['ACC0007', '装飾強化石の欠片', 'enhance', 'N'],
            ['ACC0008', '装飾強化石', 'enhance', 'R'],
            ['ACC0009', '高純度装飾強化石', 'enhance', 'SR'],
        ];

        $offset = 10;
        foreach ($this->families() as $family) {
            $materials[] = ['ACC' . str_pad((string) $offset, 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の欠片', $family['category_id'], 'N+'];
            $materials[] = ['ACC' . str_pad((string) ($offset + 1), 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の結晶', $family['category_id'], 'R+'];
            $materials[] = ['ACC' . str_pad((string) ($offset + 2), 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の核', $family['category_id'], 'SR+'];
            $offset += 3;
        }

        foreach ($materials as [$code, $name, $category, $rarity]) {
            $payload = [
                'name' => $name,
                'rarity' => $rarity,
                'category' => 'accessory_evolution',
                'material_type' => 'accessory_evolution',
                'category_id' => $category,
                'rank_tier' => $this->materialRankTier($rarity),
                'is_consumable' => true,
                'obtain_method' => '装飾品分解・敵ドロップ',
                'is_tradable' => false,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('materials', 'description')) {
                $payload['description'] = '装飾品の進化・強化・分解で使用する素材。';
            }

            if (!DB::table('materials')->where('material_code', $code)->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                $payload
            );
        }
    }

    private function importAccessories($now): void
    {
        $rankKeys = array_keys($this->rankMultipliers);
        foreach ($this->families() as $familyIndex => $family) {
            foreach ($rankKeys as $rankIndex => $rank) {
                $externalId = $this->accessoryExternalId($family['id'], $rank);
                $nextRank = $rankKeys[$rankIndex + 1] ?? null;
                $multiplier = $this->rankMultipliers[$rank];
                $stats = [];
                foreach (['hp', 'mp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'] as $stat) {
                    $base = $family['base'][$stat] ?? 0;
                    $stats[$stat] = $base > 0 ? max(1, (int) floor($base * $multiplier)) : 0;
                }

                DB::table('items')->updateOrInsert(
                    ['external_item_id' => $externalId],
                    [
                        'name' => $family['names'][$rankIndex],
                        'type' => 'accessory',
                        'description' => $family['role'],
                        'rarity' => strtolower($rank),
                        'price' => 0,
                        'sell_price' => 0,
                        'hp_bonus' => $stats['hp'],
                        'mp_bonus' => $stats['mp'],
                        'str_bonus' => $stats['str'],
                        'def_bonus' => $stats['def'],
                        'agi_bonus' => $stats['agi'],
                        'mag_bonus' => $stats['mag'],
                        'spr_bonus' => $stats['spr'],
                        'luk_bonus' => $stats['luk'],
                        'required_level' => 1,
                        'is_shop_item' => false,
                        'is_active' => true,
                        'sort_order' => 70000 + (($rankIndex + 1) * 100) + $familyIndex,
                        'unlock_city_id' => null,
                        'sub_type' => $family['name'],
                        'accessory_family_id' => $family['id'],
                        'accessory_family_name' => $family['name'],
                        'accessory_category_id' => $family['category_id'],
                        'accessory_category_name' => $family['category_name'],
                        'accessory_rank' => $rank,
                        'accessory_rank_sort' => $rankIndex + 1,
                        'accessory_rank_multiplier' => $multiplier,
                        'evolution_stage' => $rankIndex,
                        'next_accessory_external_id' => $nextRank ? $this->accessoryExternalId($family['id'], $nextRank) : null,
                        'is_evolution_enabled' => $rank !== 'EPIC',
                        'is_drop_enabled' => in_array($rank, ['G', 'F', 'E', 'D', 'C', 'B', 'A'], true),
                        'is_supply_enabled' => false,
                        'max_enhance' => 3,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    private function importRecipes($now): void
    {
        DB::table('accessory_evolution_recipe_ingredients')->delete();
        DB::table('accessory_evolution_recipes')->delete();

        $rankKeys = array_keys($this->rankMultipliers);
        foreach ($this->families() as $family) {
            foreach ($rankKeys as $rankIndex => $rank) {
                $nextRank = $rankKeys[$rankIndex + 1] ?? null;
                if (!$nextRank) {
                    continue;
                }

                $recipeId = 'ACC_EVO_' . $family['id'] . '_' . $rank . '_TO_' . $nextRank;
                DB::table('accessory_evolution_recipes')->insert([
                    'recipe_id' => $recipeId,
                    'from_accessory_id' => $this->accessoryExternalId($family['id'], $rank),
                    'from_accessory_name' => $family['names'][$rankIndex],
                    'to_accessory_id' => $this->accessoryExternalId($family['id'], $nextRank),
                    'to_accessory_name' => $family['names'][$rankIndex + 1],
                    'from_rank' => $rank,
                    'to_rank' => $nextRank,
                    'required_same_accessory_count' => $this->requiredSameCount($rank),
                    'unlock_city_id' => null,
                    'requires_city7_boss_cleared' => $rank === 'A',
                    'requires_hidden_dungeon_unlocked' => $rank === 'S',
                    'requires_hidden_boss_cleared' => $rank === 'SS',
                    'requires_demon_king_cleared' => $rank === 'SSS',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($this->recipeMaterials($rank, $family) as [$code, $name, $quantity]) {
                    DB::table('accessory_evolution_recipe_ingredients')->insert([
                        'recipe_id' => $recipeId,
                        'ingredient_type' => 'material',
                        'material_code' => $code,
                        'material_name' => $name,
                        'required_quantity' => $quantity,
                        'is_consumed' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function requiredSameCount(string $rank): int
    {
        return match ($rank) {
            'G', 'F', 'E' => 3,
            'D', 'C', 'B' => 2,
            default => 1,
        };
    }

    private function recipeMaterials(string $rank, array $family): array
    {
        $category = $this->categoryMaterialCodes($family);

        return match ($rank) {
            'E' => [['ACC0001', '装飾の欠片', 5]],
            'D' => [['ACC0001', '装飾の欠片', 10], [$category['fragment'][0], $category['fragment'][1], 3]],
            'C' => [['ACC0002', '装飾の結晶', 5], [$category['fragment'][0], $category['fragment'][1], 10]],
            'B' => [['ACC0002', '装飾の結晶', 10], [$category['crystal'][0], $category['crystal'][1], 5]],
            'A' => [['ACC0003', '装飾の核', 3], [$category['crystal'][0], $category['crystal'][1], 8]],
            'S' => [['ACC0004', '古代装飾片', 5], [$category['core'][0], $category['core'][1], 3]],
            'SS' => [['ACC0005', '星屑の宝材', 5], [$category['core'][0], $category['core'][1], 5]],
            'SSS' => [['ACC0006', '秘境素材の欠片', 5], ['ACC0005', '星屑の宝材', 1]],
            default => [],
        };
    }

    private function categoryMaterialCodes(array $family): array
    {
        $index = array_search($family['id'], array_column($this->families(), 'id'), true);
        $base = 10 + ($index * 3);

        return [
            'fragment' => ['ACC' . str_pad((string) $base, 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の欠片'],
            'crystal' => ['ACC' . str_pad((string) ($base + 1), 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の結晶'],
            'core' => ['ACC' . str_pad((string) ($base + 2), 4, '0', STR_PAD_LEFT), $family['category_name'] . 'の核'],
        ];
    }

    private function accessoryExternalId(string $familyId, string $rank): string
    {
        return 'ACC_' . $familyId . '_' . $rank;
    }

    private function materialRankTier(string $rarity): int
    {
        return match ($rarity) {
            'N', 'N+' => 1,
            'R', 'R+' => 2,
            'SR', 'SR+' => 3,
            'SSR' => 4,
            'SSSR' => 5,
            'EPIC' => 6,
            default => 1,
        };
    }

    private function families(): array
    {
        return [
            ['id' => 'POWER_RING', 'name' => '力の指輪系', 'category_id' => 'power', 'category_name' => '腕力', 'role' => '物理火力を補助する装飾品。', 'base' => ['str' => 3], 'names' => ['力の指輪', '剛力の指輪', '豪腕の指輪', '戦士の指輪', '闘鬼の指輪', '巨人の指輪', '英傑の指輪', '武神の指輪', '破壊神の指輪', '原初の力環', '神腕の王環']],
            ['id' => 'GUARD_RING', 'name' => '守りの指輪系', 'category_id' => 'guard', 'category_name' => '守護', 'role' => '物理耐久を補助する装飾品。', 'base' => ['def' => 3], 'names' => ['守りの指輪', '堅守の指輪', '鉄壁の指輪', '騎士の指輪', '城塞の指輪', '巨壁の指輪', '守護者の指輪', '聖盾の指輪', '神域の守護環', '原初の守護環', '世界を護る王環']],
            ['id' => 'MAGIC_RING', 'name' => '魔力の指輪系', 'category_id' => 'magic', 'category_name' => '魔力', 'role' => '魔法火力を補助する装飾品。', 'base' => ['mag' => 3], 'names' => ['魔力の指輪', '魔導の指輪', '精霊の指輪', '星見の指輪', '禁呪の指輪', '大魔導の指輪', '賢者の指輪', 'ルミナスリング', '天啓の魔環', '原初の魔導環', '創世魔環']],
            ['id' => 'PRAYER_AMULET', 'name' => '祈りの護符系', 'category_id' => 'prayer', 'category_name' => '祈祷', 'role' => '魔法耐久と回復職を補助する装飾品。', 'base' => ['spr' => 3], 'names' => ['祈りの護符', '清めの護符', '聖者の護符', '祝福の護符', '退魔の護符', '光祈の護符', '大聖者の護符', '神託の護符', '天啓の聖符', '原初の祈符', '救世の神符']],
            ['id' => 'WIND_CHARM', 'name' => '疾風の羽飾り系', 'category_id' => 'wind', 'category_name' => '疾風', 'role' => '速度と運を補助する装飾品。', 'base' => ['agi' => 2, 'luk' => 1], 'names' => ['風の羽飾り', '疾風の羽飾り', '早駆けの羽飾り', '風読みの羽飾り', '影走りの羽飾り', '迅雷の羽飾り', '神速の羽飾り', '天翔の羽飾り', '星渡りの羽飾り', '時渡りの羽飾り', '時空を越える翼飾り']],
            ['id' => 'LUCK_CHARM', 'name' => '幸運のお守り系', 'category_id' => 'luck', 'category_name' => '幸運', 'role' => '運を補助する装飾品。', 'base' => ['luk' => 3], 'names' => ['幸運のお守り', '旅運のお守り', '招福のお守り', '星屑のお守り', '月兎のお守り', '奇跡のお守り', '運命のお守り', '星詠みのお守り', '天命のお守り', '原初の幸運符', '運命を掴む神符']],
            ['id' => 'LIFE_NECKLACE', 'name' => '生命の首飾り系', 'category_id' => 'life', 'category_name' => '生命', 'role' => '最大HPを補助する装飾品。', 'base' => ['hp' => 30], 'names' => ['生命の首飾り', '活力の首飾り', '癒しの首飾り', '若木の首飾り', '生命樹の首飾り', '巨人の首飾り', '英傑の首飾り', '神樹の首飾り', '不滅の首飾り', '原初生命の首飾り', '永劫生命の神飾り']],
            ['id' => 'MIND_EARRING', 'name' => '精神の耳飾り系', 'category_id' => 'mind', 'category_name' => '精神', 'role' => '最大MPを補助する装飾品。', 'base' => ['mp' => 15], 'names' => ['精神の耳飾り', '集中の耳飾り', '魔力の耳飾り', '星見の耳飾り', '深思の耳飾り', '賢者の耳飾り', '大賢者の耳飾り', '天啓の耳飾り', '神域の耳飾り', '原初精神の耳飾り', '無限精神の神飾り']],
            ['id' => 'BALANCE_BRACELET', 'name' => '均衡の腕輪系', 'category_id' => 'balance', 'category_name' => '均衡', 'role' => '複数能力を少しずつ補助する装飾品。', 'base' => ['str' => 1, 'def' => 1, 'mag' => 1, 'spr' => 1, 'agi' => 1, 'luk' => 1], 'names' => ['均衡の腕輪', '調和の腕輪', '冒険者の腕輪', '開拓者の腕輪', '英傑の腕輪', '覇者の腕輪', '天命の腕輪', '星界の腕輪', '神域の腕輪', '原初均衡の腕輪', '万象調和の神環']],
            ['id' => 'ADVENTURER_PROOF', 'name' => '冒険者の証系', 'category_id' => 'adventure', 'category_name' => '冒険', 'role' => '初心者から使いやすい汎用装飾品。', 'base' => ['hp' => 10, 'mp' => 5, 'luk' => 1], 'names' => ['冒険者の証', '熟練冒険者の証', '探検家の証', '開拓者の証', '英傑の証', '覇者の証', '救世者の証', '星渡りの証', '神域到達者の証', '伝説冒険者の証', 'ヴァルゼリアの証']],
        ];
    }
};
