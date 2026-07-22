<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerValmonEgg;
use App\Models\ShopEggListing;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ShopEggListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerShopEggListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_stored_egg_is_listed_sold_and_transferred_atomically(): void
    {
        $seller = $this->character('売り手', 0);
        $buyer = $this->character('買い手', 1000);
        $egg = $this->egg($seller);

        $service = app(ShopEggListingService::class);
        $listing = $service->list($seller, $egg, 400, 48);
        $service->buy($buyer, $listing);

        $this->assertDatabaseHas('player_shops', ['character_id' => $seller->id]);
        $this->assertDatabaseHas('shop_egg_listings', ['id' => $listing->id, 'status' => 'sold', 'buyer_character_id' => $buyer->id]);
        $this->assertSame($buyer->id, $egg->fresh()->character_id);
        $this->assertSame(600, $buyer->fresh()->money);
        $this->assertSame(400, $seller->fresh()->money);
    }

    public function test_cancelled_egg_can_be_listed_again(): void
    {
        $seller = $this->character('再出品者', 0);
        $egg = $this->egg($seller);
        $service = app(ShopEggListingService::class);
        $first = $service->list($seller, $egg, 100, 12);
        $service->cancel($seller, $first);
        $second = $service->list($seller, $egg, 200, 24);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('active', ShopEggListing::findOrFail($second->id)->status);
    }

    private function character(string $name, int $money): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => $name, 'money' => $money]);
    }

    private function egg(Character $character): PlayerValmonEgg
    {
        $master = ValmonMaster::create(['valmon_key' => 'shop-egg-' . $character->id, 'name' => '商店テストモン', 'rarity' => 'normal', 'is_active' => true]);
        return PlayerValmonEgg::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'found_at' => now(), 'stored_at' => now()]);
    }
}
