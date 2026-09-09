<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\EquipmentMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class EquipmentMarketDirectedListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $this->withoutMiddleware(CheckCharacterSelected::class);
    }

    public function test_directed_listing_is_visible_and_buyable_only_by_its_recipient(): void
    {
        $seller = $this->character('宛先出品者', 100_000);
        $recipient = $this->character('宛先購入者', 2_000_000);
        $stranger = $this->character('第三者', 2_000_000);
        $characterItem = $this->marketableEquipment($seller);
        $service = app(EquipmentMarketService::class);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];

        $listing = $service->listEquipment($seller, $characterItem, $price, $recipient);

        $this->assertSame($recipient->id, $listing->recipient_character_id);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $recipient->id,
            'type' => 'equipment_market_directed_listing',
        ]);

        $this->actingAs($recipient->user)
            ->withSession(['current_character_id' => $recipient->id])
            ->get(route('equipment-market.index'))
            ->assertOk()
            ->assertSee($listing->display_name_snapshot)
            ->assertSee('あなた宛て');

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index'))
            ->assertOk()
            ->assertSee($listing->display_name_snapshot)
            ->assertSee("宛先：{$recipient->name}さん");

        $this->actingAs($stranger->user)
            ->withSession(['current_character_id' => $stranger->id])
            ->get(route('equipment-market.index'))
            ->assertOk()
            ->assertDontSee($listing->display_name_snapshot);

        $this->actingAs($stranger->user)
            ->withSession(['current_character_id' => $stranger->id])
            ->get(route('equipment-market.show', $listing))
            ->assertNotFound();

        $this->assertNotNull($listing->shop_id);
        $this->actingAs($recipient->user)
            ->withSession(['current_character_id' => $recipient->id])
            ->get(route('shops.show', $listing->shop_id))
            ->assertOk()
            ->assertSee($listing->display_name_snapshot)
            ->assertSee('あなた宛て');

        $this->actingAs($stranger->user)
            ->withSession(['current_character_id' => $stranger->id])
            ->get(route('shops.show', $listing->shop_id))
            ->assertOk()
            ->assertDontSee($listing->display_name_snapshot);

        try {
            $service->buyEquipment($stranger, $listing);
            $this->fail('宛先ではないキャラクターが購入できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertSame('この出品は購入できません。', $exception->getMessage());
        }

        $transaction = $service->buyEquipment($recipient, $listing);

        $this->assertSame($recipient->id, $transaction->buyer_character_id);
        $this->assertSame($recipient->id, $characterItem->fresh()->character_id);
        $this->assertSame('sold', $listing->fresh()->status);
    }

    public function test_seller_cannot_designate_themselves_as_recipient(): void
    {
        $seller = $this->character('自分宛出品者', 100_000);
        $characterItem = $this->marketableEquipment($seller);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('自分自身を宛先には指定できません。');

        app(EquipmentMarketService::class)->listEquipment($seller, $characterItem, $price, $seller);
    }

    public function test_seller_can_search_and_submit_a_directed_listing_from_the_market_screen(): void
    {
        $seller = $this->character('画面出品者', 100_000);
        $recipient = $this->character('宛先候補冒険者', 2_000_000);
        $characterItem = $this->marketableEquipment($seller);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index', [
                'tab' => 'sell',
                'recipient_search' => '宛先候補',
            ]))
            ->assertOk()
            ->assertSee($recipient->name);

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->post(route('equipment-market.store'), [
                'character_item_id' => $characterItem->id,
                'listing_price' => $price,
                'recipient_character_id' => $recipient->id,
            ])
            ->assertRedirect(route('equipment-market.index', ['tab' => 'listings']));

        $this->assertDatabaseHas('equipment_market_listings', [
            'seller_character_id' => $seller->id,
            'recipient_character_id' => $recipient->id,
            'character_item_id' => $characterItem->id,
            'status' => 'active',
        ]);
    }

    public function test_deleted_recipient_does_not_make_a_directed_listing_public(): void
    {
        $seller = $this->character('削除時出品者', 100_000);
        $recipient = $this->character('削除予定宛先', 2_000_000);
        $stranger = $this->character('削除後第三者', 2_000_000);
        $characterItem = $this->marketableEquipment($seller);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];
        $listing = app(EquipmentMarketService::class)->listEquipment($seller, $characterItem, $price, $recipient);

        $recipient->delete();

        $this->assertSame($recipient->id, $listing->fresh()->recipient_character_id);
        $this->actingAs($stranger->user)
            ->withSession(['current_character_id' => $stranger->id])
            ->get(route('equipment-market.index'))
            ->assertOk()
            ->assertDontSee($listing->display_name_snapshot);

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index'))
            ->assertOk()
            ->assertSee($listing->display_name_snapshot);
    }

    private function character(string $name, int $money): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }

    private function marketableEquipment(Character $owner): CharacterItem
    {
        $item = Item::query()->create([
            'name' => '宛先指定試験剣',
            'type' => 'weapon',
            'weapon_category' => 'sword',
            'weapon_rank' => 'S',
            'str_bonus' => 100,
            'is_active' => true,
            'is_tradeable' => true,
            'innate_killer_species_key' => 'dragon',
            'innate_killer_damage_rate' => 0.12,
        ]);

        return CharacterItem::query()->create([
            'character_id' => $owner->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'is_locked' => false,
            'is_tradeable' => true,
        ]);
    }
}
