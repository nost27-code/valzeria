<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\EquipmentEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmithCandidateLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_page_does_not_generate_any_candidates(): void
    {
        $character = $this->character();
        $this->mock(EquipmentEvolutionService::class)->shouldNotReceive('candidates');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('smith.index'))
            ->assertOk()
            ->assertViewHas('equipmentType', null)
            ->assertViewHas('evolutionCandidates', [])
            ->assertSee('合成する装備の種類を選んでください。')
            ->assertSee(route('smith.index', ['type' => 'weapon']))
            ->assertSee(route('smith.index', ['type' => 'armor']))
            ->assertSee(route('smith.index', ['type' => 'accessory']));
        $this->assertDoesNotMatchRegularExpression('/\sdata-smith-source-card(?:\s|>)/', $response->getContent());
    }

    public function test_each_selected_type_only_queries_and_renders_that_type(): void
    {
        $character = $this->character();
        foreach (['weapon', 'armor', 'accessory'] as $type) {
            $this->recipe($character, $type, 'SELECT_'.$type);
        }

        foreach (['weapon', 'armor', 'accessory'] as $type) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $candidates = app(EquipmentEvolutionService::class)->candidates($character, $type);
            $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            DB::disableQueryLog();

            $this->assertCount(1, $candidates);
            $this->assertSame($type, $candidates[0]['equipment_type']);
            foreach (array_diff(['weapon', 'armor', 'accessory'], [$type]) as $otherType) {
                $this->assertStringNotContainsString($otherType.'_evolution_recipe', $queries);
            }

            $response = $this->actingAs($character->user)
                ->withSession(['current_character_id' => $character->id])
                ->get(route('smith.index', ['type' => $type]))
                ->assertOk()
                ->assertSee('SELECT_'.$type.'元')
                ->assertSee('aria-current="page"', false);
            foreach (array_diff(['weapon', 'armor', 'accessory'], [$type]) as $otherType) {
                $response->assertDontSee('SELECT_'.$otherType.'元');
            }
        }
    }

    public function test_unowned_recipes_do_not_add_candidate_queries(): void
    {
        $character = $this->character();
        $this->recipe($character, 'weapon', 'OWNED');

        $measure = function () use ($character): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $candidates = app(EquipmentEvolutionService::class)->candidates($character, 'weapon');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return [$candidates, count($queries)];
        };
        [$before, $beforeQueries] = $measure();
        foreach (range(1, 30) as $index) {
            $this->recipe($character, 'weapon', 'UNOWNED_'.$index, false);
        }
        [$after, $afterQueries] = $measure();

        $this->assertSame(array_column($before, 'recipe_id'), array_column($after, 'recipe_id'));
        $this->assertSame($beforeQueries, $afterQueries);
        $this->assertCount(1, $after);
    }

    public function test_default_service_call_keeps_all_types_for_the_home_action(): void
    {
        $character = $this->character();
        foreach (['weapon', 'armor', 'accessory'] as $type) {
            $this->recipe($character, $type, 'HOME_'.$type);
        }

        $candidates = app(EquipmentEvolutionService::class)->candidates($character);
        $this->assertCount(3, $candidates);
        $this->assertEqualsCanonicalizing(['weapon', 'armor', 'accessory'], array_column($candidates, 'equipment_type'));
    }

    public function test_material_shortages_and_sources_are_kept_only_for_owned_candidates(): void
    {
        $character = $this->character();
        $this->recipe($character, 'weapon', 'REQUIRED_MATERIAL');
        $this->recipe($character, 'weapon', 'UNOWNED_MATERIAL', false);
        $material = Material::create([
            'material_code' => 'SMITH_REQUIRED_MATERIAL', 'name' => '合成試験素材', 'category' => '共通素材', 'rarity' => 'N', 'obtain_method' => '試験の採集地',
        ]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 2]);
        foreach (['REQUIRED_MATERIAL', 'UNOWNED_MATERIAL'] as $key) {
            DB::table('weapon_evolution_recipe_ingredients')->insert([
                'recipe_id' => 'SMITH_'.$key, 'ingredient_type' => 'material', 'ingredient_id' => 'SMITH_'.$key,
                'ingredient_name' => '合成試験素材', 'quantity' => 3, 'is_consumed' => true,
            ]);
        }

        $candidates = app(EquipmentEvolutionService::class)->candidates($character, 'weapon');
        $this->assertCount(1, $candidates);
        $this->assertFalse($candidates[0]['can_evolve']);
        $this->assertSame('素材が不足しています。', $candidates[0]['unavailable_reason']);
        $requirement = $candidates[0]['required_materials'][0];
        $this->assertSame(3, $requirement['required']);
        $this->assertSame(2, $requirement['owned']);
        $this->assertSame(1, $requirement['missing']);
        $this->assertNotEmpty($requirement['sources']);
    }

    public function test_duplicate_instances_and_branch_paths_remain_selectable(): void
    {
        $character = $this->character();
        [$from, $to, $source] = $this->recipe($character, 'weapon', 'BRANCH');
        $source->update(['is_equipped' => true, 'is_locked' => true, 'enhance_level' => 2]);
        $duplicate = CharacterItem::create([
            'character_id' => $character->id, 'item_id' => $from->id, 'enhance_level' => 1,
        ]);
        $recipe = (array) DB::table('weapon_evolution_recipes')->where('recipe_id', 'SMITH_BRANCH')->first();
        unset($recipe['id']);
        $recipe['recipe_id'] = 'SMITH_BRANCH_SECOND';
        DB::table('weapon_evolution_recipes')->insert($recipe);

        $candidates = app(EquipmentEvolutionService::class)->candidates($character, 'weapon');
        $this->assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $this->assertSame(2, $candidate['owned_source_count']);
            $this->assertSame([$source->id, $duplicate->id], array_column($candidate['source_options'], 'id'));
            $this->assertTrue($candidate['source_options'][0]['is_locked']);
            $this->assertTrue($candidate['source_options'][0]['is_equipped']);
            $this->assertFalse($candidate['to_is_discovered']);
        }
    }

    public function test_other_characters_inactive_sources_and_inactive_recipes_are_excluded(): void
    {
        $character = $this->character();
        $other = $this->character('別の冒険者');
        $this->recipe($other, 'weapon', 'OTHER');
        [$inactive] = $this->recipe($character, 'weapon', 'INACTIVE_SOURCE');
        $inactive->update(['is_active' => false]);
        $this->recipe($character, 'weapon', 'INACTIVE_RECIPE');
        DB::table('weapon_evolution_recipes')->where('recipe_id', 'SMITH_INACTIVE_RECIPE')->update(['is_active' => false]);

        $this->assertSame([], app(EquipmentEvolutionService::class)->candidates($character, 'weapon'));
    }

    public function test_invalid_type_is_rejected_without_loading_candidates(): void
    {
        $character = $this->character();
        $this->mock(EquipmentEvolutionService::class)->shouldNotReceive('candidates');
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('smith.index'))
            ->get(route('smith.index', ['type' => 'all']))
            ->assertRedirect(route('smith.index'))
            ->assertSessionHasErrors('type');
    }

    public function test_crafting_returns_to_the_selected_type_and_consumes_the_chosen_instance(): void
    {
        $character = $this->character();
        [$from, $to, $source] = $this->recipe($character, 'armor', 'CRAFT');
        $duplicate = CharacterItem::create(['character_id' => $character->id, 'item_id' => $from->id]);
        $source->update(['is_locked' => true, 'enhance_level' => 2]);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('smith.index', ['type' => 'armor']))
            ->post(route('smith.craft'), [
                'recipe_type' => 'armor', 'recipe_id' => 'SMITH_CRAFT', 'source_character_item_id' => $source->id,
            ])
            ->assertRedirect(route('smith.index', ['type' => 'armor']))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('character_items', ['id' => $source->id]);
        $this->assertDatabaseHas('character_items', ['id' => $duplicate->id, 'item_id' => $from->id]);
        $this->assertDatabaseHas('character_items', [
            'character_id' => $character->id, 'item_id' => $to->id, 'enhance_level' => 2, 'is_locked' => true,
        ]);
    }

    public function test_selling_returns_to_the_same_type_even_without_javascript(): void
    {
        $character = $this->character();
        [$from, $to, $source] = $this->recipe($character, 'accessory', 'SELL');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('equipment.sell', $source), ['return_to_smith' => 1])
            ->assertRedirect(route('smith.index', ['type' => 'accessory']))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('character_items', ['id' => $source->id]);
    }

    public function test_failed_crafting_keeps_the_type_and_does_not_consume_the_source(): void
    {
        $character = $this->character();
        [$from, $to, $source] = $this->recipe($character, 'weapon', 'FAILED_CRAFT');
        $character->update(['money' => 0, 'bank_gold' => 0]);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('smith.craft'), [
                'recipe_type' => 'weapon', 'recipe_id' => 'SMITH_FAILED_CRAFT', 'source_character_item_id' => $source->id,
            ])
            ->assertRedirect(route('smith.index', ['type' => 'weapon']))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('character_items', ['id' => $source->id]);
        $this->assertDatabaseMissing('character_items', ['character_id' => $character->id, 'item_id' => $to->id]);
    }

    protected function character(string $name = '合成屋軽量化試験'): Character
    {
        $city = City::create(['name' => '合成屋試験街', 'sort_order' => 1]);
        $character = Character::create([
            'user_id' => User::factory()->create()->id, 'name' => $name,
            'current_city_id' => $city->id, 'money' => 10000, 'bank_gold' => 10000,
        ]);
        $valmon = ValmonMaster::create([
            'valmon_key' => 'smith-test-'.$character->id, 'name' => '試験モン', 'rarity' => 'normal', 'is_active' => true,
        ]);
        PlayerValmon::create([
            'character_id' => $character->id, 'valmon_master_id' => $valmon->id, 'is_partner' => true, 'obtained_at' => now(),
        ]);

        return $character;
    }

    protected function recipe(Character $character, string $type, string $key, bool $owned = true): array
    {
        $from = Item::create([
            'external_item_id' => 'SMITH_'.$key.'_FROM', 'name' => $key.'元', 'type' => $type,
            'rarity' => 'G', 'display_rank' => 'G', $type.'_rank' => 'G', 'is_active' => true,
            'weapon_category' => $type === 'weapon' ? 'sword' : null, 'price' => 100, 'sell_price' => 10,
        ]);
        $to = Item::create([
            'external_item_id' => 'SMITH_'.$key.'_TO', 'name' => $key.'先', 'type' => $type,
            'rarity' => 'F', 'display_rank' => 'F', $type.'_rank' => 'F', 'is_active' => true,
            'weapon_category' => $type === 'weapon' ? 'sword' : null,
        ]);
        $row = ['from_rank' => 'G', 'to_rank' => 'F', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        if ($type === 'armor') {
            $row += [
                'evolution_recipe_id' => 'SMITH_'.$key,
                'source_armor_id' => $from->external_item_id, 'source_armor_name' => $from->name,
                'target_armor_id' => $to->external_item_id, 'target_armor_name' => $to->name,
                'required_same_armor_count' => 1,
            ];
        } else {
            $row += [
                'recipe_id' => 'SMITH_'.$key,
                'from_'.$type.'_id' => $from->external_item_id, 'from_'.$type.'_name' => $from->name,
                'to_'.$type.'_id' => $to->external_item_id, 'to_'.$type.'_name' => $to->name,
                $type === 'weapon' ? 'same_weapon_count' : 'required_same_accessory_count' => 1,
            ];
        }
        DB::table($type.'_evolution_recipes')->insert($row);
        $source = $owned ? CharacterItem::create([
            'character_id' => $character->id, 'item_id' => $from->id, 'acquired_from' => 'drop',
        ]) : null;

        return [$from, $to, $source];
    }
}
