<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterStatusServiceWeaponScalingTest extends TestCase
{
    use RefreshDatabase;

    public function test_physical_and_magical_weapon_offense_use_the_same_common_formula(): void
    {
        $character = $this->character(2479, 1326);
        $weapon = $this->weapon(536, 656);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        $this->assertSame(2537, $stats['str']);
        $this->assertSame(1423, $stats['mag']);
        $this->assertSame(['str' => 536, 'mag' => 656], $stats['weapon_offense']);
    }

    public function test_weapon_base_includes_non_weapon_equipment_but_excludes_the_weapon(): void
    {
        $character = $this->character(1000, 0);
        $ring = Item::query()->create([
            'name' => '力の指輪', 'type' => 'accessory', 'str_bonus' => 400,
            'accessory_performance_scale_version' => 2, 'is_active' => true,
        ]);
        $weapon = $this->weapon(800, 0);

        CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $ring->id,
            'is_equipped' => true, 'equipped_slot' => 'accessory',
        ]);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        $this->assertSame(1400, $stats['weapon_base']['str']);
        $this->assertSame(1587, $stats['str']);
        $this->assertSame(787, $stats['bonuses']['str']);
    }

    public function test_weapon_enhancement_is_included_in_weapon_offense_only(): void
    {
        $character = $this->character(1000, 0);
        $weapon = $this->weapon(800, 0);
        $this->equip($character, $weapon, enhanceLevel: 5);

        $stats = $this->stats($character);

        // +5 は武器攻撃800を920へ増やし、共通式の武器能力へだけ使う。
        $this->assertSame(920, $stats['weapon_offense']['str']);
        $this->assertSame(1183, $stats['str']);
        $this->assertSame(0, $stats['def']);
    }

    public function test_weapon_and_legacy_affix_non_offense_stats_are_applied_after_their_eightfold_scale(): void
    {
        $character = $this->character(0, 0);
        $weapon = Item::query()->create([
            'name' => '全能力試験武器', 'type' => 'weapon',
            // 移行後の武器本体値。
            'hp_bonus' => 80, 'mp_bonus' => 40, 'def_bonus' => 32,
            'agi_bonus' => 24, 'spr_bonus' => 16, 'luk_bonus' => 8,
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $weapon->id,
            'is_equipped' => true, 'equipped_slot' => 'weapon',
            // 旧方式で保存済みだった銘補正も、移行後は全能力値が8倍化済み。
            'affix_hp_bonus' => 16, 'affix_def_bonus' => 8, 'affix_agi_bonus' => 8,
            'affix_spr_bonus' => 8, 'affix_luk_bonus' => 8,
        ]);

        $stats = $this->stats($character);

        $this->assertSame(1096, $stats['max_hp']);
        $this->assertSame(40, $stats['max_mp']);
        $this->assertSame(40, $stats['def']);
        $this->assertSame(32, $stats['agi']);
        $this->assertSame(24, $stats['spr']);
        $this->assertSame(16, $stats['luk']);
    }

    public function test_weapon_preview_uses_the_common_formula_with_affix_stats(): void
    {
        $character = $this->character(1000, 1000);
        $weapon = $this->weapon(800, 400);
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weapon->id,
            'affix_str_bonus' => 80,
            'affix_mag_bonus' => 16,
        ]);
        $characterItem->load('item');
        CharacterStatusService::clearRequestCache($character->id);

        $preview = app(CharacterStatusService::class)->weaponEffectivePreview($character, $characterItem);

        $this->assertSame(['str' => 1167, 'mag' => 973], $preview);
    }

    public function test_unarmed_offense_is_eighty_percent_of_the_weaponless_base_stat(): void
    {
        $character = $this->character(1000, 1000);

        $stats = $this->stats($character);

        $this->assertSame(800, $stats['str']);
        $this->assertSame(800, $stats['mag']);
        $this->assertSame(0, $stats['bonuses']['str']);
        $this->assertSame(0, $stats['bonuses']['mag']);
    }

    private function stats(Character $character): array
    {
        CharacterStatusService::clearRequestCache($character->id);

        return app(CharacterStatusService::class)->getFinalStats($character);
    }

    private function equip(Character $character, Item $item, int $enhanceLevel = 0): CharacterItem
    {
        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'enhance_level' => $enhanceLevel,
        ]);
    }

    private function weapon(int $strBonus, int $magBonus): Item
    {
        return Item::query()->create([
            'name' => "テスト武器{$strBonus}-{$magBonus}",
            'type' => 'weapon',
            'weapon_rank' => 'EPIC',
            'str_bonus' => $strBonus,
            'mag_bonus' => $magBonus,
            'is_active' => true,
        ]);
    }

    private function character(int $attackBase, int $magicBase): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => 'テスト冒険者',
            'explore_stamina' => 0,
            'hp_base' => 1000, 'mp_base' => 0,
            'attack_base' => $attackBase, 'defense_base' => 0,
            'speed_base' => 0, 'magic_base' => $magicBase,
            'spirit_base' => 0, 'luck_base' => 0,
        ]);
    }
}
