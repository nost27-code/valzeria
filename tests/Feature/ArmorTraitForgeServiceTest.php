<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use App\Models\User;
use App\Services\EquipmentAffixRulesService;
use App\Services\WeaponTraitForgeService;
use App\Services\WeaponTraitTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ArmorTraitForgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_armor_resist_rates_scale_by_level_and_quality(): void
    {
        $rules = app(EquipmentAffixRulesService::class);
        $item = $this->armorItem('SS', 'heavy_armor');

        $this->assertEqualsWithDelta(0.05, $rules->armorSpeciesResistRate($item, 1, 'normal'), 0.0001);
        $this->assertEqualsWithDelta(0.10, $rules->armorSpeciesResistRate($item, 2, 'normal'), 0.0001);
        $this->assertEqualsWithDelta(0.25, $rules->armorSpeciesResistRate($item, 5, 'normal'), 0.0001);
        $this->assertEqualsWithDelta(0.3375, $rules->armorSpeciesResistRate($item, 5, 'excellent'), 0.0001);
        // ランク上限（AはIIIまで）を超える段階はクランプされる。
        $this->assertEqualsWithDelta(0.15, $rules->armorSpeciesResistRate($this->armorItem('A', 'heavy_armor'), 5, 'normal'), 0.0001);
    }

    public function test_slayer_forge_on_armor_raises_resist_level_and_recomputes_stored_rate(): void
    {
        $character = $this->createCharacter(500_000);
        $base = $this->armor($character, 'SS', 'heavy_armor', suffixLevel: 1);
        $material = $this->armor($character, 'SS', 'heavy_armor', suffixLevel: 1);

        $result = app(WeaponTraitForgeService::class)->forge($character, 'slayer_forge', $base->id, $material->id);

        $base->refresh();
        $this->assertSame(2, $base->affix_suffix_level);
        $this->assertEqualsWithDelta(0.10, (float) $base->species_damage_reduction_rate, 0.0001);
        $this->assertEqualsWithDelta(0.10, $base->effectiveSpeciesDamageReductionRate(), 0.0001);
        $this->assertStringContainsString('耐性磨き', $result['message']);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
    }

    public function test_forge_rejects_mixed_weapon_and_armor_pair(): void
    {
        $character = $this->createCharacter(500_000);
        $base = $this->armor($character, 'SS', 'heavy_armor', suffixLevel: 1);

        $weaponSuffix = EquipmentAffixSuffix::query()
            ->where('item_type', 'weapon')
            ->where('effect_type', 'killer_damage')
            ->firstOrFail();
        $weaponItem = Item::query()->create([
            'name' => '鍛錬用SS剣',
            'type' => 'weapon',
            'rarity' => 'SS',
            'weapon_category' => 'sword',
            'weapon_rank' => 'SS',
            'str_bonus' => 100,
            'is_active' => true,
        ]);
        $weapon = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weaponItem->id,
            'affix_prefix_id' => $this->prefix()->id,
            'affix_prefix_level' => 1,
            'affix_suffix_id' => $weaponSuffix->id,
            'affix_suffix_level' => 1,
            'affix_quality' => 'normal',
            'acquired_from' => 'drop',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('武器と防具をまたいで鍛錬できません。');
        app(WeaponTraitForgeService::class)->forge($character, 'slayer_forge', $base->id, $weapon->id);
    }

    public function test_forge_requires_same_armor_category(): void
    {
        $character = $this->createCharacter(500_000);
        $base = $this->armor($character, 'SS', 'heavy_armor', suffixLevel: 1);
        $material = $this->armor($character, 'SS', 'robe', suffixLevel: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('鍛錬には同じ防具種が必要です。');
        app(WeaponTraitForgeService::class)->forge($character, 'slayer_forge', $base->id, $material->id);
    }

    public function test_resist_transfer_between_armors_sets_resist_columns(): void
    {
        $character = $this->createCharacter(500_000);
        $base = $this->armor($character, 'SS', 'heavy_armor', suffix: null);
        $material = $this->armor($character, 'A', 'robe', suffixLevel: 3);

        $result = app(WeaponTraitTransferService::class)->transfer($character, 'slayer_transfer', $base->id, $material->id);

        $base->refresh();
        $this->assertSame(3, $base->affix_suffix_level);
        $this->assertSame('dragon', $base->resist_species_key);
        $this->assertEqualsWithDelta(0.15, (float) $base->species_damage_reduction_rate, 0.0001);
        $this->assertNull($base->killer_species_key);
        $this->assertStringContainsString('耐性移し', $result['message']);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
    }

    public function test_legacy_stored_rate_is_kept_when_higher_than_dynamic_rate(): void
    {
        $character = $this->createCharacter(0);
        // 段階制移行前の逸品I（8%固定）。動的算出は5%×1.35=6.75%なので保存値が優先される。
        $legacy = $this->armor($character, 'SS', 'heavy_armor', suffixLevel: 1, quality: 'excellent', storedRate: 0.08);

        $this->assertEqualsWithDelta(0.08, $legacy->effectiveSpeciesDamageReductionRate(), 0.0001);
    }

    private function createCharacter(int $money): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '耐性鍛錬テスト冒険者',
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }

    private function prefix(): EquipmentAffixPrefix
    {
        return EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();
    }

    private function resistSuffix(): EquipmentAffixSuffix
    {
        return EquipmentAffixSuffix::query()
            ->where('item_type', 'armor')
            ->where('effect_type', 'species_resist')
            ->where('species_key', 'dragon')
            ->firstOrFail();
    }

    private function armorItem(string $rank, string $category): Item
    {
        return Item::query()->create([
            'name' => "鍛錬用{$rank}鎧{$category}",
            'type' => 'armor',
            'rarity' => $rank,
            'armor_category' => $category,
            'armor_rank' => $rank,
            'def_bonus' => 80,
            'is_active' => true,
        ]);
    }

    private function armor(
        Character $character,
        string $rank,
        string $category,
        ?bool $suffix = true,
        int $suffixLevel = 1,
        string $quality = 'normal',
        ?float $storedRate = null,
    ): CharacterItem {
        $item = $this->armorItem($rank, $category);
        $resistSuffix = $suffix ? $this->resistSuffix() : null;

        return CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'affix_prefix_id' => $this->prefix()->id,
            'affix_prefix_level' => 1,
            'affix_suffix_id' => $resistSuffix?->id,
            'affix_suffix_level' => $resistSuffix ? $suffixLevel : 0,
            'affix_quality' => $quality,
            'resist_species_key' => $resistSuffix ? 'dragon' : null,
            'species_damage_reduction_rate' => $resistSuffix ? ($storedRate ?? 0.04) : 0,
            'acquired_from' => 'drop',
        ]);
    }
}
