<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\Item;
use App\Models\Material;
use App\Models\User;
use App\Services\EquipmentEnhancementService;
use App\Services\EquipmentEvolutionService;
use App\Services\ShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FacilityBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_shop_uses_only_the_hand_gold_shortfall_after_confirmation(): void
    {
        $character = $this->character(40, 100);
        $item = Item::query()->create([
            'name' => '銀行購入テスト剣',
            'type' => 'weapon',
            'rarity' => 'G',
            'weapon_category' => 'sword',
            'weapon_rank' => 'G',
            'price' => 100,
            'is_shop_item' => true,
            'is_active' => true,
        ]);

        $unconfirmed = app(ShopService::class)->buy($character, $item);
        $this->assertFalse($unconfirmed['success']);
        $this->assertSame('銀行預金を使う確認が必要です。', $unconfirmed['message']);
        $this->assertSame(40, (int) $character->fresh()->money);
        $this->assertSame(100, (int) $character->fresh()->bank_gold);
        $this->assertDatabaseMissing('character_items', ['character_id' => $character->id, 'item_id' => $item->id]);

        $result = app(ShopService::class)->buy($character, $item, 1, true);

        $character->refresh();
        $this->assertTrue($result['success']);
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(40, (int) $character->bank_gold);
        $this->assertDatabaseHas('character_items', ['character_id' => $character->id, 'item_id' => $item->id]);
        $this->assertPayment($character, 'shop_equipment_purchase', 100, 40, 60, 40);
    }

    public function test_equipment_enhancement_rolls_back_materials_until_bank_use_is_confirmed(): void
    {
        $character = $this->character(100, 300);
        $fragment = $this->material('MAT_ENHANCE_FRAGMENT', '強化石の欠片');
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $fragment->id,
            'quantity' => 5,
        ]);
        $item = Item::query()->create([
            'name' => '銀行強化テスト剣',
            'type' => 'weapon',
            'rarity' => 'G',
            'weapon_category' => 'sword',
            'weapon_rank' => 'G',
            'str_bonus' => 10,
            'is_active' => true,
        ]);
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'enhance_level' => 0,
            'is_equipped' => false,
            'acquired_from' => 'drop',
        ]);

        try {
            app(EquipmentEnhancementService::class)->enhance($character, $characterItem);
            $this->fail('銀行利用の確認なしで強化されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertSame(5, $this->materialQuantity($character, $fragment));
        $this->assertSame(0, (int) $characterItem->fresh()->enhance_level);

        $result = app(EquipmentEnhancementService::class)->enhance($character, $characterItem, true);

        $character->refresh();
        $this->assertSame(1, (int) $characterItem->fresh()->enhance_level);
        $this->assertSame(0, $this->materialQuantity($character, $fragment));
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(100, (int) $character->bank_gold);
        $this->assertStringContainsString('手持ち100G・銀行200G', $result['message']);
        $this->assertPayment($character, 'equipment_enhancement', 300, 100, 200, 100);
    }

    public function test_equipment_evolution_preserves_the_source_until_bank_use_is_confirmed(): void
    {
        $character = $this->character(40, 100);
        $from = $this->evolutionWeapon('BANK_EVO_FROM', '銀行合成元の剣', 'G');
        $to = $this->evolutionWeapon('BANK_EVO_TO', '銀行合成先の剣', 'F');
        DB::table('weapon_evolution_recipes')->insert([
            'recipe_id' => 'BANK_PAYMENT_EVOLUTION_TEST',
            'from_weapon_id' => $from->external_item_id,
            'from_weapon_name' => $from->name,
            'to_weapon_id' => $to->external_item_id,
            'to_weapon_name' => $to->name,
            'weapon_family_id' => 'BANK_PAYMENT_TEST',
            'category_id' => 'sword',
            'from_rank' => 'G',
            'to_rank' => 'F',
            'same_weapon_count' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $source = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $from->id,
            'enhance_level' => 0,
            'is_equipped' => false,
            'acquired_from' => 'drop',
        ]);

        try {
            app(EquipmentEvolutionService::class)->evolve(
                $character,
                'weapon',
                'BANK_PAYMENT_EVOLUTION_TEST',
                $source->id,
            );
            $this->fail('銀行利用の確認なしで合成されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertDatabaseHas('character_items', ['id' => $source->id, 'item_id' => $from->id]);
        $this->assertDatabaseMissing('character_items', ['character_id' => $character->id, 'item_id' => $to->id]);

        $result = app(EquipmentEvolutionService::class)->evolve(
            $character,
            'weapon',
            'BANK_PAYMENT_EVOLUTION_TEST',
            $source->id,
            true,
        );

        $character->refresh();
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(40, (int) $character->bank_gold);
        $this->assertDatabaseMissing('character_items', ['id' => $source->id]);
        $this->assertDatabaseHas('character_items', ['character_id' => $character->id, 'item_id' => $to->id]);
        $this->assertStringContainsString('手持ち40G・銀行60G', $result['message']);
        $this->assertPayment($character, 'equipment_evolution', 100, 40, 60, 40);
    }

    private function character(int $handGold, int $bankGold): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '施設銀行支払いテスト',
            'money' => $handGold,
            'bank_gold' => $bankGold,
            'explore_stamina' => 0,
        ]);
    }

    private function material(string $code, string $name): Material
    {
        return Material::query()->updateOrCreate(['material_code' => $code], [
            'name' => $name,
            'category' => '共通素材',
            'rarity' => 'N',
            'npc_sale_price' => 0,
            'is_tradable' => false,
        ]);
    }

    private function materialQuantity(Character $character, Material $material): int
    {
        return (int) (CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('material_id', $material->id)
            ->value('quantity') ?? 0);
    }

    private function evolutionWeapon(string $externalId, string $name, string $rank): Item
    {
        return Item::query()->create([
            'external_item_id' => $externalId,
            'name' => $name,
            'type' => 'weapon',
            'weapon_category' => 'sword',
            'weapon_family_id' => 'BANK_PAYMENT_TEST',
            'weapon_family_name' => '銀行合成試験剣',
            'weapon_rank' => $rank,
            'display_rank' => $rank,
            'rarity' => $rank,
            'is_active' => true,
            'str_bonus' => 10,
        ]);
    }

    private function assertPayment(
        Character $character,
        string $type,
        int $amount,
        int $handGoldUsed,
        int $bankGoldUsed,
        int $bankBalanceAfter,
    ): void {
        $transaction = GoldTransaction::query()
            ->where('character_id', $character->id)
            ->where('type', $type)
            ->sole();

        $this->assertSame(-$amount, (int) $transaction->amount);
        $this->assertSame($handGoldUsed, (int) $transaction->metadata['payment_hand_gold']);
        $this->assertSame($bankGoldUsed, (int) $transaction->metadata['payment_bank_gold']);
        $this->assertSame($bankBalanceAfter, (int) $transaction->metadata['bank_balance_after']);
    }
}
