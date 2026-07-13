<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use App\Models\User;
use App\Services\WeaponTraitTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WeaponTraitTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidates_include_the_item_name_protection_action_url_and_effect_lines(): void
    {
        $character = $this->createCharacter(100_000);
        [$power, , $dragon] = $this->affixes();
        $weapon = $this->weapon($character, 'S', 'sword', $power, 2, $dragon, 1);

        $candidates = app(WeaponTraitTransferService::class)->candidates($character);
        $payload = collect($candidates['engraving_transfer']['base_options'])->firstWhere('id', $weapon->id);

        $this->assertSame($weapon->item->name, $payload['item_name']);
        $this->assertSame(route('equipment.lock', $weapon), $payload['lock_url']);
        $this->assertContains('攻撃 +100', $payload['base_performance_lines']);
        $this->assertNotEmpty($payload['engraving_effect_lines']);
        $this->assertNotEmpty($payload['slayer_effect_lines']);
    }

    public function test_engraving_transfer_allows_different_weapon_categories_and_preserves_base_state(): void
    {
        $character = $this->createCharacter(300_000);
        [$power, $arcane, $dragon, $beast] = $this->affixes();
        $base = $this->weapon($character, 'SS', 'sword', $power, 4, $dragon, 3, true, false, 3, 'excellent');
        $base->market_relistable_at = now()->addHours(24);
        $base->save();
        $material = $this->weapon($character, 'A', 'staff', $arcane, 2, $beast, 1);

        $result = app(WeaponTraitTransferService::class)->transfer($character, 'engraving_transfer', $base->id, $material->id);

        $base->refresh();
        $this->assertSame($arcane->id, $base->affix_prefix_id);
        $this->assertSame(2, $base->affix_prefix_level);
        $this->assertSame($dragon->id, $base->affix_suffix_id);
        $this->assertSame(3, $base->affix_suffix_level);
        $this->assertSame('excellent', $base->affix_quality);
        $this->assertSame(3, $base->enhance_level);
        $this->assertTrue($base->is_equipped);
        $this->assertTrue($base->market_relistable_at->isFuture());
        $this->assertGreaterThan(0, $base->affix_mag_bonus);
        $this->assertSame(10_000, $result['gold_cost']);
        $this->assertSame(290_000, $character->fresh()->money);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
        $this->assertDatabaseHas('gold_transactions', ['character_id' => $character->id, 'type' => 'weapon_engraving_transfer', 'amount' => -10_000]);
        $this->assertDatabaseHas('weapon_trait_operation_logs', ['operation' => 'engraving_transfer', 'base_character_item_id' => $base->id, 'material_character_item_id' => $material->id]);
    }

    public function test_slayer_transfer_preserves_base_engraving(): void
    {
        $character = $this->createCharacter(100_000);
        [$power, $arcane, $dragon, $beast] = $this->affixes();
        $base = $this->weapon($character, 'S', 'bow', $power, 2, $dragon, 4, false, false, 1, 'good');
        $material = $this->weapon($character, 'A', 'staff', $arcane, 1, $beast, 3);

        $result = app(WeaponTraitTransferService::class)->transfer($character, 'slayer_transfer', $base->id, $material->id);

        $base->refresh();
        $this->assertSame($power->id, $base->affix_prefix_id);
        $this->assertSame(2, $base->affix_prefix_level);
        $this->assertSame($beast->id, $base->affix_suffix_id);
        $this->assertSame(3, $base->affix_suffix_level);
        $this->assertSame('beast', $base->killer_species_key);
        $this->assertSame(30_000, $result['gold_cost']);
        $this->assertSame(70_000, $character->fresh()->money);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
        $this->assertDatabaseHas('gold_transactions', ['character_id' => $character->id, 'type' => 'weapon_slayer_transfer', 'amount' => -30_000]);
    }

    public function test_transfer_rejects_a_lower_level_of_the_same_engraving_without_changing_state(): void
    {
        $character = $this->createCharacter(100_000);
        [$power, $arcane, $dragon] = $this->affixes();
        $base = $this->weapon($character, 'S', 'sword', $power, 3, $dragon, 1);
        $material = $this->weapon($character, 'A', 'staff', $power, 2, $dragon, 1);

        try {
            app(WeaponTraitTransferService::class)->transfer($character, 'engraving_transfer', $base->id, $material->id);
            $this->fail('同じ銘の低い段階は移せないはずです。');
        } catch (RuntimeException $exception) {
            $this->assertSame('同じ銘の段階を下げることはできません。', $exception->getMessage());
        }

        $this->assertSame(100_000, $character->fresh()->money);
        $this->assertDatabaseHas('character_items', ['id' => $base->id, 'affix_prefix_level' => 3]);
        $this->assertDatabaseHas('character_items', ['id' => $material->id, 'affix_prefix_level' => 2]);
        $this->assertDatabaseCount('weapon_trait_operation_logs', 0);
    }

    public function test_transfer_rejects_overwriting_an_existing_trait_on_a_locked_base_weapon(): void
    {
        $character = $this->createCharacter(100_000);
        [$power, $arcane, $dragon] = $this->affixes();
        $base = $this->weapon($character, 'S', 'sword', $power, 2, $dragon, 1, false, true);
        $material = $this->weapon($character, 'A', 'staff', $arcane, 3, $dragon, 1);

        try {
            app(WeaponTraitTransferService::class)->transfer($character, 'engraving_transfer', $base->id, $material->id);
            $this->fail('保護中の既存銘は上書きできないはずです。');
        } catch (RuntimeException $exception) {
            $this->assertSame('保護中の武器に付いている銘は上書きできません。', $exception->getMessage());
        }

        $this->assertSame(100_000, $character->fresh()->money);
        $this->assertDatabaseHas('character_items', ['id' => $material->id]);
    }

    public function test_transfer_rejects_a_level_above_the_base_weapon_rank_cap(): void
    {
        $character = $this->createCharacter(300_000);
        [$power, $arcane, $dragon] = $this->affixes();
        $base = $this->weapon($character, 'A', 'sword', $power, 2, $dragon, 1);
        $material = $this->weapon($character, 'SS', 'staff', $arcane, 4, $dragon, 1);

        try {
            app(WeaponTraitTransferService::class)->transfer($character, 'engraving_transfer', $base->id, $material->id);
            $this->fail('Aランク武器は銘IVを保持できないはずです。');
        } catch (RuntimeException $exception) {
            $this->assertSame('Aランク武器が保持できる銘段階はIIIまでです。', $exception->getMessage());
        }

        $this->assertSame(300_000, $character->fresh()->money);
        $this->assertDatabaseHas('character_items', ['id' => $material->id]);
    }

    private function createCharacter(int $money): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '移しテスト冒険者',
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }

    /**
     * @return array{EquipmentAffixPrefix, EquipmentAffixPrefix, EquipmentAffixSuffix, EquipmentAffixSuffix}
     */
    private function affixes(): array
    {
        return [
            EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail(),
            EquipmentAffixPrefix::query()->where('affix_key', 'arcane')->firstOrFail(),
            EquipmentAffixSuffix::query()->where('item_type', 'weapon')->where('effect_type', 'killer_damage')->where('species_key', 'dragon')->firstOrFail(),
            EquipmentAffixSuffix::query()->where('item_type', 'weapon')->where('effect_type', 'killer_damage')->where('species_key', 'beast')->firstOrFail(),
        ];
    }

    private function weapon(
        Character $character,
        string $rank,
        string $category,
        EquipmentAffixPrefix $prefix,
        int $prefixLevel,
        EquipmentAffixSuffix $suffix,
        int $suffixLevel,
        bool $equipped = false,
        bool $locked = false,
        int $enhanceLevel = 0,
        string $quality = 'normal',
    ): CharacterItem {
        $item = Item::query()->create([
            'name' => "移し用{$rank}{$category}",
            'type' => 'weapon',
            'rarity' => $rank,
            'weapon_category' => $category,
            'weapon_rank' => $rank,
            'str_bonus' => 100,
            'is_active' => true,
        ]);

        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'affix_prefix_id' => $prefix->id,
            'affix_prefix_level' => $prefixLevel,
            'affix_suffix_id' => $suffix->id,
            'affix_suffix_level' => $suffixLevel,
            'affix_quality' => $quality,
            'killer_species_key' => $suffix->species_key,
            'killer_damage_rate' => 0.06,
            'is_equipped' => $equipped,
            'is_locked' => $locked,
            'enhance_level' => $enhanceLevel,
            'equipped_slot' => $equipped ? 'weapon' : null,
            'acquired_from' => 'drop',
        ]);
    }
}
