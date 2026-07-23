<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterStatusServiceArmorScalingTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_and_magical_armor_defense_use_the_non_regressing_formula(): void
    {
        $character = $this->character(2479, 1326);
        $armor = $this->armor(536, 656);
        $this->equip($character, $armor);

        $stats = $this->stats($character);

        $this->assertSame(3033, $stats['def']);
        $this->assertSame(1688, $stats['spr']);
        $this->assertSame(['def' => 536, 'spr' => 656], $stats['armor_defense']);
    }

    public function test_armor_base_includes_non_armor_equipment_but_excludes_the_armor(): void
    {
        $character = $this->character(1000, 0);
        $ring = Item::query()->create([
            'name' => '守りの指輪', 'type' => 'accessory', 'def_bonus' => 400,
            'accessory_performance_scale_version' => 2, 'is_active' => true,
        ]);
        $armor = $this->armor(800, 0);

        CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $ring->id,
            'is_equipped' => true, 'equipped_slot' => 'accessory',
        ]);
        $this->equip($character, $armor);

        $stats = $this->stats($character);

        $this->assertSame(1400, $stats['armor_base']['def']);
        $this->assertSame(1867, $stats['def']);
    }

    public function test_armor_enhancement_and_affixes_feed_only_the_armor_defense_input(): void
    {
        $character = $this->character(1000, 1000);
        $armor = $this->armor(800, 400);
        $this->equip($character, $armor, enhanceLevel: 5, affixDef: 80, affixSpr: 16);

        $stats = $this->stats($character);

        // +5後の防具DEFは920、保存済み銘80を加えた1000が共通式の入力になる。
        $this->assertSame(['def' => 1000, 'spr' => 476], $stats['armor_defense']);
        $this->assertSame(1417, $stats['def']);
        $this->assertSame(1198, $stats['spr']);
    }

    public function test_armor_preview_uses_the_common_formula_with_affix_stats(): void
    {
        $character = $this->character(1000, 1000);
        $armor = $this->armor(800, 400);
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $armor->id,
            'affix_def_bonus' => 80,
            'affix_spr_bonus' => 16,
        ]);
        $characterItem->load('item');
        CharacterStatusService::clearRequestCache($character->id);

        $preview = app(CharacterStatusService::class)->armorEffectivePreview($character, $characterItem);

        $this->assertSame(['def' => 1367, 'spr' => 1173], $preview);
    }

    public function test_armor_defense_never_falls_below_the_pre_scale_direct_addition(): void
    {
        $character = $this->character(200, 200);
        $armor = $this->armor(288, 192);
        $this->equip($character, $armor);

        $stats = $this->stats($character);

        // 防具8倍化前の+36/+24を必ず下回らない。
        $this->assertSame(236, $stats['def']);
        $this->assertSame(224, $stats['spr']);
    }

    public function test_unarmored_defense_preserves_the_existing_non_armor_base_stat(): void
    {
        $character = $this->character(1000, 1000);

        $stats = $this->stats($character);

        $this->assertSame(1000, $stats['def']);
        $this->assertSame(1000, $stats['spr']);
    }

    private function stats(Character $character): array
    {
        CharacterStatusService::clearRequestCache($character->id);

        return app(CharacterStatusService::class)->getFinalStats($character);
    }

    private function equip(Character $character, Item $item, int $enhanceLevel = 0, int $affixDef = 0, int $affixSpr = 0): CharacterItem
    {
        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'enhance_level' => $enhanceLevel,
            'affix_def_bonus' => $affixDef,
            'affix_spr_bonus' => $affixSpr,
        ]);
    }

    private function armor(int $defBonus, int $sprBonus): Item
    {
        return Item::query()->create([
            'name' => "テスト防具{$defBonus}-{$sprBonus}",
            'type' => 'armor',
            'armor_rank' => 'EPIC',
            'def_bonus' => $defBonus,
            'spr_bonus' => $sprBonus,
            'is_active' => true,
        ]);
    }

    private function character(int $defenseBase, int $spiritBase): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => 'テスト冒険者',
            'explore_stamina' => 0,
            'hp_base' => 1000, 'mp_base' => 0,
            'attack_base' => 0, 'defense_base' => $defenseBase,
            'speed_base' => 0, 'magic_base' => 0,
            'spirit_base' => $spiritBase, 'luck_base' => 0,
        ]);
    }
}
