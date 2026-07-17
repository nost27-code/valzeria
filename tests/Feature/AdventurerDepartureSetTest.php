<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\AdventureSupportService;
use App\Services\GameSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdventurerDepartureSetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['support_pass.enabled' => true]);
        app(GameSettingService::class)->flush();
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(GameSettingService::class)->flush();

        parent::tearDown();
    }

    public function test_departure_set_grants_every_benefit_and_spends_free_kiseki_first(): void
    {
        [$user, $character] = $this->createCharacterWithKiseki(60, 50);

        $result = app(AdventureSupportService::class)->purchase($character, 'adventurer_departure_set');

        $this->assertTrue($result['success']);
        $this->assertSame(implode("\n", [
            '冒険者旅立ちセットを受け取りました。',
            '冒険者支援パス30日利用券×1と探索力の薬×3を所持品へ追加しました',
            '素材倉庫 500 → 1,000（+500）へ拡張しました。',
            '装備倉庫 300 → 600（+300）へ拡張しました。',
            '限定カードフレームも付与しました。',
        ]), $result['message']);

        $character->refresh();
        $user->refresh();
        $this->assertSame(0, (int) $character->free_kiseki);
        $this->assertSame(10, (int) $character->paid_kiseki);
        $this->assertSame(10, (int) $character->kiseki);
        $this->assertSame(1000, (int) $character->material_storage_limit);
        $this->assertSame(600, (int) $character->equipment_storage_limit);
        $this->assertNull($user->support_pass_expires_at);

        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'explore_stamina_potion',
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'support_pass_30d_ticket',
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('character_shop_limits', [
            'character_id' => $character->id,
            'shop_item_key' => 'adventurer_departure_set',
            'purchased_count' => 1,
        ]);
        $this->assertDatabaseMissing('character_shop_limits', [
            'character_id' => $character->id,
            'shop_item_key' => 'explore_stamina_potion',
        ]);
        $this->assertDatabaseMissing('character_shop_limits', [
            'character_id' => $character->id,
            'shop_item_key' => 'material_storage_expand',
        ]);
        $this->assertDatabaseMissing('character_shop_limits', [
            'character_id' => $character->id,
            'shop_item_key' => 'equipment_storage_expand',
        ]);

        foreach ([
            ['card_frame', 'images/profile/adventurer_card_frame91.webp'],
            ['avatar_frame', 'images/profile/adventurer_avatar_frame91.webp'],
        ] as [$assetType, $assetPath]) {
            $this->assertDatabaseHas('character_adventurer_card_assets', [
                'character_id' => $character->id,
                'asset_type' => $assetType,
                'asset_path' => $assetPath,
                'source' => 'adventurer_departure_set',
            ]);
        }

        $this->assertDatabaseHas('shop_purchase_logs', [
            'character_id' => $character->id,
            'shop_item_key' => 'adventurer_departure_set',
            'total_kiseki_cost' => 100,
            'free_kiseki_spent' => 60,
            'paid_kiseki_spent' => 40,
        ]);
        $this->assertDatabaseHas('kiseki_transactions', [
            'character_id' => $character->id,
            'kiseki_type' => 'mixed',
            'amount' => -100,
            'transaction_type' => 'shop_purchase',
            'source_type' => 'adventure_support',
        ]);
        $this->assertDatabaseMissing('pass_purchase_logs', [
            'user_id' => $user->id,
            'character_id' => $character->id,
        ]);

        $useResult = app(AdventureSupportService::class)->useConsumable($character, 'support_pass_30d_ticket');

        $this->assertTrue($useResult['success']);
        $this->assertStringContainsString('利用券を使用しました', $useResult['message']);
        $user->refresh();
        $this->assertSame('2026-08-16 12:00:00', $user->support_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'support_pass_30d_ticket',
            'quantity' => 0,
        ]);
        $this->assertDatabaseHas('pass_purchase_logs', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'pass_type' => 'support_pass_30d',
            'price_currency' => 'ticket',
            'price_amount' => 0,
        ]);
    }

    public function test_departure_set_can_only_be_purchased_once(): void
    {
        [, $character] = $this->createCharacterWithKiseki(105, 0);

        $first = app(AdventureSupportService::class)->purchase($character, 'adventurer_departure_set');
        $second = app(AdventureSupportService::class)->purchase($character, 'adventurer_departure_set');

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame('このセットは一度限りの購入です。すでに購入済みです。', $second['message']);

        $character->refresh();
        $this->assertSame(5, (int) $character->kiseki);
        $this->assertSame(1000, (int) $character->material_storage_limit);
        $this->assertSame(600, (int) $character->equipment_storage_limit);
        $this->assertSame(1, DB::table('shop_purchase_logs')
            ->where('character_id', $character->id)
            ->where('shop_item_key', 'adventurer_departure_set')
            ->count());
    }

    public function test_departure_set_does_not_grant_anything_when_kiseki_is_insufficient(): void
    {
        [$user, $character] = $this->createCharacterWithKiseki(99, 0);

        $result = app(AdventureSupportService::class)->purchase($character, 'adventurer_departure_set');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('輝石が不足', $result['message']);
        $character->refresh();
        $user->refresh();
        $this->assertSame(99, (int) $character->kiseki);
        $this->assertSame(500, (int) $character->material_storage_limit);
        $this->assertSame(300, (int) $character->equipment_storage_limit);
        $this->assertNull($user->support_pass_expires_at);
        $this->assertDatabaseMissing('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'explore_stamina_potion',
        ]);
        $this->assertDatabaseMissing('shop_purchase_logs', [
            'character_id' => $character->id,
            'shop_item_key' => 'adventurer_departure_set',
        ]);
    }

    public function test_departure_set_ticket_is_kept_when_support_pass_cannot_be_extended(): void
    {
        [$user, $character] = $this->createCharacterWithKiseki(100, 0);
        $user->forceFill(['support_pass_expires_at' => now()->addDays(70)])->save();

        $purchaseResult = app(AdventureSupportService::class)->purchase($character, 'adventurer_departure_set');
        $useResult = app(AdventureSupportService::class)->useConsumable($character, 'support_pass_30d_ticket');

        $this->assertTrue($purchaseResult['success']);
        $this->assertFalse($useResult['success']);
        $this->assertStringContainsString('最大90日先', $useResult['message']);
        $character->refresh();
        $user->refresh();
        $this->assertSame(0, (int) $character->kiseki);
        $this->assertSame('2026-09-25 12:00:00', $user->support_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('shop_purchase_logs', [
            'character_id' => $character->id,
            'shop_item_key' => 'adventurer_departure_set',
        ]);
        $this->assertDatabaseHas('character_shop_limits', [
            'character_id' => $character->id,
            'shop_item_key' => 'adventurer_departure_set',
            'purchased_count' => 1,
        ]);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'support_pass_30d_ticket',
            'quantity' => 1,
        ]);
    }

    public function test_standalone_support_pass_purchase_grants_a_ticket_until_used(): void
    {
        [$user, $character] = $this->createCharacterWithKiseki(50, 0);

        $purchaseResult = app(AdventureSupportService::class)->purchase($character, 'support_pass_30d');

        $this->assertTrue($purchaseResult['success']);
        $this->assertStringContainsString('所持品から使用', $purchaseResult['message']);
        $user->refresh();
        $this->assertNull($user->support_pass_expires_at);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'support_pass_30d_ticket',
            'quantity' => 1,
        ]);

        $useResult = app(AdventureSupportService::class)->useConsumable($character, 'support_pass_30d_ticket');

        $this->assertTrue($useResult['success']);
        $user->refresh();
        $this->assertSame('2026-08-16 12:00:00', $user->support_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('pass_purchase_logs', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'price_currency' => 'ticket',
            'price_amount' => 0,
        ]);
    }

    /**
     * @return array{User, Character}
     */
    private function createCharacterWithKiseki(int $freeKiseki, int $paidKiseki): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '旅立ちテスト',
            'free_kiseki' => $freeKiseki,
            'paid_kiseki' => $paidKiseki,
            'kiseki' => $freeKiseki + $paidKiseki,
            'material_storage_limit' => 500,
            'equipment_storage_limit' => 300,
            'explore_stamina' => 0,
        ]);

        return [$user, $character];
    }
}
