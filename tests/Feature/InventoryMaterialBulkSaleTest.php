<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\GoldService;
use App\Services\MapExplorationItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InventoryMaterialBulkSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_sale_returns_json_and_preserves_per_material_ledger(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 3, 20);
        $payload = $this->payload($first, 2, $second, 3);

        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk()->assertJson([
            'success' => true, 'count' => 2, 'quantity' => 5, 'amount' => 80, 'money' => 1080,
            'sales' => [
                ['character_material_id' => $first->id, 'remaining_quantity' => 8],
                ['character_material_id' => $second->id, 'remaining_quantity' => 0],
            ],
        ])->assertJsonStructure(['next_request_uuid']);
        $this->assertSame(8, (int) $first->fresh()->quantity);
        $this->assertNull($second->fresh());
        $logs = GoldTransaction::orderBy('id')->get();
        $this->assertSame([20, 60], $logs->pluck('amount')->all());
        $this->assertSame([1020, 1080], $logs->pluck('balance_after')->all());
        $this->assertSame($payload['request_uuid'], $logs[0]->metadata['bulk_sale_uuid']);
    }

    public function test_retry_of_committed_sale_does_not_sell_remaining_stock_again(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        $payload = $this->payload($first, 2, $second, 3);
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk();
        $payload['sales'] = array_reverse($payload['sales']);
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk()->assertJson(['amount' => 80, 'money' => 1080]);
        $this->assertSame(8, (int) $first->fresh()->quantity);
        $this->assertSame(7, (int) $second->fresh()->quantity);
        $this->assertSame(1080, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 2);
    }

    public function test_retry_also_works_when_sold_stock_rows_have_been_deleted(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 1, 10);
        $second = $this->stock($character, 1, 20);
        $payload = $this->payload($first, 1, $second, 1);
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk();
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk()->assertJson(['money' => 1030]);
        $this->assertDatabaseCount('character_materials', 0);
        $this->assertDatabaseCount('gold_transactions', 2);
    }

    public function test_same_operation_number_with_changed_payload_is_rejected(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        $payload = $this->payload($first, 2, $second, 3);
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk();
        $payload['sales'][0]['quantity'] = 1;
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertUnprocessable()->assertJson(['success' => false]);
        $this->assertSame(1080, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 2);
    }

    public function test_insufficient_stock_rolls_back_earlier_sale_and_ledger(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 1, 20);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $second, 3))
            ->assertUnprocessable()->assertJson(['message' => '売却する素材数が不足しています。']);
        $this->assertUnchanged($character, $first, 10, $second, 1);
    }

    public function test_important_material_is_rejected_even_when_it_has_a_price(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $key = $this->stock($character, 2, 20, ['material_type' => 'weapon_unlock_key']);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $key, 1))
            ->assertUnprocessable()->assertJson(['message' => '大事なものは売却できません。']);
        $this->assertUnchanged($character, $first, 10, $key, 2);
        $this->expectExceptionMessage('大事なものは売却できません。');
        app(GoldService::class)->sellMaterial($character, $key, 1);
    }

    public function test_foreign_stock_and_current_zero_price_do_not_allow_partial_sale(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $foreign = $this->stock(Character::create(['user_id' => User::factory()->create()->id, 'name' => '別の冒険者']), 2, 20);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $foreign, 1))->assertUnprocessable();
        $this->assertUnchanged($character, $first, 10, $foreign, 2);
        $second = $this->stock($character, 2, 20);
        $second->material->update(['npc_sale_price' => 0]);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $second, 1))
            ->assertUnprocessable()->assertJson(['message' => 'この素材は売却できません。']);
        $this->assertUnchanged($character, $first, 10, $second, 2);
    }

    public function test_duplicate_ids_and_invalid_quantities_are_validation_errors(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        foreach ([0, -1, 1.5] as $quantity) {
            $this->postJson(route('inventory.bulk-sell'), $this->payload($first, $quantity, $first, 1))
                ->assertUnprocessable()->assertJsonValidationErrors(['sales.0.quantity', 'sales.0.character_material_id']);
        }
        $this->assertSame(10, (int) $first->fresh()->quantity);
        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_second_ledger_failure_rolls_back_all_assets_and_allows_retry(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        $payload = $this->payload($first, 2, $second, 3);
        $real = new GoldService();
        $service = Mockery::mock(GoldService::class)->makePartial();
        $calls = 0;
        $service->shouldReceive('record')->andReturnUsing(function (...$args) use (&$calls, $real) {
            if (++$calls === 2) {
                throw new RuntimeException('injected ledger failure');
            }
            return $real->record(...$args);
        });
        try {
            $service->sellMaterialsBulk($character, $payload['sales'], $payload['request_uuid']);
            $this->fail('Expected ledger failure');
        } catch (RuntimeException $e) {
            $this->assertSame('injected ledger failure', $e->getMessage());
        }
        $this->assertUnchanged($character, $first, 10, $second, 10);
        $this->postJson(route('inventory.bulk-sell'), $payload)->assertOk()->assertJson(['amount' => 80]);
    }

    public function test_stale_character_balance_is_reloaded_under_the_lock(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        Character::whereKey($character->id)->update(['money' => 2000]);
        $payload = $this->payload($first, 2, $second, 3);
        $result = app(GoldService::class)->sellMaterialsBulk($character, $payload['sales'], $payload['request_uuid']);
        $this->assertSame(2080, $result['money']);
        $this->assertSame(2080, (int) $character->fresh()->money);
    }

    public function test_guests_cannot_sell_materials(): void
    {
        $this->postJson(route('inventory.bulk-sell'), ['request_uuid' => (string) Str::uuid(), 'sales' => []])->assertRedirect('/');
    }

    public function test_active_map_rejects_bulk_sale_with_a_visible_json_reason(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        $maps = Mockery::mock(MapExplorationItemService::class)->makePartial();
        $maps->shouldReceive('restoreActiveSession')->once()->andReturn(new TownMapRegistration(['id' => 1]));
        $this->app->instance(MapExplorationItemService::class, $maps);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $second, 3))
            ->assertUnprocessable()->assertJson(['message' => '探索中の地図を切り上げてから行ってください。']);
        $this->assertUnchanged($character, $first, 10, $second, 10);
    }

    public function test_current_price_is_used_and_returned_for_the_next_selection(): void
    {
        $character = $this->player();
        $first = $this->stock($character, 10, 10);
        $second = $this->stock($character, 10, 20);
        $first->material->update(['npc_sale_price' => 30]);
        $this->postJson(route('inventory.bulk-sell'), $this->payload($first, 2, $second, 3))
            ->assertOk()->assertJson(['amount' => 120, 'money' => 1120, 'sales' => [
                ['character_material_id' => $first->id, 'unit_price' => 30, 'remaining_quantity' => 8],
                ['character_material_id' => $second->id, 'unit_price' => 20, 'remaining_quantity' => 7],
            ]]);
    }

    private function player(): Character
    {
        $user = User::factory()->create();
        $character = Character::create(['user_id' => $user->id, 'name' => '素材売却確認者', 'money' => 1000]);
        $master = ValmonMaster::create(['valmon_key' => 'bulk-sale-test', 'name' => '確認モン', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);
        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);
        return $character;
    }

    private function stock(Character $character, int $quantity, int $price, array $attributes = []): CharacterMaterial
    {
        $material = Material::create([
            'material_code' => 'BULK_TEST_'.Str::random(10), 'name' => '売却試験素材', 'category' => '素材', 'rarity' => 'COMMON',
            'npc_sale_price' => $price, ...$attributes,
        ]);
        return CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => $quantity]);
    }

    private function payload(CharacterMaterial $first, int|float $firstQuantity, CharacterMaterial $second, int $secondQuantity): array
    {
        return ['request_uuid' => (string) Str::uuid(), 'sales' => [
            ['character_material_id' => $first->id, 'quantity' => $firstQuantity],
            ['character_material_id' => $second->id, 'quantity' => $secondQuantity],
        ]];
    }

    private function assertUnchanged(Character $character, CharacterMaterial $first, int $firstQuantity, CharacterMaterial $second, int $secondQuantity): void
    {
        $this->assertSame($firstQuantity, (int) $first->fresh()->quantity);
        $this->assertSame($secondQuantity, (int) $second->fresh()->quantity);
        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
    }
}
