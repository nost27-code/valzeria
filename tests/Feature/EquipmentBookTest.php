<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\CharacterEquipmentDiscovery;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class EquipmentBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_book_is_not_accessible_while_disabled(): void
    {
        config(['extra_content.contents.equipment_book.default_enabled' => false]);
        [$user, $character] = $this->player();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('equipment-book.index'))
            ->assertNotFound();
    }

    public function test_enabled_equipment_book_is_listed_in_adventurer_records(): void
    {
        config(['extra_content.contents.equipment_book.default_enabled' => true]);

        $menuMethod = new ReflectionMethod(MainScreen::class, 'homeMenuItems');
        $menuItems = $menuMethod->invoke(new MainScreen);
        $equipmentBook = collect($menuItems)->firstWhere('name', '装備図鑑');

        $this->assertSame('記録', $equipmentBook['group'] ?? null);
        $this->assertSame('equipment-book.index', $equipmentBook['route'] ?? null);
        $this->assertSame('icon/icon_277.webp', $equipmentBook['icon_image'] ?? null);
    }

    public function test_enabled_equipment_book_shows_weapon_tree_and_records_owned_item(): void
    {
        config(['extra_content.contents.equipment_book.default_enabled' => true]);
        [$user, $character] = $this->player();
        $base = $this->weapon('TEST_BOOK_G', '試作の剣', 'G', 101);
        $branchA = $this->weapon('TEST_BOOK_S_A', '暁の試作剣', 'S', 102);
        $branchB = $this->weapon('TEST_BOOK_S_B', '宵の試作剣', 'S', 103);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $base->id,
            'is_equipped' => false,
        ]);
        $this->weaponRecipe('TEST_BOOK_RECIPE_A', $base, $branchA);
        $this->weaponRecipe('TEST_BOOK_RECIPE_B', $base, $branchB);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('equipment-book.index', ['type' => 'weapon', 'chart' => 'weapon-' . $base->id]))
            ->assertOk()
            ->assertSee('装備の進化系譜')
            ->assertSee('試作の剣')
            ->assertSee('？？？')
            ->assertSee('武器図鑑')
            ->assertSee('防具図鑑')
            ->assertSee('準備中')
            ->assertSee('装備をタップすると性能を確認できます。')
            ->assertSee('data-equipment-detail-modal', false)
            ->assertSee('items-center justify-center', false)
            ->assertSee('h-36 w-36', false)
            ->assertSee('h-28 w-28', false);

        $this->assertDatabaseHas('character_equipment_discoveries', [
            'character_id' => $character->id,
            'item_id' => $base->id,
        ]);
        $this->assertDatabaseMissing('character_equipment_discoveries', [
            'character_id' => $character->id,
            'item_id' => $branchA->id,
        ]);
    }

    public function test_armor_book_request_falls_back_to_weapon_book_while_armor_is_in_preparation(): void
    {
        config(['extra_content.contents.equipment_book.default_enabled' => true]);
        [$user, $character] = $this->player();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('equipment-book.index', ['type' => 'armor']))
            ->assertOk()
            ->assertSee('防具図鑑')
            ->assertSee('準備中')
            ->assertSee('name="type" value="weapon"', false)
            ->assertDontSee('name="type" value="armor"', false);
    }

    public function test_discovery_remains_after_equipment_is_removed(): void
    {
        config(['extra_content.contents.equipment_book.default_enabled' => true]);
        [, $character] = $this->player();
        $item = $this->weapon('TEST_BOOK_KEEP', '記録に残る剣', 'G', 104);
        $owned = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
        ]);

        $this->assertDatabaseHas('character_equipment_discoveries', [
            'character_id' => $character->id,
            'item_id' => $item->id,
        ]);

        $owned->delete();

        $this->assertTrue(CharacterEquipmentDiscovery::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->exists());
    }

    private function player(): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '装備図鑑テスト',
        ]);
        $valmon = ValmonMaster::query()->create([
            'valmon_key' => 'equipment-book-test',
            'name' => '図鑑モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmon->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character];
    }

    private function weapon(string $externalId, string $name, string $rank, int $sortOrder): Item
    {
        return Item::query()->create([
            'external_item_id' => $externalId,
            'name' => $name,
            'type' => 'weapon',
            'weapon_category' => 'sword',
            'weapon_family_id' => 'TEST_BOOK_SWORD',
            'weapon_family_name' => '試作剣',
            'weapon_rank' => $rank,
            'display_rank' => $rank,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'str_bonus' => 8,
        ]);
    }

    private function weaponRecipe(string $recipeId, Item $from, Item $to): void
    {
        DB::table('weapon_evolution_recipes')->insert([
            'recipe_id' => $recipeId,
            'from_weapon_id' => $from->external_item_id,
            'from_weapon_name' => $from->name,
            'to_weapon_id' => $to->external_item_id,
            'to_weapon_name' => $to->name,
            'weapon_family_id' => 'TEST_BOOK_SWORD',
            'category_id' => 'sword',
            'from_rank' => $from->weapon_rank,
            'to_rank' => $to->weapon_rank,
            'same_weapon_count' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
