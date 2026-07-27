<?php

namespace Tests\Feature;

use Database\Seeders\DropEquipmentAdditionsSeeder;
use Database\Seeders\DropWeaponEvolutionSeeder;
use App\Models\Enemy;
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

        $this->assertCount(14, $master['chains']);
        $this->assertSame(
            100,
            DB::table('items')->where('source_type', 'drop_weapon_evolution')->count()
        );
        $this->assertSame(
            100,
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
                $unique = DB::table('items')
                    ->where('external_item_id', "DROP_EVO_{$chain['key']}_{$rank}")
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
