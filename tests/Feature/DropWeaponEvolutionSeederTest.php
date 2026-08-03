<?php

namespace Tests\Feature;

use Database\Seeders\DropEquipmentAdditionsSeeder;
use Database\Seeders\DropWeaponEvolutionSeeder;
use Database\Seeders\AllDungeonsSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\EnemySeeder;
use Database\Seeders\FerdiaRegionSeeder;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Services\DropService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class DropWeaponEvolutionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const STAT_COLUMNS = ['str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus'];

    public function test_enemy_specific_drop_weapons_receive_one_dedicated_route_through_epic(): void
    {
        $this->seed(DropEquipmentAdditionsSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);
        $this->seed(DropEquipmentAdditionsSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);

        $master = json_decode(
            (string) file_get_contents(base_path('database/data/drop_weapon_evolution_chains.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertCount(22, $master['chains']);
        $this->assertSame(
            124,
            DB::table('items')->where('source_type', 'drop_weapon_evolution')->count()
        );
        $this->assertSame(
            124,
            DB::table('weapon_evolution_recipes')
                ->where('recipe_id', 'like', 'DROP_EVO_%')
                ->where('is_active', true)
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('weapon_evolution_recipe_ingredients as ingredient')
                ->leftJoin('materials as material', 'material.material_code', '=', 'ingredient.ingredient_id')
                ->where('ingredient.recipe_id', 'like', 'DROP_EVO_%')
                ->whereNull('material.id')
                ->count()
        );

        foreach ($master['chains'] as $chain) {
            $source = DB::table('items')
                ->where('external_item_id', $chain['source_external_item_id'])
                ->first();

            $this->assertNotNull($source);
            $this->assertTrue((bool) $source->is_evolution_enabled);
            $this->assertSame($chain['source_rank'], $source->weapon_rank);
            $this->assertStringNotContainsString('進化不可', (string) $source->description);

            $fromExternalId = $chain['source_external_item_id'];
            foreach ($chain['names'] as $rank => $name) {
                $toExternalId = "DROP_EVO_{$chain['key']}_{$rank}";
                $target = DB::table('items')->where('external_item_id', $toExternalId)->first();

                $this->assertNotNull($target, $toExternalId);
                $this->assertSame($name, $target->name);
                $this->assertSame($rank, $target->weapon_rank);
                $this->assertFalse((bool) $target->is_drop_enabled);
                $this->assertSame(2, (int) $target->weapon_offense_scale_version);

                $recipes = DB::table('weapon_evolution_recipes')
                    ->where('from_weapon_id', $fromExternalId)
                    ->where('is_active', true)
                    ->get();

                $this->assertCount(1, $recipes, "{$fromExternalId} must have exactly one evolution destination.");
                $this->assertSame($toExternalId, $recipes->first()->to_weapon_id);

                $fromExternalId = $toExternalId;
            }

            $this->assertSame(
                0,
                DB::table('weapon_evolution_recipes')
                    ->where('from_weapon_id', $fromExternalId)
                    ->where('is_active', true)
                    ->count()
            );
        }

        $graveSword = DB::table('items')->where('external_item_id', 'DROP_WPN_1_9900')->first();
        $this->assertSame(128, (int) $graveSword->str_bonus);
        $this->assertSame(2, (int) $graveSword->weapon_offense_scale_version);

        $graveEpic = DB::table('items')
            ->where('external_item_id', 'DROP_EVO_GRAVE_KNIGHT_SWORD_EPIC')
            ->first();
        $this->assertSame('永劫墓守剣グレイヴァル', $graveEpic->name);
        $this->assertFalse((bool) $graveEpic->is_evolution_enabled);
        $this->assertFalse((bool) $graveEpic->is_evolvable);

        $finalRecipe = DB::table('weapon_evolution_recipes')
            ->where('recipe_id', 'DROP_EVO_GRAVE_KNIGHT_SWORD_SSS_TO_EPIC')
            ->first();
        $this->assertNotNull($finalRecipe);
        $this->assertDatabaseHas('weapon_evolution_recipe_ingredients', [
            'recipe_id' => $finalRecipe->recipe_id,
            'ingredient_id' => 'MAT_BR_WPN_DARK_CREST',
            'quantity' => 1,
        ]);
        $this->assertSame(
            0,
            DB::table('weapon_evolution_recipe_ingredients as ingredient')
                ->join('weapon_evolution_recipes as recipe', 'recipe.recipe_id', '=', 'ingredient.recipe_id')
                ->where('recipe.from_rank', 'SS')
                ->where('recipe.to_rank', 'SSS')
                ->where('ingredient.ingredient_id', 'like', 'MAT_BR_WPN_%_SECRET')
                ->where('ingredient.quantity', '!=', 20)
                ->count()
        );
    }

    public function test_ranked_enemy_specific_weapons_stay_out_of_the_generic_drop_pool(): void
    {
        $this->seed(DropEquipmentAdditionsSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);

        $enemy = Enemy::query()->firstOrFail();
        $method = new ReflectionMethod(DropService::class, 'equipmentCandidates');
        $genericCandidates = $method->invoke(new DropService(), 'weapon', 'F', $enemy);

        $this->assertTrue($genericCandidates->isNotEmpty());
        $this->assertFalse(
            $genericCandidates->contains(
                fn ($item) => str_starts_with((string) $item->external_item_id, 'DROP_WPN_')
            )
        );
    }

    public function test_rare_s_weapons_are_assigned_to_their_named_enemy_at_point_zero_three_percent(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(AllDungeonsSeeder::class);
        $this->seed(EnemySeeder::class);
        $this->seed(DropEquipmentAdditionsSeeder::class);

        foreach ([
            ['王家の墓', '王家の呪霊', 'DROP_WPN_RARE_ROYAL_WRAITH_GRIMOIRE'],
            ['星見の塔', '星見天文ゴーレム', 'DROP_WPN_RARE_ASTRAL_GOLEM_GUN'],
            ['魔王軍要塞', '黒騎士', 'DROP_WPN_RARE_BLACK_KNIGHT_SWORD'],
            ['魔神の間', '魔神の化身', 'DROP_WPN_RARE_ABYSS_AVATAR_FIST'],
        ] as [$areaName, $enemyName, $externalItemId]) {
            $enemy = Enemy::query()->where('name', $enemyName)->first();
            $item = DB::table('items')->where('external_item_id', $externalItemId)->first();

            $this->assertNotNull($enemy, "missing {$areaName} {$enemyName}");
            $this->assertSame($areaName, $enemy->area?->name);
            $this->assertNotNull($item, "missing {$externalItemId}");

            $drop = EnemyDrop::query()
                ->where('enemy_id', $enemy->id)
                ->where('item_id', $item->id)
                ->first();

            $this->assertNotNull($drop, "missing drop {$enemyName} {$externalItemId}");
            $this->assertSame(0.03, (float) $drop->drop_rate);
        }
    }

    public function test_ferdia_rare_weapons_drop_at_point_zero_three_percent_and_keep_innate_killers_through_epic(): void
    {
        $this->seed(FerdiaRegionSeeder::class);
        $this->seed(DropEquipmentAdditionsSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);

        foreach ([
            [
                '見晴らしの丘道', 'ヒル・ホーク',
                'DROP_WPN_RARE_FERDIA_HILL_HAWK_BOW', 'DROP_EVO_FERDIA_HILL_HAWK_BOW_EPIC',
                'flying',
                ['mp_bonus' => 400, 'str_bonus' => 488, 'spr_bonus' => 200],
                ['mp_bonus' => 984, 'str_bonus' => 1200, 'spr_bonus' => 496],
            ],
            [
                'アーデル遺跡', 'ルイン・ギア',
                'DROP_WPN_RARE_FERDIA_RUIN_GEAR_GUN', 'DROP_EVO_FERDIA_RUIN_GEAR_GUN_EPIC',
                'machine',
                ['hp_bonus' => 320, 'str_bonus' => 424, 'def_bonus' => 80, 'mag_bonus' => 424, 'spr_bonus' => 120],
                ['hp_bonus' => 788, 'str_bonus' => 1040, 'def_bonus' => 200, 'mag_bonus' => 1040, 'spr_bonus' => 296],
            ],
            [
                '大樹の聖城外縁', '聖城の光霊',
                'DROP_WPN_RARE_FERDIA_LIGHT_SPIRIT_DEVICE', 'DROP_EVO_FERDIA_LIGHT_SPIRIT_DEVICE_EPIC',
                'dragon',
                ['mp_bonus' => 984, 'mag_bonus' => 704, 'spr_bonus' => 320],
                ['mp_bonus' => 2424, 'mag_bonus' => 1736, 'spr_bonus' => 784],
            ],
            [
                '北境の霊峰エルヴァン', 'スノー・インプ',
                'DROP_WPN_RARE_FERDIA_SNOW_IMP_DAGGER', 'DROP_EVO_FERDIA_SNOW_IMP_DAGGER_EPIC',
                'demon',
                ['hp_bonus' => 480, 'str_bonus' => 432, 'spr_bonus' => 200],
                ['hp_bonus' => 1180, 'str_bonus' => 1064, 'spr_bonus' => 496],
            ],
        ] as [$areaName, $enemyName, $sourceExternalId, $epicExternalId, $speciesKey, $sourceStats, $epicStats]) {
            $enemy = Enemy::query()
                ->where('name', $enemyName)
                ->whereHas('area', fn ($query) => $query->where('name', $areaName))
                ->first();
            $source = DB::table('items')->where('external_item_id', $sourceExternalId)->first();
            $epic = DB::table('items')->where('external_item_id', $epicExternalId)->first();

            $this->assertNotNull($enemy, "missing {$areaName} {$enemyName}");
            $this->assertNotNull($source, "missing {$sourceExternalId}");
            $this->assertNotNull($epic, "missing {$epicExternalId}");
            $this->assertSame(0.03, (float) EnemyDrop::query()
                ->where('enemy_id', $enemy->id)
                ->where('item_id', $source->id)
                ->value('drop_rate'));
            $this->assertTrue((bool) $source->affix_enabled);
            $this->assertTrue((bool) $epic->affix_enabled);
            $this->assertSame($speciesKey, $source->innate_killer_species_key);
            $this->assertSame($speciesKey, $epic->innate_killer_species_key);
            $this->assertSame(0.12, (float) $source->innate_killer_damage_rate);
            $this->assertSame(0.12, (float) $epic->innate_killer_damage_rate);

            foreach ($sourceStats as $column => $expected) {
                $this->assertSame($expected, (int) $source->{$column}, "{$sourceExternalId} {$column}");
            }
            foreach ($epicStats as $column => $expected) {
                $this->assertSame($expected, (int) $epic->{$column}, "{$epicExternalId} {$column}");
            }
        }
    }

    public function test_rebalanced_unique_paths_keep_a_distinct_role_without_a_clear_branch_upgrade(): void
    {
        $this->seed(DropEquipmentAdditionsSeeder::class);
        $this->seed(DropWeaponEvolutionSeeder::class);

        foreach ([
            'DROP_WPN_2_9905' => ['str_bonus' => 256, 'agi_bonus' => -24, 'luk_bonus' => 48],
            'DROP_WPN_1_9901' => ['str_bonus' => 80, 'agi_bonus' => 32, 'luk_bonus' => 56],
            'DROP_WPN_3_9908' => ['str_bonus' => 136, 'agi_bonus' => 80, 'luk_bonus' => 64],
            'DROP_EVO_POWDER_AXE_EPIC' => ['str_bonus' => 2824, 'agi_bonus' => -264, 'luk_bonus' => 528],
            'DROP_EVO_GOBLIN_ARCHER_BOW_EPIC' => ['str_bonus' => 1192, 'agi_bonus' => 480, 'luk_bonus' => 832],
            'DROP_EVO_LEAF_HUNTER_BOW_EPIC' => ['str_bonus' => 1112, 'agi_bonus' => 656, 'luk_bonus' => 520],
        ] as $externalItemId => $expectedStats) {
            $item = DB::table('items')->where('external_item_id', $externalItemId)->first();

            $this->assertNotNull($item, $externalItemId);
            foreach ($expectedStats as $column => $expected) {
                $this->assertSame($expected, (int) $item->{$column}, "{$externalItemId} {$column}");
            }
        }

        $master = json_decode(
            (string) file_get_contents(base_path('database/data/drop_weapon_evolution_chains.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($master['chains'] as $chain) {
            $previous = DB::table('items')->where('external_item_id', $chain['source_external_item_id'])->first();
            $this->assertNotNull($previous);

            foreach ($chain['names'] as $rank => $_name) {
                $current = DB::table('items')
                    ->where('external_item_id', "DROP_EVO_{$chain['key']}_{$rank}")
                    ->first();
                $this->assertNotNull($current);
                $this->assertGreaterThanOrEqual($this->statTotal($previous, true), $this->statTotal($current, true));
                $this->assertGreaterThanOrEqual($this->statTotal($previous, false), $this->statTotal($current, false));
                $previous = $current;
            }

            foreach (['S', 'SS', 'SSS', 'EPIC'] as $rank) {
                $uniqueExternalId = $rank === $chain['source_rank']
                    ? $chain['source_external_item_id']
                    : "DROP_EVO_{$chain['key']}_{$rank}";
                $unique = DB::table('items')
                    ->where('external_item_id', $uniqueExternalId)
                    ->first();
                $standardBranches = DB::table('items')
                    ->where('weapon_rank', $rank)
                    ->where('external_item_id', 'like', "WPN_BR_{$chain['template_family_id']}_%")
                    ->get();

                $this->assertNotNull($unique);
                $this->assertCount(3, $standardBranches, "{$chain['key']} {$rank}");
                foreach ($standardBranches as $standard) {
                    $this->assertFalse($this->strictlyDominates($standard, $unique), "{$standard->external_item_id} must not dominate {$unique->external_item_id}");
                    $this->assertFalse($this->strictlyDominates($unique, $standard), "{$unique->external_item_id} must not dominate {$standard->external_item_id}");
                }
            }
        }
    }

    private function statTotal(object $item, bool $positiveOnly): int
    {
        return array_sum(array_map(
            fn (string $column) => $positiveOnly ? max(0, (int) $item->{$column}) : (int) $item->{$column},
            self::STAT_COLUMNS
        ));
    }

    private function strictlyDominates(object $left, object $right): bool
    {
        $hasStrictlyGreaterStat = false;

        foreach (self::STAT_COLUMNS as $column) {
            $leftValue = (int) $left->{$column};
            $rightValue = (int) $right->{$column};
            if ($leftValue < $rightValue) {
                return false;
            }

            $hasStrictlyGreaterStat = $hasStrictlyGreaterStat || $leftValue > $rightValue;
        }

        return $hasStrictlyGreaterStat;
    }
}
