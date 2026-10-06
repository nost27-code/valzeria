<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_lost_materials_redirect_to_visible_error_without_charging_or_crafting(): void
    {
        $character = $this->player();
        $fragment = $this->stock($character, '魔物の欠片', 10);
        $powder = $this->stock($character, '妖精粉', 20);
        Item::create(['name' => '誘魔香〈精霊〉', 'type' => 'consumable', 'is_active' => true]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $before = $this->get(route('apothecary.index'))->assertOk();
        $this->saveHtml('pharmacy.html', $before->getContent());
        $fragment->update(['quantity' => 1]); // Material used after the displayed recipe was read.
        $this->from(route('apothecary.index'))->post(route('apothecary.craft'), ['recipe_code' => 'lure_spirit', 'count' => 1])
            ->assertRedirect(route('apothecary.index'))->assertSessionHas('error', '魔物の欠片が足りません。');
        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertSame(20, (int) $powder->fresh()->quantity);
        $this->assertDatabaseCount('character_items', 0);
        $this->assertDatabaseCount('gold_transactions', 0);
        $after = $this->get(route('apothecary.index'))->assertOk()->assertSee('魔物の欠片が足りません。')
            ->assertSee('data-facility-error', false)->assertSee('role="alert"', false)->assertSee('errorTarget: document.querySelector', false);
        $this->saveHtml('pharmacy-error.html', $after->getContent());
    }

    public function test_selling_last_matching_equipment_keeps_remaining_items_and_renders_filter_recovery(): void
    {
        $character = $this->player();
        $last = Item::create(['name' => '最後の確認剣', 'type' => 'weapon', 'weapon_rank' => 'C', 'sell_price' => 100]);
        $other = Item::create(['name' => '残る確認槍', 'type' => 'weapon', 'weapon_rank' => 'C', 'sell_price' => 100]);
        $sold = CharacterItem::create(['character_id' => $character->id, 'item_id' => $last->id]);
        $remaining = CharacterItem::create(['character_id' => $character->id, 'item_id' => $other->id]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $before = $this->get(route('inventory.index'))->assertOk();
        $this->saveHtml('equipment-before.html', $before->getContent());
        $this->post(route('equipment.sell', $sold), ['return_to_inventory' => 1])->assertRedirect(route('inventory.index'));
        $after = $this->get(route('inventory.index'))->assertOk()->assertViewHas('equipmentGroups', fn ($groups) => $groups['weapon']->pluck('id')->all() === [$remaining->id])->assertSee('残る確認槍')
            ->assertSee('条件に一致する品はありません。')->assertSee('絞り込み解除');
        $this->assertDatabaseHas('character_items', ['id' => $remaining->id]);
        $this->assertSame(1100, (int) $character->fresh()->money);
        $this->saveHtml('equipment-after.html', $after->getContent());
    }

    private function player(): Character
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '施設表示確認者', 'money' => 1000]);
        $master = ValmonMaster::create(['valmon_key' => 'feedback-test', 'name' => '確認モン', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);

        return $character;
    }

    private function stock(Character $character, string $name, int $quantity): CharacterMaterial
    {
        $material = Material::create(['material_code' => 'FEEDBACK_'.md5($name), 'name' => $name, 'category' => '素材', 'rarity' => 'COMMON', 'npc_sale_price' => 100]);

        return CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => $quantity]);
    }

    private function saveHtml(string $name, string $html): void
    {
        if ($directory = getenv('FACILITY_FEEDBACK_QA_DIR')) {
            file_put_contents($directory.'/'.$name, $html);
        }
    }
}
