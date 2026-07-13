<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use App\Models\User;
use App\Services\WeaponTraitForgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WeaponTraitForgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_engraving_forge_consumes_only_material_and_preserves_base_state(): void
    {
        $character = $this->createCharacter(500_000);
        [$prefix, $suffix] = $this->affixes();
        $base = $this->weapon($character, 'A', 'sword', $prefix, 2, $suffix, 1, true, true, 3, 'excellent');
        $material = $this->weapon($character, 'A', 'sword', $prefix, 2, $suffix, 1);

        $result = app(WeaponTraitForgeService::class)->forge($character, 'engraving_forge', $base->id, $material->id);

        $base->refresh();
        $this->assertSame(3, $base->affix_prefix_level);
        $this->assertSame(1, $base->affix_suffix_level);
        $this->assertTrue($base->is_equipped);
        $this->assertTrue($base->is_locked);
        $this->assertSame(3, $base->enhance_level);
        $this->assertSame(80_000, $result['gold_cost']);
        $this->assertSame(420_000, $character->fresh()->money);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
        $this->assertDatabaseHas('weapon_trait_operation_logs', [
            'character_id' => $character->id,
            'operation' => 'engraving_forge',
            'base_character_item_id' => $base->id,
            'material_character_item_id' => $material->id,
            'gold_cost' => 80_000,
        ]);
        $operationLog = \App\Models\WeaponTraitOperationLog::query()->firstOrFail();
        $this->assertSame($prefix->name, data_get($operationLog->before_snapshot, 'engraving_name'));
        $this->assertSame($suffix->name, data_get($operationLog->material_snapshot, 'slayer_name'));
    }

    public function test_dual_forge_can_raise_different_matching_levels_together(): void
    {
        $character = $this->createCharacter(500_000);
        [$prefix, $suffix] = $this->affixes();
        $base = $this->weapon($character, 'S', 'sword', $prefix, 3, $suffix, 2);
        $material = $this->weapon($character, 'S', 'sword', $prefix, 3, $suffix, 2);

        $result = app(WeaponTraitForgeService::class)->forge($character, 'dual_forge', $base->id, $material->id);

        $base->refresh();
        $this->assertSame(4, $base->affix_prefix_level);
        $this->assertSame(3, $base->affix_suffix_level);
        $this->assertSame(264_000, $result['gold_cost']);
        $this->assertSame(236_000, $character->fresh()->money);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
    }

    public function test_forge_rejects_result_above_the_rank_cap_without_changing_state(): void
    {
        $character = $this->createCharacter(500_000);
        [$prefix, $suffix] = $this->affixes();
        $base = $this->weapon($character, 'A', 'sword', $prefix, 3, $suffix, 1);
        $material = $this->weapon($character, 'A', 'sword', $prefix, 3, $suffix, 1);

        try {
            app(WeaponTraitForgeService::class)->forge($character, 'engraving_forge', $base->id, $material->id);
            $this->fail('Aランク武器は銘IVへ鍛錬できないはずです。');
        } catch (RuntimeException $exception) {
            $this->assertSame('完成後の銘IVにはSランク以上の武器が必要です。', $exception->getMessage());
        }

        $this->assertSame(500_000, $character->fresh()->money);
        $this->assertDatabaseHas('character_items', ['id' => $base->id, 'affix_prefix_level' => 3]);
        $this->assertDatabaseHas('character_items', ['id' => $material->id, 'affix_prefix_level' => 3]);
        $this->assertDatabaseCount('weapon_trait_operation_logs', 0);
    }

    private function createCharacter(int $money): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '鍛錬テスト冒険者',
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }

    /**
     * @return array{EquipmentAffixPrefix, EquipmentAffixSuffix}
     */
    private function affixes(): array
    {
        return [
            EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail(),
            EquipmentAffixSuffix::query()
                ->where('item_type', 'weapon')
                ->where('effect_type', 'killer_damage')
                ->where('species_key', 'dragon')
                ->firstOrFail(),
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
            'name' => "鍛錬用{$rank}剣",
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
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.06,
            'is_equipped' => $equipped,
            'is_locked' => $locked,
            'enhance_level' => $enhanceLevel,
            'equipped_slot' => $equipped ? 'weapon' : null,
            'acquired_from' => 'drop',
        ]);
    }
}
