<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\EquipmentEvolutionService;
use App\Services\ExplorationMapLegacyRewardService;
use App\Services\ExplorationService;
use App\Services\GoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class AccessorySSTierReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_ten_standard_s_to_ss_recipes_are_unlocked_without_changing_costs(): void
    {
        $recipes = DB::table('accessory_evolution_recipes')
            ->where('is_active', true)
            ->where('recipe_id', 'like', 'ACC_EVO_%')
            ->where('from_rank', 'S')
            ->where('to_rank', 'SS')
            ->get();

        $this->assertCount(10, $recipes);
        $this->assertTrue($recipes->every(fn (object $recipe): bool => $recipe->unlock_city_id === null
            && ! (bool) $recipe->requires_city7_boss_cleared
            && ! (bool) $recipe->requires_hidden_dungeon_unlocked
            && ! (bool) $recipe->requires_hidden_boss_cleared
            && ! (bool) $recipe->requires_demon_king_cleared
            && (int) $recipe->required_same_accessory_count === 1
        ));

        $ingredients = DB::table('accessory_evolution_recipe_ingredients')
            ->whereIn('recipe_id', $recipes->pluck('recipe_id'))
            ->get();

        $this->assertCount(10, $ingredients);
        $this->assertTrue($ingredients->every(fn (object $ingredient): bool => (string) $ingredient->material_code === 'ACC0004'
            && (int) $ingredient->required_quantity === 3
            && (bool) $ingredient->is_consumed
        ));
        $this->assertSame(8000, app(GoldService::class)->evolutionCost('SS'));
        $this->assertSame(0, DB::table('accessory_evolution_recipes')
            ->where('recipe_id', 'like', 'BR_ACC_%')
            ->where('is_active', true)
            ->count());
    }

    public function test_character_without_hidden_area_can_evolve_once_with_existing_costs(): void
    {
        $recipe = DB::table('accessory_evolution_recipes')
            ->where('recipe_id', 'ACC_EVO_POWER_RING_S_TO_SS')
            ->firstOrFail();
        $source = Item::query()->where('external_item_id', $recipe->from_accessory_id)->firstOrFail();
        $target = Item::query()->where('external_item_id', $recipe->to_accessory_id)->firstOrFail();
        $material = Material::query()->where('material_code', 'ACC0004')->firstOrFail();
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '装飾品進化確認者',
            'money' => 8000,
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $ownedSource = CharacterItem::create([
            'character_id' => $character->id,
            'item_id' => $source->id,
            'is_equipped' => false,
            'is_stored' => true,
            'is_locked' => false,
            'enhance_level' => 2,
        ]);
        CharacterMaterial::create([
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => 3,
        ]);

        $service = app(EquipmentEvolutionService::class);
        $candidate = collect($service->candidates($character))
            ->firstWhere('recipe_id', $recipe->recipe_id);

        $this->assertNotNull($candidate);
        $this->assertTrue($candidate['can_evolve']);
        $this->assertSame(8000, $candidate['gold_cost']);
        $this->assertSame('ACC0004', $candidate['required_materials'][0]['material_code']);
        $this->assertSame(3, $candidate['required_materials'][0]['required']);

        $valmon = ValmonMaster::create([
            'valmon_key' => 'accessory-ss-release-test',
            'name' => '進化確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmon->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('smith.index'))
            ->assertOk()
            ->assertSee('古代装飾片')
            ->assertDontSee('現時点で未実装です');

        $result = $service->evolve($character, 'accessory', $recipe->recipe_id, $ownedSource->id);

        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertDatabaseMissing('character_items', ['id' => $ownedSource->id]);
        $this->assertDatabaseHas('character_items', [
            'id' => $result['created_equipment_id'],
            'character_id' => $character->id,
            'item_id' => $target->id,
            'enhance_level' => 2,
        ]);
        $this->assertDatabaseMissing('character_materials', [
            'character_id' => $character->id,
            'material_id' => $material->id,
        ]);
        $this->assertDatabaseHas('equipment_evolution_logs', [
            'character_id' => $character->id,
            'recipe_id' => $recipe->recipe_id,
            'created_equipment_instance_id' => $result['created_equipment_id'],
        ]);

        try {
            $service->evolve($character, 'accessory', $recipe->recipe_id, $ownedSource->id);
            $this->fail('同じ進化元を再度消費できてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertSame('進化元の装備が不足しています。', $exception->getMessage());
        }
        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertSame(1, DB::table('equipment_evolution_logs')->where('character_id', $character->id)->count());
    }

    public function test_fragment_drops_are_scoped_and_rerunning_migration_preserves_other_ancient_rates(): void
    {
        $fragmentId = Material::query()->where('material_code', 'ACC0004')->value('id');
        $expectedEnemyIds = DB::table('enemies')
            ->whereBetween('area_id', [1001, 1013])
            ->where('is_boss', false)
            ->whereIn('type_name', ['人型', '巨人'])
            ->pluck('id');
        $this->assertCount(26, $expectedEnemyIds);

        $fragmentDrops = DB::table('material_drops')
            ->where('material_id', $fragmentId)
            ->where('is_active', true)
            ->get();
        $this->assertCount(26, $fragmentDrops);
        $this->assertEqualsCanonicalizing($expectedEnemyIds->all(), $fragmentDrops->pluck('enemy_id')->all());
        foreach ($fragmentDrops as $drop) {
            $sourceRate = DB::table('material_drops as source_drop')
                ->join('materials as material', 'material.id', '=', 'source_drop.material_id')
                ->where('source_drop.enemy_id', $drop->enemy_id)
                ->where('source_drop.is_active', true)
                ->where('material.material_type', 'branch_evolution')
                ->where('material.material_code', 'like', '%_ANCIENT')
                ->value('source_drop.drop_rate');
            $this->assertNotNull($sourceRate);
            $this->assertEqualsWithDelta((float) $sourceRate, (float) $drop->drop_rate, 0.001);
        }

        $otherDrop = DB::table('material_drops as drop')
            ->join('materials as material', 'material.id', '=', 'drop.material_id')
            ->where('material.material_code', 'like', '%_ANCIENT')
            ->value('drop.id');
        $this->assertNotNull($otherDrop);
        DB::table('material_drops')->where('id', $otherDrop)->update(['drop_rate' => 0.12]);

        $migration = require database_path('migrations/2026_09_17_020000_release_accessory_s_to_ss_evolution.php');
        $migration->up();

        $this->assertEqualsWithDelta(0.12, (float) DB::table('material_drops')->where('id', $otherDrop)->value('drop_rate'), 0.001);
        $this->assertSame(26, DB::table('material_drops')->where('material_id', $fragmentId)->where('is_active', true)->count());
    }

    public function test_new_fragment_is_available_to_ancient_maps_and_ferdia_treasure(): void
    {
        $mapRewards = app(ExplorationMapLegacyRewardService::class);
        $foundCodes = [];
        foreach (range(1, 128) as $seed) {
            $foundCodes[] = $mapRewards->ancientFragmentForSeedHash("accessory-ss-{$seed}", true)?->material_code;
            $this->assertNotSame('ACC0004', $mapRewards->ancientFragmentForSeedHash("accessory-ss-{$seed}")?->material_code);
        }
        $this->assertContains('ACC0004', $foundCodes);

        $fragment = Material::query()->where('material_code', 'ACC0004')->firstOrFail();
        $recognizesAncient = new ReflectionMethod(ExplorationService::class, 'isAncientMaterial');
        $this->assertTrue($recognizesAncient->invoke(app(ExplorationService::class), $fragment));
    }

    public function test_inconsistent_drop_master_aborts_before_unlocking_a_recipe(): void
    {
        $recipeId = 'ACC_EVO_POWER_RING_S_TO_SS';
        DB::table('accessory_evolution_recipes')
            ->where('recipe_id', $recipeId)
            ->update(['requires_hidden_dungeon_unlocked' => true]);
        $fragmentId = Material::query()->where('material_code', 'ACC0004')->value('id');
        $enemyId = DB::table('enemies')
            ->whereBetween('area_id', [1001, 1013])
            ->where('is_boss', false)
            ->whereIn('type_name', ['人型', '巨人'])
            ->value('id');
        DB::table('material_drops')
            ->where('enemy_id', $enemyId)
            ->where('material_id', $fragmentId)
            ->update(['is_active' => false]);

        $migration = require database_path('migrations/2026_09_17_020000_release_accessory_s_to_ss_evolution.php');
        try {
            $migration->up();
            $this->fail('素材ドロップが不整合のまま進化を解放してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Existing accessory fragment drop', $exception->getMessage());
        }

        $this->assertSame(1, (int) DB::table('accessory_evolution_recipes')
            ->where('recipe_id', $recipeId)
            ->value('requires_hidden_dungeon_unlocked'));
    }
}
