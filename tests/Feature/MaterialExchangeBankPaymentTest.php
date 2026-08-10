<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\GoldTransaction;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MaterialExchangeBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_shortfall_requires_confirmation_and_is_not_charged_without_it(): void
    {
        [$character, $recipe, $materials] = $this->prepareFragmentSynthesis(40, 100);

        $this->assertTrue($recipe['can_exchange']);
        $this->assertSame(0, $recipe['missing_gold']);

        try {
            app(MaterialExchangeService::class)->exchange($character, $recipe['id']);
            $this->fail('銀行利用の確認なしで交換されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $character->refresh();
        $this->assertSame(40, (int) $character->money);
        $this->assertSame(100, (int) $character->bank_gold);
        $this->assertSame(4, $this->ownedQuantity($character, $materials['fang']));
        $this->assertSame(2, $this->ownedQuantity($character, $materials['ore']));
        $this->assertSame(0, GoldTransaction::query()->where('character_id', $character->id)->count());
    }

    public function test_only_the_hand_gold_shortfall_is_paid_from_the_bank(): void
    {
        [$character, $recipe, $materials] = $this->prepareFragmentSynthesis(40, 100);

        $result = app(MaterialExchangeService::class)->exchange($character, $recipe['id'], 1, true);

        $character->refresh();
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(40, (int) $character->bank_gold);
        $this->assertSame(1, $this->ownedQuantity($character, $materials['target']));
        $this->assertSame(0, $this->ownedQuantity($character, $materials['fang']));
        $this->assertSame(0, $this->ownedQuantity($character, $materials['ore']));
        $this->assertStringContainsString('手持ち40G・銀行60G', $result['message']);

        $transaction = GoldTransaction::query()
            ->where('character_id', $character->id)
            ->where('type', 'material_exchange')
            ->sole();
        $this->assertSame(-100, (int) $transaction->amount);
        $this->assertSame(40, (int) $transaction->metadata['payment_hand_gold']);
        $this->assertSame(60, (int) $transaction->metadata['payment_bank_gold']);
        $this->assertSame(40, (int) $transaction->metadata['bank_balance_after']);
    }

    public function test_combined_gold_shortage_prevents_exchange_without_consuming_materials(): void
    {
        [$character, $recipe, $materials] = $this->prepareFragmentSynthesis(40, 50);

        $this->assertFalse($recipe['can_exchange']);
        $this->assertSame(10, $recipe['missing_gold']);

        try {
            app(MaterialExchangeService::class)->exchange($character, $recipe['id'], 1, true);
            $this->fail('Gold不足のまま交換されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('Goldが不足しています。', $e->getMessage());
        }

        $character->refresh();
        $this->assertSame(40, (int) $character->money);
        $this->assertSame(50, (int) $character->bank_gold);
        $this->assertSame(4, $this->ownedQuantity($character, $materials['fang']));
        $this->assertSame(2, $this->ownedQuantity($character, $materials['ore']));
        $this->assertSame(0, $this->ownedQuantity($character, $materials['target']));
    }

    public function test_bank_is_not_used_when_hand_gold_covers_the_cost(): void
    {
        [$character, $recipe] = $this->prepareFragmentSynthesis(100, 80);

        app(MaterialExchangeService::class)->exchange($character, $recipe['id']);

        $character->refresh();
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(80, (int) $character->bank_gold);

        $transaction = GoldTransaction::query()->where('character_id', $character->id)->sole();
        $this->assertSame(100, (int) $transaction->metadata['payment_hand_gold']);
        $this->assertSame(0, (int) $transaction->metadata['payment_bank_gold']);
    }

    private function prepareFragmentSynthesis(int $handGold, int $bankGold): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '銀行支払いテスト',
            'money' => $handGold,
            'bank_gold' => $bankGold,
            'explore_stamina' => 0,
        ]);

        $materials = [
            'fang' => $this->createMaterial('MAT_COMMON_GOBLIN_FANG', '小鬼の牙'),
            'ore' => $this->createMaterial('MAT_COMMON_MAGIC_ORE', '魔鉱片'),
            'target' => $this->createMaterial('MAT_ENHANCE_FRAGMENT', '強化石の欠片'),
        ];

        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $materials['fang']->id,
            'quantity' => 4,
        ]);
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $materials['ore']->id,
            'quantity' => 2,
        ]);

        $recipe = collect(app(MaterialExchangeService::class)->recipes($character))
            ->first(fn (array $candidate): bool => ($candidate['target_code'] ?? null) === 'MAT_ENHANCE_FRAGMENT');

        $this->assertNotNull($recipe);

        return [$character, $recipe, $materials];
    }

    private function createMaterial(string $code, string $name): Material
    {
        return Material::query()->updateOrCreate([
            'material_code' => $code,
        ], [
            'name' => $name,
            'category' => '共通素材',
            'rarity' => 'N',
            'npc_sale_price' => 0,
            'is_tradable' => false,
        ]);
    }

    private function ownedQuantity(Character $character, Material $material): int
    {
        return (int) (CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('material_id', $material->id)
            ->value('quantity') ?? 0);
    }
}
