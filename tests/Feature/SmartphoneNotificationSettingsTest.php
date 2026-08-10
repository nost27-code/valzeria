<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\CharacterWebPushPreference;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ExplorationStaminaService;
use App\Services\GameSettingService;
use App\Services\WebPushEventService;
use App\Services\WebPushPreferenceService;
use App\Services\WebPushSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SmartphoneNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adventurer_menu_places_smartphone_notifications_below_settings(): void
    {
        $method = new ReflectionMethod(MainScreen::class, 'homeMenuItems');
        $items = $method->invoke(new MainScreen);
        $settingsItems = collect($items)
            ->where('group', '設定')
            ->values();

        $this->assertSame(['設定', 'スマホ通知'], $settingsItems->pluck('name')->all());
        $this->assertSame('smartphone-notifications.edit', $settingsItems->last()['route']);
    }

    public function test_settings_page_explains_pwa_constraints_and_lists_current_notification_types(): void
    {
        [$user, $character] = $this->createPlayer('案内確認者');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('smartphone-notifications.edit'))
            ->assertOk()
            ->assertSee('PWA（アプリのように使えるWebサイト）')
            ->assertSee('iOS・iPadOS 16.4以降')
            ->assertSee('探索力がMAXになったとき')
            ->assertSee('闘技場の順位が下がったとき')
            ->assertSee('素材市場で売れたとき')
            ->assertSee('個別メッセージが届いたとき')
            ->assertSee('ゲーム内の通知ベルにはすべて残ります');
    }

    public function test_character_can_save_selected_phone_notification_types(): void
    {
        [$user, $character] = $this->createPlayer('種類保存者');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->patch(route('smartphone-notifications.update'), [
                'types' => ['exploration_stamina_full', 'arena_rank_down', 'private_message'],
            ])
            ->assertRedirect(route('smartphone-notifications.edit'))
            ->assertSessionHas('message', 'スマホ通知の種類を保存しました。');

        $preference = CharacterWebPushPreference::query()->firstOrFail();
        $this->assertSame($character->id, $preference->character_id);
        $this->assertSame(
            ['exploration_stamina_full', 'arena_rank_down', 'private_message'],
            $preference->enabled_types
        );

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->patch(route('smartphone-notifications.update'), [])
            ->assertRedirect(route('smartphone-notifications.edit'));

        $this->assertSame([], $preference->fresh()->enabled_types);
    }

    public function test_unknown_notification_type_cannot_be_saved(): void
    {
        [$user, $character] = $this->createPlayer('不正種類確認者');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('smartphone-notifications.edit'))
            ->patch(route('smartphone-notifications.update'), [
                'types' => ['unknown_notification'],
            ])
            ->assertRedirect(route('smartphone-notifications.edit'))
            ->assertSessionHasErrors('types.0');

        $this->assertDatabaseCount('character_web_push_preferences', 0);
    }

    public function test_exploration_stamina_full_notification_is_generated_once_after_natural_recovery(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));

        try {
            $this->configureWebPush();
            $this->configureStaminaMode();
            [, $character] = $this->createPlayer('探索通知者', [
                'wins' => 0,
                'explore_stamina' => 249,
                'explore_stamina_max' => 250,
                'explore_stamina_updated_at' => now()->subSeconds(61),
            ]);
            app(WebPushSubscriptionService::class)->subscribe(
                $character,
                'https://push.example.test/stamina-full',
                'staminaFullPublicKey',
                'staminaFullAuthToken'
            );
            app(WebPushPreferenceService::class)->save($character, ['exploration_stamina_full']);

            $service = app(WebPushEventService::class);

            $this->assertSame(1, $service->generate());
            $this->assertSame(0, $service->generate());
            $this->assertDatabaseHas('characters', [
                'id' => $character->id,
                'explore_stamina' => 250,
            ]);
            $this->assertDatabaseHas('character_notifications', [
                'character_id' => $character->id,
                'type' => 'exploration_stamina_full',
                'title' => '探索力が最大まで回復しました',
            ]);
            $this->assertSame(1, $character->notifications()->where('type', 'exploration_stamina_full')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_disabled_stamina_type_does_not_send_a_delayed_full_notification_when_reenabled(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));

        try {
            $this->configureWebPush();
            $this->configureStaminaMode();
            [, $character] = $this->createPlayer('探索通知停止者', [
                'wins' => 0,
                'explore_stamina' => 249,
                'explore_stamina_max' => 250,
                'explore_stamina_updated_at' => now()->subSeconds(61),
            ]);
            app(WebPushSubscriptionService::class)->subscribe(
                $character,
                'https://push.example.test/stamina-disabled',
                'staminaDisabledPublicKey',
                'staminaDisabledAuthToken'
            );
            app(WebPushPreferenceService::class)->save($character, []);

            $this->assertSame(0, app(WebPushEventService::class)->generate());
            $this->assertSame(250, (int) $character->fresh()->explore_stamina);
            $this->assertSame(0, $character->notifications()->where('type', 'exploration_stamina_full')->count());

            app(WebPushPreferenceService::class)->save($character, ['exploration_stamina_full']);

            $this->assertSame(0, app(WebPushEventService::class)->generate());
            $this->assertSame(0, $character->notifications()->where('type', 'exploration_stamina_full')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Character}
     */
    private function createPlayer(string $name, array $attributes = []): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 250,
            ...$attributes,
        ]);
        $valmonMaster = ValmonMaster::query()->create([
            'valmon_key' => 'smartphone-notification-test-'.$character->id,
            'name' => '通知確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character];
    }

    private function configureWebPush(): void
    {
        config()->set('web_push.mode', 'all');
        config()->set('web_push.vapid.subject', 'mailto:test@example.test');
        config()->set('web_push.vapid.public_key', $this->base64Url("\x04".str_repeat('P', 64)));
        config()->set('web_push.vapid.private_key', $this->base64Url(str_repeat('K', 32)));
    }

    private function configureStaminaMode(): void
    {
        $this->app->instance(GameSettingService::class, new class
        {
            public function getString(string $key, string $default = ''): string
            {
                return $key === 'exploration.mode' ? ExplorationStaminaService::MODE_STAMINA : $default;
            }

            public function getInt(string $key, int $default = 0): int
            {
                return match ($key) {
                    'exploration.stamina_recovery_seconds' => 60,
                    'exploration.stamina_cost' => 1,
                    default => $default,
                };
            }

            public function getBool(string $key, bool $default = false): bool
            {
                return $default;
            }
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
