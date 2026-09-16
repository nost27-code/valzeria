<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketDemandNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_demand_board_can_be_opened_after_listing_a_material(): void
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '需要板確認用',
        ]);
        $valmonMaster = ValmonMaster::query()->create([
            'valmon_key' => 'market-demand-navigation',
            'name' => '需要板確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);
        $material = Material::query()->create([
            'material_code' => 'TEST_DEMAND_NAVIGATION',
            'name' => '需要板確認素材',
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
            'character_id' => $character->id,
            'material_id' => $material->id,
            'quantity' => 2,
        ]);

        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);

        $this->post(route('market.materials.list'), [
            'material_id' => $material->id,
            'quantity' => 1,
            'unit_price' => 10,
        ])->assertRedirect(route('market.index', ['tab' => 'listings']));

        $listingsPage = $this->get(route('market.index', ['tab' => 'listings']))->assertOk();
        $this->assertSame(
            1,
            preg_match(
                '/<a\b[^>]*href="'.preg_quote(route('market.index', ['tab' => 'demand']), '/').'"[^>]*>\s*需要板\s*<\/a>/u',
                $listingsPage->getContent()
            ),
            '出品後の画面から需要板を再取得できるリンクが必要です。'
        );

        $this->get(route('market.index', ['tab' => 'demand']))
            ->assertOk()
            ->assertSee('需要板確認素材')
            ->assertDontSee('現在、表示できる素材がありません。');
    }
}
