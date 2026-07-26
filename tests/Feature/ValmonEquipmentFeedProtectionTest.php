<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonFeedLog;
use App\Models\ValmonMaster;
use App\Services\ValmonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValmonEquipmentFeedProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_feed_candidates_show_item_book_category_and_rarity(): void
    {
        [$user, $character] = $this->createCharacterAndValmon();
        $material = Material::create([
            'material_code' => 'TEST_FEED_CITY_MATERIAL',
            'name' => '餌表示試験素材',
            'category' => '地域素材',
            'material_type' => 'city_material',
            'rarity' => 'R',
        ]);
        CharacterMaterial::create([
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => 3,
        ]);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))
            ->assertOk()
            ->assertSee('餌表示試験素材')
            ->assertSee('都市素材')
            ->assertSee('レアリティが高い順')
            ->assertSee('data-material-feed-rarity="R"', false);
    }

    public function test_market_listed_equipment_is_not_shown_as_a_feed_candidate(): void
    {
        [$user, $character, $valmon] = $this->createCharacterAndValmon();
        $listedItem = $this->createEquipment($character, '出品中の餌試験剣', 12345);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('valmons.index'))
            ->assertOk()
            ->assertDontSee($listedItem->item->name);
    }

    public function test_market_listed_equipment_cannot_be_fed_directly(): void
    {
        [, $character, $valmon] = $this->createCharacterAndValmon();
        $listedItem = $this->createEquipment($character, '直接餌試験剣', 12345);

        $result = app(ValmonService::class)->feedEquipment($character, $valmon, $listedItem);

        $this->assertFalse($result['success']);
        $this->assertSame('この装備はヴァルモンの餌にできません。', $result['message']);
        $this->assertDatabaseHas('character_items', ['id' => $listedItem->id, 'market_listing_id' => 12345]);
        $this->assertSame(0, (int) $valmon->fresh()->exp);
        $this->assertSame(0, ValmonFeedLog::query()->count());
    }

    public function test_unlisted_equipment_can_still_be_fed_directly(): void
    {
        [, $character, $valmon] = $this->createCharacterAndValmon();
        $normalItem = $this->createEquipment($character, '通常直接餌試験剣');

        $result = app(ValmonService::class)->feedEquipment($character, $valmon, $normalItem);

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('character_items', ['id' => $normalItem->id]);
        $this->assertSame(2, (int) $valmon->fresh()->exp);
        $this->assertDatabaseHas('valmon_feed_logs', [
            'character_id' => $character->id,
            'feed_type' => 'equipment',
            'feed_id' => $normalItem->id,
            'gained_exp' => 2,
        ]);
    }

    public function test_bulk_feed_rejects_all_equipment_when_a_market_listed_item_is_included(): void
    {
        [, $character, $valmon] = $this->createCharacterAndValmon();
        $normalItem = $this->createEquipment($character, '通常餌試験剣');
        $listedItem = $this->createEquipment($character, '一括出品中餌試験剣', 12345);

        $result = app(ValmonService::class)->feedEquipmentBulk($character, $valmon, [
            $normalItem->id,
            $listedItem->id,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('餌にできない装備が含まれています。', $result['message']);
        $this->assertDatabaseHas('character_items', ['id' => $normalItem->id]);
        $this->assertDatabaseHas('character_items', ['id' => $listedItem->id, 'market_listing_id' => 12345]);
        $this->assertSame(0, (int) $valmon->fresh()->exp);
        $this->assertSame(0, ValmonFeedLog::query()->count());
    }

    public function test_bulk_feed_still_consumes_unlisted_equipment(): void
    {
        [, $character, $valmon] = $this->createCharacterAndValmon();
        $firstItem = $this->createEquipment($character, '一括通常餌試験剣1');
        $secondItem = $this->createEquipment($character, '一括通常餌試験剣2');

        $result = app(ValmonService::class)->feedEquipmentBulk($character, $valmon, [
            $firstItem->id,
            $secondItem->id,
        ]);

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('character_items', ['id' => $firstItem->id]);
        $this->assertDatabaseMissing('character_items', ['id' => $secondItem->id]);
        $this->assertSame(4, (int) $valmon->fresh()->exp);
        $this->assertDatabaseHas('valmon_feed_logs', [
            'character_id' => $character->id,
            'feed_type' => 'equipment_bulk',
            'feed_id' => $firstItem->id,
            'quantity' => 2,
            'gained_exp' => 4,
        ]);
    }

    private function createCharacterAndValmon(): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '装備餌保護テスト',
        ]);
        $master = ValmonMaster::create([
            'valmon_key' => 'equipment-feed-protection',
            'name' => '餌保護モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        $valmon = PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character, $valmon];
    }

    private function createEquipment(Character $character, string $name, ?int $marketListingId = null): CharacterItem
    {
        $item = Item::create([
            'name' => $name,
            'type' => 'weapon',
            'weapon_rank' => 'G',
            'is_active' => true,
        ]);

        return CharacterItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'is_locked' => false,
            'market_listing_id' => $marketListingId,
        ])->load('item');
    }
}
