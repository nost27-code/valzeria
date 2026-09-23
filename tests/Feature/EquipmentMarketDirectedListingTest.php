<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\EquipmentMarketListing;
use App\Models\NationMembership;
use App\Models\User;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\EquipmentMarketService;
use App\Services\Nation\NationService;
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
        $this->withoutVite();
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
            ->assertSee('冒険者を検索')
            ->assertSee('placeholder="冒険者名を入力"', false)
            ->assertSee('data-equipment-recipient-search', false)
            ->assertSee($recipient->name)
            ->assertSee('宛先にする')
            ->assertSee($recipient->name.'の冒険者カードを見る')
            ->assertSee('data-equipment-recipient-candidate="'.$recipient->id.'"', false)
            ->assertSee('data-equipment-recipient-avatar="'.$recipient->id.'"', false)
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false);

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index', [
                'tab' => 'sell',
                'recipient_character_id' => $recipient->id,
            ]))
            ->assertOk()
            ->assertSee('宛先')
            ->assertSee($recipient->name.'さん')
            ->assertSee($recipient->name.'の冒険者カードを見る')
            ->assertSee('data-equipment-recipient-selected-card="'.$recipient->id.'"', false)
            ->assertSee('data-equipment-recipient-avatar="'.$recipient->id.'"', false)
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false);

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

    public function test_sell_tab_shows_recent_adventurers_before_recipient_search(): void
    {
        $seller = $this->character('現在候補の閲覧者', 100_000);
        $recent = $this->character('現在活動中の冒険者', 2_000_000);
        $stale = $this->character('活動終了済みの冒険者', 2_000_000);
        $recent->forceFill(['last_seen_at' => now()->subMinute()])->saveQuietly();
        $stale->forceFill(['last_seen_at' => now()->subMinutes(6)])->saveQuietly();

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index', ['tab' => 'sell']))
            ->assertOk()
            ->assertSee('現在の冒険者')
            ->assertSee('直近5分')
            ->assertSee($recent->name)
            ->assertSee('data-equipment-recipient-candidate="'.$recent->id.'"', false)
            ->assertDontSee('data-equipment-recipient-candidate="'.$seller->id.'"', false)
            ->assertDontSee($stale->name);
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

    public function test_nation_listing_uses_the_listing_nation_and_current_membership(): void
    {
        config()->set('features.nation_community_enabled', true);
        $seller = $this->character('国家出品者', 100_000);
        $member = $this->character('同国の購入者', 2_000_000);
        $outsider = $this->character('他国の購入者', 2_000_000);
        $nation = app(NationService::class)->create($seller, '市場共有');
        NationMembership::create(['nation_id' => $nation->id, 'character_id' => $member->id, 'role' => 'citizen', 'joined_at' => now()]);
        $characterItem = $this->marketableEquipment($seller);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];

        $this->actingAs($seller->user)
            ->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.index', ['tab' => 'sell']))
            ->assertOk()->assertSee('国家限定')->assertSee($nation->display_name);
        $this->post(route('equipment-market.store'), [
            'character_item_id' => $characterItem->id,
            'listing_price' => $price,
            'listing_scope' => 'nation',
        ])->assertRedirect(route('equipment-market.index', ['tab' => 'listings']));
        $listing = EquipmentMarketListing::query()->where('character_item_id', $characterItem->id)->sole();
        $this->assertSame($nation->id, $listing->nation_id_snapshot);
        $this->assertSame($seller->id, $listing->recipient_character_id);
        $this->assertFalse(EquipmentMarketListing::query()
            ->whereKey($listing->id)
            ->where(fn ($query) => $query->whereNull('recipient_character_id')->orWhere('recipient_character_id', $member->id))
            ->exists());
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.equipment-market.index'))
            ->assertOk()
            ->assertSee('国家限定：'.$nation->display_name);

        $this->actingAs($member->user)->withSession(['current_character_id' => $member->id])
            ->get(route('equipment-market.index'))->assertOk()->assertSee($listing->display_name_snapshot);
        $this->get(route('equipment-market.show', $listing))->assertOk()->assertSee('国家限定');
        $this->get(route('shops.show', $listing->shop_id))->assertOk()->assertSee($listing->display_name_snapshot);

        $this->actingAs($outsider->user)->withSession(['current_character_id' => $outsider->id])
            ->get(route('equipment-market.index'))->assertOk()->assertDontSee($listing->display_name_snapshot);
        $this->get(route('equipment-market.show', $listing))->assertNotFound();
        $this->get(route('shops.show', $listing->shop_id))->assertOk()->assertDontSee($listing->display_name_snapshot);
        try {
            app(EquipmentMarketService::class)->buyEquipment($outsider, $listing);
            $this->fail('国家外の冒険者が購入できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertSame('この出品は購入できません。', $exception->getMessage());
        }

        NationMembership::query()->where('character_id', $member->id)->delete();
        $this->actingAs($member->user)->withSession(['current_character_id' => $member->id])
            ->get(route('equipment-market.show', $listing))->assertNotFound();
        try {
            app(EquipmentMarketService::class)->buyEquipment($member, $listing);
            $this->fail('脱退した冒険者が購入できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertSame('この出品は購入できません。', $exception->getMessage());
        }
        $this->actingAs($seller->user)->withSession(['current_character_id' => $seller->id])
            ->get(route('equipment-market.show', $listing))->assertOk();

        NationMembership::create(['nation_id' => $nation->id, 'character_id' => $outsider->id, 'role' => 'citizen', 'joined_at' => now()]);
        $transaction = app(EquipmentMarketService::class)->buyEquipment($outsider, $listing);
        $this->assertSame($outsider->id, $transaction->buyer_character_id);
    }

    public function test_nation_listing_requires_membership(): void
    {
        config()->set('features.nation_community_enabled', true);
        $seller = $this->character('無所属出品者', 100_000);
        $characterItem = $this->marketableEquipment($seller);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem)['appraisal_price'];

        $this->actingAs($seller->user)->withSession(['current_character_id' => $seller->id])
            ->post(route('equipment-market.store'), [
                'character_item_id' => $characterItem->id,
                'listing_price' => $price,
                'listing_scope' => 'nation',
            ])->assertRedirect(route('equipment-market.index', ['tab' => 'sell']))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('equipment_market_listings', 0);
    }

    public function test_armor_rank_uses_the_weapon_style_prefix_and_old_snapshots_display_it(): void
    {
        $armor = Item::query()->create(['name' => '試験鎧', 'type' => 'armor', 'armor_rank' => 'A']);
        $characterItem = new CharacterItem();
        $characterItem->setRelation('item', $armor);
        $this->assertSame('[A] 試験鎧', $characterItem->displayName());

        $oldListing = new EquipmentMarketListing([
            'display_name_snapshot' => '試験鎧',
            'weapon_rank' => 'A',
            'item_snapshot' => ['item_type' => 'armor'],
        ]);
        $this->assertSame('[A] 試験鎧', $oldListing->displayNameWithRank());
        $oldListing->display_name_snapshot = '[A] 試験鎧';
        $this->assertSame('[A] 試験鎧', $oldListing->displayNameWithRank());
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
