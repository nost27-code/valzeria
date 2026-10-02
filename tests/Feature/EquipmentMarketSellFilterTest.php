<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\Item;
use App\Models\PlayerValmon;
use App\Models\ValmonMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentMarketSellFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('t', 32))]);
    }

    public function test_filters_combine_and_keep_individual_equipment_ids(): void
    {
        $character = $this->loginCharacter();
        $first = $this->equipment($character, '星の試験剣');
        $second = $this->equipment($character, '星の試験剣', [], ['enhance_level' => 2]);
        $this->equipment($character, '星の試験鎧', ['type' => 'armor', 'weapon_category' => null, 'weapon_rank' => null, 'armor_category' => 'heavy_armor', 'armor_rank' => 'S']);
        $this->equipment($character, '星の試験杖', ['weapon_category' => 'staff']);
        $this->equipment($character, '星の試験剣', ['weapon_rank' => 'A']);

        $response = $this->get(route('equipment-market.index', [
            'tab' => 'sell', 'sell_name' => '星の', 'sell_type' => 'weapon', 'sell_category' => 'sword', 'sell_rank' => 'S',
        ]));

        $response->assertOk()->assertViewHas('tab', 'sell')->assertViewHas('sellableTotal', 5);
        $this->assertSame([$second->id, $first->id], $response->viewData('sellable')->modelKeys());
        $response->assertSee('name="character_item_id" value="' . $first->id . '"', false)
            ->assertSee('name="character_item_id" value="' . $second->id . '"', false)
            ->assertSee('2件がヒット / 出品可能な装備 5件');

        $reset = $this->get(route('equipment-market.index', ['tab' => 'sell']));
        $reset->assertOk();
        $this->assertCount(5, $reset->viewData('sellable'));
    }

    public function test_armor_filters_use_armor_columns_and_do_not_use_purchase_filters(): void
    {
        $character = $this->loginCharacter();
        $armor = $this->equipment($character, '星の試験鎧', ['type' => 'armor', 'weapon_category' => null, 'weapon_rank' => null, 'armor_category' => 'heavy_armor', 'armor_rank' => 'A']);
        $this->equipment($character, '星の試験剣');
        $response = $this->get(route('equipment-market.index', [
            'tab' => 'sell', 'sell_type' => 'armor', 'sell_category' => 'heavy_armor', 'sell_rank' => 'A',
            'name' => '購入側の別条件', 'weapon_rank' => 'G',
        ]));
        $response->assertOk();
        $this->assertSame([$armor->id], $response->viewData('sellable')->modelKeys());
        $this->assertSame(['heavy_armor', 'sword'], $response->viewData('sellCategoryOptions')->all());
    }

    public function test_search_treats_wildcards_literally_and_can_find_engraving_names(): void
    {
        $character = $this->loginCharacter();
        $literal = $this->equipment($character, '試験%_!剣');
        $this->equipment($character, '試験普通剣');
        $response = $this->get(route('equipment-market.index', ['tab' => 'sell', 'sell_name' => '%_!']));
        $response->assertOk();
        $this->assertSame([$literal->id], $response->viewData('sellable')->modelKeys());

        $prefix = $literal->affixPrefix;
        $response = $this->get(route('equipment-market.index', ['tab' => 'sell', 'sell_name' => $prefix->name]));
        $response->assertOk();
        $this->assertCount(2, $response->viewData('sellable'));
    }

    public function test_search_preserves_eligibility_and_distinguishes_empty_results(): void
    {
        $character = $this->loginCharacter();
        $eligible = $this->equipment($character, '試験剣');
        foreach ([['is_equipped' => true], ['is_locked' => true], ['is_tradeable' => false], ['market_relistable_at' => now()->addHour()]] as $attributes) {
            $this->equipment($character, '試験剣', [], $attributes);
        }
        $other = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '別の冒険者']);
        $this->equipment($other, '試験剣');

        $response = $this->get(route('equipment-market.index', ['tab' => 'sell', 'sell_name' => '試験']));
        $response->assertOk()->assertViewHas('sellableTotal', 1);
        $this->assertSame([$eligible->id], $response->viewData('sellable')->modelKeys());
        $this->get(route('equipment-market.index', ['tab' => 'sell', 'sell_name' => '一致しない名前']))
            ->assertOk()->assertSee('条件に合う装備はありません。');
        $eligible->update(['is_locked' => true]);
        $this->get(route('equipment-market.index', ['tab' => 'sell']))
            ->assertOk()->assertSee('出品可能な銘・特攻・耐性付き装備はありません。');
    }

    public function test_search_keeps_selected_recipient_and_listing_scope(): void
    {
        $character = $this->loginCharacter();
        $this->equipment($character, '試験剣');
        $recipient = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '宛先冒険者']);
        $response = $this->get(route('equipment-market.index', [
            'tab' => 'sell', 'sell_name' => '試験', 'recipient_character_id' => $recipient->id,
            'market_scope_choice' => 'character',
        ]));
        $response->assertOk()->assertViewHas('sellListingScope', 'character')
            ->assertSee('name="recipient_character_id" value="' . $recipient->id . '"', false);
        $this->assertSame($recipient->id, $response->viewData('selectedRecipient')->id);
        $response->assertSee(route('equipment-market.index', [
            'recipient_character_id' => $recipient->id, 'market_scope_choice' => 'character', 'tab' => 'sell',
        ]));

        $this->get(route('equipment-market.index', ['tab' => 'sell', 'market_scope_choice' => 'nation']))
            ->assertOk()->assertViewHas('sellListingScope', 'all');
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->loginCharacter();
        $this->get(route('equipment-market.index', ['tab' => 'sell', 'sell_type' => 'accessory', 'sell_name' => ['bad']]))
            ->assertRedirect()->assertSessionHasErrors(['sell_type', 'sell_name']);
    }

    private function loginCharacter(): Character
    {
        $user = User::factory()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'name' => '市場検索冒険者', 'money' => 100_000]);
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'market-filter-test', 'name' => '市場試験モン', 'rarity' => 'normal', 'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id, 'valmon_master_id' => $master->id,
            'obtained_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);
        return $character;
    }

    private function equipment(Character $character, string $name, array $master = [], array $individual = []): CharacterItem
    {
        $item = Item::query()->create(array_merge([
            'name' => $name, 'type' => 'weapon', 'weapon_category' => 'sword', 'weapon_rank' => 'S',
            'str_bonus' => 100, 'is_active' => true, 'is_tradeable' => true,
        ], $master));
        return CharacterItem::query()->create(array_merge([
            'character_id' => $character->id, 'item_id' => $item->id,
            'affix_prefix_id' => EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail()->id,
            'affix_prefix_level' => 2, 'is_equipped' => false, 'is_locked' => false, 'is_tradeable' => true,
        ], $individual));
    }
}
