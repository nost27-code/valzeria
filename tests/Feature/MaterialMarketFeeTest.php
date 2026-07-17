<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\MarketTransaction;
use App\Models\Material;
use App\Models\User;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialMarketFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_material_sale_charges_five_percent_only_when_sold(): void
    {
        $seller = $this->createCharacter('出品者', 100);
        $buyer = $this->createCharacter('購入者', 1_000);
        $material = Material::query()->create([
            'material_code' => 'TEST_MARKET_FEE',
            'name' => '市場手数料テスト素材',
            'category' => 'テスト',
            'rarity' => 'N',
            'npc_sale_price' => 10,
            'is_tradable' => true,
            'trade_policy' => 'marketable',
            'market_min_price' => 10,
            'market_max_price' => 100,
            'is_key_item' => false,
            'is_cash_item' => false,
        ]);
        CharacterMaterial::query()->create([
            'character_id' => $seller->id,
            'material_id' => $material->id,
            'quantity' => 1,
        ]);

        $service = app(MarketService::class);
        $listing = $service->listMaterial($seller, $material, 1, 100);

        $seller->refresh();
        $this->assertSame(0, $listing->listing_fee);
        $this->assertSame(100, $seller->money);

        $service->buyMaterial($buyer, $material, 1);

        $seller->refresh();
        $buyer->refresh();
        $transaction = MarketTransaction::query()->sole();

        $this->assertSame(5, $transaction->sale_fee);
        $this->assertSame(95, $transaction->seller_received);
        $this->assertSame(195, $seller->money);
        $this->assertSame(900, $buyer->money);
    }

    private function createCharacter(string $name, int $money): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }
}
