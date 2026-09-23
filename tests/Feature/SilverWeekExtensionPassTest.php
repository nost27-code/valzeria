<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\User;
use App\Services\AdventureSupportService;
use App\Services\AdventureSupportItemControlService;
use App\Services\ExplorationStaminaService;
use App\Services\GameSettingService;
use App\Services\SilverWeekExtensionPassService;
use App\Services\SupportPassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SilverWeekExtensionPassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['support_pass.enabled' => true]);
        app(GameSettingService::class)->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(GameSettingService::class)->flush();

        parent::tearDown();
    }

    public function test_sale_starts_exactly_at_midnight_after_silver_week(): void
    {
        [, $character] = $this->createCharacterWithKiseki(105, 0);

        Carbon::setTestNow(Carbon::parse('2026-09-23 23:59:59', 'Asia/Tokyo'));
        $before = collect(app(AdventureSupportService::class)->catalogFor($character))
            ->flatten(1)
            ->firstWhere('key', SilverWeekExtensionPassService::PASS_TYPE);

        $this->assertFalse($before['can_purchase']);
        $this->assertSame('シルバーウィーク仕様延長パスは2026/09/24 00:00から販売します。', $before['disabled_reason']);
        $this->assertFalse(app(AdventureSupportService::class)->purchase($character, SilverWeekExtensionPassService::PASS_TYPE)['success']);
        $this->assertSame(105, (int) $character->fresh()->kiseki);

        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        $after = collect(app(AdventureSupportService::class)->catalogFor($character->fresh()))
            ->flatten(1)
            ->firstWhere('key', SilverWeekExtensionPassService::PASS_TYPE);

        $this->assertTrue($after['can_purchase']);
    }

    public function test_support_shop_shows_the_scheduled_extension_pass_and_its_illustration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 23:59:59', 'Asia/Tokyo'));
        [$user] = $this->createCharacterWithKiseki(105, 0);

        $this->actingAs($user)
            ->get(route('kiseki.support'))
            ->assertOk()
            ->assertSee('シルバーウィーク仕様延長パス 30日')
            ->assertSee('9/24 0:00販売開始')
            ->assertSee('images/icon/silver_week_extension_pass.webp');
    }

    public function test_support_shop_explains_ticket_activation_and_shows_confirmed_use_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        CharacterConsumableItem::create([
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('kiseki.support'))
            ->assertOk()
            ->assertSee('利用券所持数：1枚')
            ->assertSee('購入しただけでは効果は発動しません。所持品から利用券を使用してください。')
            ->assertSee('延長パス利用券を使用しますか？')
            ->assertSee('使用する')
            ->assertSee(route('kiseki.support.silver-week-extension-pass.use'), false);
    }

    public function test_extension_ticket_can_be_used_from_support_shop_after_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        CharacterConsumableItem::create([
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('kiseki.support.silver-week-extension-pass.use'))
            ->assertRedirect(route('kiseki.support'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('2026-09-24 00:00:00', $user->silver_week_extension_pass_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 00:00:00', $user->silver_week_extension_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 0,
        ]);
    }

    public function test_launch_catalog_shows_both_independent_passes_without_the_departure_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 23:59:59', 'Asia/Tokyo'));
        [, $character] = $this->createCharacterWithKiseki(0, 0);
        app(SupportPassService::class)->setEnabled(true);
        app(AdventureSupportItemControlService::class)->setVisible('adventurer_departure_set', false);

        $catalog = collect(app(AdventureSupportService::class)->catalogFor($character))->flatten(1);

        $this->assertNotNull($catalog->firstWhere('key', SupportPassService::PASS_TYPE));
        $this->assertNotNull($catalog->firstWhere('key', SilverWeekExtensionPassService::PASS_TYPE));
        $this->assertNull($catalog->firstWhere('key', 'adventurer_departure_set'));
    }

    public function test_purchase_grants_an_independent_ticket_and_records_kiseki_ledgers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(60, 45);

        $result = app(AdventureSupportService::class)->purchase($character, SilverWeekExtensionPassService::PASS_TYPE);

        $this->assertTrue($result['success']);
        $this->assertNull($user->fresh()->silver_week_extension_pass_started_at);
        $this->assertNull($user->fresh()->silver_week_extension_pass_expires_at);
        $this->assertSame(0, (int) $character->fresh()->kiseki);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('shop_purchase_logs', [
            'character_id' => $character->id,
            'shop_item_key' => SilverWeekExtensionPassService::PASS_TYPE,
            'total_kiseki_cost' => 105,
            'free_kiseki_spent' => 60,
            'paid_kiseki_spent' => 45,
        ]);
        $this->assertDatabaseHas('kiseki_transactions', [
            'character_id' => $character->id,
            'kiseki_type' => 'mixed',
            'amount' => -105,
            'transaction_type' => 'shop_purchase',
            'source_type' => 'adventure_support',
        ]);
    }

    public function test_ticket_activates_only_the_extension_pass_for_thirty_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(105, 0);
        $user->forceFill(['support_pass_expires_at' => now()->addDays(10)])->save();

        app(AdventureSupportService::class)->purchase($character, SilverWeekExtensionPassService::PASS_TYPE);
        $result = app(AdventureSupportService::class)->useConsumable($character, SilverWeekExtensionPassService::TICKET_ITEM_KEY);

        $this->assertTrue($result['success']);
        $user->refresh();
        $this->assertSame('2026-10-04 00:00:00', $user->support_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-24 00:00:00', $user->silver_week_extension_pass_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-24 00:00:00', $user->silver_week_extension_pass_expires_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 0,
        ]);
        $this->assertDatabaseHas('pass_purchase_logs', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'pass_type' => SilverWeekExtensionPassService::PASS_TYPE,
            'price_currency' => 'ticket',
            'price_amount' => 0,
        ]);
    }

    public function test_regular_pass_stacks_but_campaign_and_extension_bonus_do_not_double(): void
    {
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        $user->forceFill([
            'support_pass_expires_at' => Carbon::parse('2026-10-24 00:00:00', 'Asia/Tokyo'),
            'silver_week_extension_pass_started_at' => Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'),
            'silver_week_extension_pass_expires_at' => Carbon::parse('2026-10-24 00:00:00', 'Asia/Tokyo'),
        ])->save();

        $service = app(ExplorationStaminaService::class);

        Carbon::setTestNow(Carbon::parse('2026-09-23 23:59:59', 'Asia/Tokyo'));
        $character->unsetRelation('user');
        $this->assertSame(1000, $service->maxForCharacter($character));
        $this->assertSame(45, $service->recoverySecondsFor($character));

        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        $character->unsetRelation('user');
        $this->assertSame(1000, $service->maxForCharacter($character));
        $this->assertSame(45, $service->recoverySecondsFor($character));

        Carbon::setTestNow(Carbon::parse('2026-10-24 00:00:00', 'Asia/Tokyo'));
        $character->unsetRelation('user');
        $this->assertSame(250, $service->maxForCharacter($character));
        $this->assertSame(60, $service->recoverySecondsFor($character));
    }

    public function test_recovery_is_split_at_the_extension_pass_expiry_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:01:45', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        $user->forceFill([
            'silver_week_extension_pass_started_at' => Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'),
            'silver_week_extension_pass_expires_at' => Carbon::parse('2026-09-24 00:00:45', 'Asia/Tokyo'),
        ])->save();
        $character->forceFill([
            'explore_stamina' => 0,
            'explore_stamina_max' => 750,
            'explore_stamina_updated_at' => Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'),
        ])->save();
        $character->unsetRelation('user');

        $summary = app(ExplorationStaminaService::class)->summary($character);

        $this->assertSame(2, $summary['current']);
        $this->assertSame(250, $summary['max']);
        $this->assertSame(60, $summary['recovery_seconds']);
    }

    public function test_extension_rate_is_not_applied_before_its_recorded_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:10:45', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        $user->forceFill([
            'silver_week_extension_pass_started_at' => Carbon::parse('2026-09-24 00:10:00', 'Asia/Tokyo'),
            'silver_week_extension_pass_expires_at' => Carbon::parse('2026-10-24 00:10:00', 'Asia/Tokyo'),
        ])->save();
        $character->forceFill([
            'explore_stamina' => 0,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'),
        ])->save();
        $character->unsetRelation('user');

        $summary = app(ExplorationStaminaService::class)->summary($character);

        $this->assertSame(11, $summary['current']);
        $this->assertSame(750, $summary['max']);
    }

    public function test_ticket_is_kept_when_extension_would_exceed_ninety_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 00:00:00', 'Asia/Tokyo'));
        [$user, $character] = $this->createCharacterWithKiseki(0, 0);
        $user->forceFill([
            'silver_week_extension_pass_started_at' => now()->subDays(20),
            'silver_week_extension_pass_expires_at' => now()->addDays(70),
        ])->save();
        CharacterConsumableItem::create([
            'character_id' => $character->id,
            'item_key' => SilverWeekExtensionPassService::TICKET_ITEM_KEY,
            'quantity' => 1,
        ]);

        $result = app(AdventureSupportService::class)->useConsumable($character, SilverWeekExtensionPassService::TICKET_ITEM_KEY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('最大90日先', $result['message']);
        $this->assertSame(1, (int) CharacterConsumableItem::query()
            ->where('character_id', $character->id)
            ->where('item_key', SilverWeekExtensionPassService::TICKET_ITEM_KEY)
            ->value('quantity'));
    }

    /** @return array{User, Character} */
    private function createCharacterWithKiseki(int $freeKiseki, int $paidKiseki): array
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '延長パステスト',
            'free_kiseki' => $freeKiseki,
            'paid_kiseki' => $paidKiseki,
            'kiseki' => $freeKiseki + $paidKiseki,
            'explore_stamina' => 0,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        return [$user, $character];
    }
}
