<?php

namespace Tests\Feature;

use App\Http\Controllers\EquipmentController;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\EquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EquipmentWarehouseBulkSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_equipment_can_be_sold_together(): void
    {
        [$user, $character] = $this->createPlayer();
        $first = $this->createEquipment($character, '一括売却の剣', 450);
        $second = $this->createEquipment($character, '一括売却の鎧', 700, 'armor');

        $response = $this->withoutMiddleware()
            ->actingAs($user)
            ->postJson(route('equipment.bulk-sell'), [
                'character_item_ids' => [$first->id, $second->id],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'count' => 2,
                'amount' => 1150,
            ]);

        $this->assertDatabaseMissing('character_items', ['id' => $first->id]);
        $this->assertDatabaseMissing('character_items', ['id' => $second->id]);
        $this->assertDatabaseHas('characters', ['id' => $character->id, 'money' => 2150]);
        $this->assertDatabaseCount('gold_transactions', 2);
    }

    public function test_protected_equipment_rolls_back_the_entire_bulk_sale(): void
    {
        [$user, $character] = $this->createPlayer();
        $sellable = $this->createEquipment($character, '売却可能な剣', 450);
        $protected = $this->createEquipment($character, '保護された鎧', 700, 'armor', true);

        $response = $this->withoutMiddleware()
            ->actingAs($user)
            ->postJson(route('equipment.bulk-sell'), [
                'character_item_ids' => [$sellable->id, $protected->id],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'message' => '保護中の装備は売却できません。先に星印を解除してください。',
            ]);

        $this->assertDatabaseHas('character_items', ['id' => $sellable->id]);
        $this->assertDatabaseHas('character_items', ['id' => $protected->id, 'is_locked' => true]);
        $this->assertDatabaseHas('characters', ['id' => $character->id, 'money' => 1000]);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_equipment_lock_endpoint_toggles_the_protection_star(): void
    {
        [$user, $character] = $this->createPlayer();
        $equipment = $this->createEquipment($character, '保護切替の剣', 450);

        $this->actingAs($user);
        $request = Request::create(route('equipment.lock', $equipment), 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = app(EquipmentController::class)->toggleLock(
            $equipment,
            app(EquipmentService::class),
            $request
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertSame($equipment->id, $payload['character_item_id']);
        $this->assertTrue($payload['is_locked']);
        $this->assertFalse($payload['can_sell']);

        $this->assertDatabaseHas('character_items', [
            'id' => $equipment->id,
            'is_locked' => true,
        ]);
    }

    private function createPlayer(): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '倉庫テスト冒険者',
            'money' => 1000,
            'explore_stamina' => 0,
        ]);

        return [$user, $character];
    }

    private function createEquipment(
        Character $character,
        string $name,
        int $sellPrice,
        string $type = 'weapon',
        bool $locked = false
    ): CharacterItem {
        $item = Item::query()->create([
            'name' => $name,
            'type' => $type,
            'sell_price' => $sellPrice,
            'weapon_rank' => $type === 'weapon' ? 'C' : null,
            'armor_rank' => $type === 'armor' ? 'C' : null,
            'is_active' => true,
        ]);

        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'is_locked' => $locked,
        ]);
    }
}
