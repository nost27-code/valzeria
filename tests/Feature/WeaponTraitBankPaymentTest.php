<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\GoldTransaction;
use App\Models\Item;
use App\Models\User;
use App\Services\WeaponTraitForgeService;
use App\Services\WeaponTraitTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WeaponTraitBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_trait_forge_uses_the_bank_shortfall_only_after_confirmation(): void
    {
        $character = $this->character(40, 100_000);
        [$power, , $dragon] = $this->affixes();
        $base = $this->weapon($character, 'A', 'sword', $power, 2, $dragon, 1);
        $material = $this->weapon($character, 'A', 'sword', $power, 2, $dragon, 1);

        try {
            app(WeaponTraitForgeService::class)->forge($character, 'engraving_forge', $base->id, $material->id);
            $this->fail('銀行利用の確認なしで鍛錬されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertDatabaseHas('character_items', ['id' => $material->id]);
        $result = app(WeaponTraitForgeService::class)->forge($character, 'engraving_forge', $base->id, $material->id, true);

        $character->refresh();
        $this->assertSame(80_000, $result['gold_cost']);
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(20_040, (int) $character->bank_gold);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
        $this->assertPayment($character, 'weapon_trait_forge', 40, 79_960, 20_040);
    }

    public function test_trait_transfer_can_use_the_bank_shortfall(): void
    {
        $character = $this->character(40, 20_000);
        [$power, $arcane, $dragon] = $this->affixes();
        $base = $this->weapon($character, 'A', 'sword', $power, 2, $dragon, 1);
        $material = $this->weapon($character, 'A', 'staff', $arcane, 2, $dragon, 1);

        $result = app(WeaponTraitTransferService::class)->transfer(
            $character,
            'engraving_transfer',
            $base->id,
            $material->id,
            true,
        );

        $character->refresh();
        $this->assertSame(10_000, $result['gold_cost']);
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(10_040, (int) $character->bank_gold);
        $this->assertDatabaseMissing('character_items', ['id' => $material->id]);
        $this->assertPayment($character, 'weapon_engraving_transfer', 40, 9_960, 10_040);
    }

    private function character(int $handGold, int $bankGold): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '鍛冶銀行支払いテスト',
            'money' => $handGold,
            'bank_gold' => $bankGold,
            'explore_stamina' => 0,
        ]);
    }

    private function affixes(): array
    {
        return [
            EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail(),
            EquipmentAffixPrefix::query()->where('affix_key', 'arcane')->firstOrFail(),
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
    ): CharacterItem {
        $item = Item::query()->create([
            'name' => "鍛冶銀行用{$rank}{$category}",
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
            'affix_quality' => 'normal',
            'killer_species_key' => $suffix->species_key,
            'killer_damage_rate' => 0.06,
            'is_equipped' => false,
            'is_locked' => false,
            'enhance_level' => 0,
            'acquired_from' => 'drop',
        ]);
    }

    private function assertPayment(
        Character $character,
        string $type,
        int $handGoldUsed,
        int $bankGoldUsed,
        int $bankBalanceAfter,
    ): void {
        $transaction = GoldTransaction::query()
            ->where('character_id', $character->id)
            ->where('type', $type)
            ->sole();

        $this->assertSame($handGoldUsed, (int) $transaction->metadata['payment_hand_gold']);
        $this->assertSame($bankGoldUsed, (int) $transaction->metadata['payment_bank_gold']);
        $this->assertSame($bankBalanceAfter, (int) $transaction->metadata['bank_balance_after']);
    }
}
