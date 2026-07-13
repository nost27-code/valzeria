<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\Item;
use App\Models\User;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterStatusServiceWeaponScalingTest extends TestCase
{
    use RefreshDatabase;

    public function test_proportional_bonus_is_based_on_pre_equipment_main_stat(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 0);
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        $this->assertSame(1000, $stats['pre_equipment']['str']);
        // 500(固定) + floor(1000 * 0.16)(比例) = 660 の装備ボーナス
        $this->assertSame(1660, $stats['str']);
    }

    public function test_final_stats_do_not_depend_on_equipped_item_order(): void
    {
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $accessory = Item::query()->create([
            'name' => '力の指輪', 'type' => 'accessory', 'str_bonus' => 50, 'is_active' => true,
        ]);

        $characterFirst = $this->character(attackBase: 1000, magicBase: 0, name: '武器先');
        $this->equip($characterFirst, $weapon);
        CharacterItem::query()->create([
            'character_id' => $characterFirst->id, 'item_id' => $accessory->id,
            'is_equipped' => true, 'equipped_slot' => 'accessory',
        ]);

        $characterSecond = $this->character(attackBase: 1000, magicBase: 0, name: '装飾先');
        CharacterItem::query()->create([
            'character_id' => $characterSecond->id, 'item_id' => $accessory->id,
            'is_equipped' => true, 'equipped_slot' => 'accessory',
        ]);
        $this->equip($characterSecond, $weapon);

        $statsFirst = $this->stats($characterFirst);
        $statsSecond = $this->stats($characterSecond);

        $this->assertSame(1710, $statsFirst['str']);
        $this->assertSame($statsFirst['str'], $statsSecond['str']);
    }

    public function test_only_one_weapon_can_be_equipped_at_a_time(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 0);
        $weaponA = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $weaponB = $this->weaponItem('EPIC', strBonus: 800, magBonus: 0);

        $service = app(\App\Services\EquipmentService::class);
        $itemA = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $weaponA->id]);
        $itemB = CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $weaponB->id]);

        $this->assertTrue($service->equip($character, $itemA)['success']);
        $this->assertTrue($service->equip($character, $itemB)['success']);

        $itemA->refresh();
        $itemB->refresh();

        $this->assertFalse((bool) $itemA->is_equipped);
        $this->assertTrue((bool) $itemB->is_equipped);
        $this->assertSame(
            1,
            CharacterItem::query()->where('character_id', $character->id)->where('is_equipped', true)->where('equipped_slot', 'weapon')->count()
        );
    }

    public function test_physical_weapon_scales_str_only(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 1000);
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        $this->assertSame(1660, $stats['str']);
        $this->assertSame(1000, $stats['mag']); // 魔力は変化しない
    }

    public function test_magical_weapon_scales_mag_only(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 1000);
        $weapon = $this->weaponItem('EPIC', strBonus: 0, magBonus: 500);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        $this->assertSame(1000, $stats['str']); // 攻撃は変化しない
        $this->assertSame(1660, $stats['mag']);
    }

    public function test_compound_weapon_distributes_proportional_bonus_by_fixed_stat_ratio(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 1000);
        // str:mag = 300:100 = 3:1
        $weapon = $this->weaponItem('EPIC', strBonus: 300, magBonus: 100);
        $this->equip($character, $weapon);

        $stats = $this->stats($character);

        // str: 300(固定) + floor(1000 * 0.16 * 0.75) = 300+120 = 420 増加
        $this->assertSame(1420, $stats['str']);
        // mag: 100(固定) + floor(1000 * 0.16 * 0.25) = 100+40 = 140 増加
        $this->assertSame(1140, $stats['mag']);
    }

    public function test_enhancement_only_scales_the_fixed_part_not_the_proportional_part(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 0);
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);

        $baseline = $this->equipAndGetStr($character, $weapon, enhanceLevel: 0);
        $this->unequipAll($character);
        $enhanced = $this->equipAndGetStr($character, $weapon, enhanceLevel: 5);

        // +5鍛冶: 500 + max(5, floor(500*0.03*5)) = 500+75 = 575（固定部のみ+75）
        $this->assertSame(75, $enhanced - $baseline);
    }

    public function test_engraving_bonus_does_not_multiply_the_proportional_part(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 0);
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();

        $baseline = $this->equipAndGetStr($character, $weapon, enhanceLevel: 0);
        $this->unequipAll($character);
        $withEngraving = $this->equipAndGetStr($character, $weapon, enhanceLevel: 0, prefix: $prefix, prefixLevel: 3);

        // 銘V未満のIII・通常品質: ceil(500 * 0.18 * 1.00) = 90 が固定加算されるだけ
        $this->assertSame(90, $withEngraving - $baseline);
    }

    public function test_proportional_bonus_is_recomputed_after_job_change_resets_base_stats(): void
    {
        $character = $this->character(attackBase: 1000, magicBase: 0);
        $weapon = $this->weaponItem('EPIC', strBonus: 500, magBonus: 0);
        $this->equip($character, $weapon);

        $before = $this->stats($character);
        $this->assertSame(1660, $before['str']);

        // 転職を模して基礎攻撃を大きく引き下げる
        $character->attack_base = 200;
        $character->save();
        CharacterStatusService::clearRequestCache($character->id);

        $after = $this->stats($character);
        // 200(基礎) + 500(固定) + floor(200*0.16)=32(比例) = 732
        $this->assertSame(732, $after['str']);
        $this->assertSame(200, $after['pre_equipment']['str']);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Character $character): array
    {
        CharacterStatusService::clearRequestCache($character->id);

        return app(CharacterStatusService::class)->getFinalStats($character);
    }

    private function equipAndGetStr(Character $character, Item $weapon, int $enhanceLevel = 0, ?EquipmentAffixPrefix $prefix = null, int $prefixLevel = 1): int
    {
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weapon->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'enhance_level' => $enhanceLevel,
            'affix_prefix_id' => $prefix?->id,
            'affix_prefix_level' => $prefix ? $prefixLevel : 1,
        ]);

        return (int) $this->stats($character)['str'];
    }

    private function unequipAll(Character $character): void
    {
        CharacterItem::query()->where('character_id', $character->id)->delete();
    }

    private function equip(Character $character, Item $item): CharacterItem
    {
        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => true,
            'equipped_slot' => $item->type,
        ]);
    }

    private function weaponItem(string $rank, int $strBonus, int $magBonus): Item
    {
        return Item::query()->create([
            'name' => "テスト武器{$rank}-{$strBonus}-{$magBonus}",
            'type' => 'weapon',
            'weapon_rank' => $rank,
            'str_bonus' => $strBonus,
            'mag_bonus' => $magBonus,
            'is_active' => true,
        ]);
    }

    private function character(int $attackBase, int $magicBase, string $name = 'テスト冒険者'): Character
    {
        $user = User::factory()->create();

        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 0,
            'hp_base' => 1000, 'mp_base' => 0,
            'attack_base' => $attackBase, 'defense_base' => 0,
            'speed_base' => 0, 'magic_base' => $magicBase,
            'spirit_base' => 0, 'luck_base' => 0,
        ]);

        CharacterStatusService::clearRequestCache($character->id);

        return $character;
    }
}
