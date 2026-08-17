<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\AdminWebPushNotificationService;
use App\Services\WebPushDispatchService;
use App\Services\WebPushEligibilityService;
use App\Services\WebPushPreferenceService;
use App\Services\WebPushSender;
use App\Services\WebPushSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_route_is_hidden_while_off_and_available_in_all_mode(): void
    {
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '検証対象');
        $this->configureWebPush('off', [$character->id]);

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('web-push.subscription.store'), $this->subscriptionPayload())
            ->assertNotFound();

        $this->configureWebPush('all', [$character->id]);
        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('web-push.subscription.store'), $this->subscriptionPayload())
            ->assertCreated();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
    }

    public function test_only_allowlisted_character_can_manage_an_encrypted_subscription(): void
    {
        $targetUser = User::factory()->create();
        $target = $this->createCharacter($targetUser, '検証対象');
        $otherUser = User::factory()->create();
        $other = $this->createCharacter($otherUser, '対象外');
        $this->configureWebPush('allowlist', [$target->id]);
        $payload = $this->subscriptionPayload();

        $this->actingAs($targetUser)
            ->withSession(['current_character_id' => $target->id])
            ->postJson(route('web-push.subscription.store'), $payload)
            ->assertCreated()
            ->assertJson(['subscribed' => true]);

        $stored = WebPushSubscription::query()->firstOrFail();
        $this->assertSame($target->id, $stored->character_id);
        $this->assertSame($payload['endpoint'], $stored->endpoint);
        $this->assertNotSame(
            $payload['endpoint'],
            DB::table('web_push_subscriptions')->value('endpoint')
        );

        $this->actingAs($otherUser)
            ->withSession(['current_character_id' => $other->id])
            ->deleteJson(route('web-push.subscription.destroy'), ['endpoint' => $payload['endpoint']])
            ->assertNotFound();

        $this->assertDatabaseCount('web_push_subscriptions', 1);

        $this->actingAs($targetUser)
            ->withSession(['current_character_id' => $target->id])
            ->deleteJson(route('web-push.subscription.destroy'), ['endpoint' => $payload['endpoint']])
            ->assertOk()
            ->assertJson(['subscribed' => false]);

        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_control_is_rendered_only_for_allowlisted_character(): void
    {
        $targetUser = User::factory()->create();
        $target = $this->createCharacter($targetUser, '検証対象');
        $otherUser = User::factory()->create();
        $other = $this->createCharacter($otherUser, '対象外');
        $this->configureWebPush('allowlist', [$target->id]);

        $this->actingAs($targetUser);
        session(['current_character_id' => $target->id]);
        $this->assertStringContainsString(
            'data-web-push-control',
            Blade::render('<x-web-push-control />')
        );

        $this->actingAs($otherUser);
        session(['current_character_id' => $other->id]);
        $this->assertStringNotContainsString(
            'data-web-push-control',
            Blade::render('<x-web-push-control />')
        );
    }

    public function test_dispatch_sends_generic_payload_only_to_allowlisted_character(): void
    {
        $targetUser = User::factory()->create();
        $target = $this->createCharacter($targetUser, '検証対象');
        $otherUser = User::factory()->create();
        $other = $this->createCharacter($otherUser, '対象外');
        $this->configureWebPush('allowlist', [$target->id]);

        $oldNotification = CharacterNotification::query()->create([
            'character_id' => $target->id,
            'category' => 'general',
            'type' => 'test',
            'title' => '購読前の通知',
        ]);
        $subscriptionService = app(WebPushSubscriptionService::class);
        $targetSubscription = $subscriptionService->subscribe(
            $target,
            'https://push.example.test/target',
            'targetPublicKey',
            'targetAuthToken'
        );
        $otherSubscription = $subscriptionService->subscribe(
            $other,
            'https://push.example.test/other',
            'otherPublicKey',
            'otherAuthToken'
        );

        $subscriptionService->subscribe(
            $target,
            'https://push.example.test/target',
            'refreshedPublicKey',
            'refreshedAuthToken'
        );
        $this->assertSame(
            $oldNotification->id,
            $targetSubscription->fresh()->last_notification_id
        );

        $targetNotification = CharacterNotification::query()->create([
            'character_id' => $target->id,
            'category' => 'general',
            'type' => 'test',
            'title' => '端末には出さない限定タイトル',
            'body' => '端末には出さない限定本文',
        ]);
        $otherNotification = CharacterNotification::query()->create([
            'character_id' => $other->id,
            'category' => 'general',
            'type' => 'test',
            'title' => '対象外通知',
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (WebPushSubscription $subscription): bool => $subscription->character_id === $target->id),
                Mockery::on(function (array $payload) use ($targetNotification): bool {
                    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

                    return $payload['body'] === '通知ベルに新着があります。'
                        && $payload['data']['notificationId'] === $targetNotification->id
                        && ! str_contains($encoded, '限定タイトル')
                        && ! str_contains($encoded, '限定本文');
                })
            )
            ->andReturn(['success' => true, 'expired' => false]);

        $dispatcher = new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        );
        $result = $dispatcher->dispatch();

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(
            $targetNotification->id,
            $targetSubscription->fresh()->last_notification_id
        );
        $this->assertSame(
            $otherNotification->id,
            $otherSubscription->fresh()->last_notification_id
        );
    }

    public function test_expired_push_endpoint_is_removed(): void
    {
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '検証対象');
        $this->configureWebPush('allowlist', [$character->id]);

        app(WebPushSubscriptionService::class)->subscribe(
            $character,
            'https://push.example.test/expired',
            'expiredPublicKey',
            'expiredAuthToken'
        );
        CharacterNotification::query()->create([
            'character_id' => $character->id,
            'category' => 'general',
            'type' => 'test',
            'title' => '失効確認',
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->andReturn(['success' => false, 'expired' => true]);

        $result = (new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        ))->dispatch();

        $this->assertSame(1, $result['expired']);
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_title_preview_sends_only_sanitized_truncated_bell_title(): void
    {
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '検証対象');
        $this->configureWebPush('allowlist', [$character->id]);
        config()->set('web_push.preview_mode', 'title');

        $subscription = app(WebPushSubscriptionService::class)->subscribe(
            $character,
            'https://push.example.test/title-preview',
            'titlePreviewPublicKey',
            'titlePreviewAuthToken'
        );
        $notification = CharacterNotification::query()->create([
            'character_id' => $character->id,
            'category' => 'general',
            'type' => 'test',
            'title' => '  <b>装備市場</b>  '.str_repeat('売', 70).'  ',
            'body' => '落札額など詳しい情報は端末へ出さない',
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (WebPushSubscription $stored): bool => $stored->is($subscription)),
                Mockery::on(function (array $payload) use ($notification): bool {
                    return $payload['title'] === 'ヴァルゼリアの冒険者'
                        && $payload['data']['notificationId'] === $notification->id
                        && str_starts_with($payload['body'], '装備市場 ')
                        && str_ends_with($payload['body'], '…')
                        && Str::length($payload['body']) === 60
                        && ! str_contains($payload['body'], '<b>')
                        && ! str_contains($payload['body'], '落札額');
                })
            )
            ->andReturn(['success' => true, 'expired' => false]);

        $result = (new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        ))->dispatch();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame($notification->id, $subscription->fresh()->last_notification_id);
    }

    public function test_dispatch_sends_only_selected_types_and_advances_past_disabled_notifications(): void
    {
        $user = User::factory()->create();
        $character = $this->createCharacter($user, '種類選択者');
        $this->configureWebPush('all', []);

        $subscription = app(WebPushSubscriptionService::class)->subscribe(
            $character,
            'https://push.example.test/type-filter',
            'typeFilterPublicKey',
            'typeFilterAuthToken'
        );
        app(WebPushPreferenceService::class)->save($character, ['arena_rank_down']);

        $enabledNotification = CharacterNotification::query()->create([
            'character_id' => $character->id,
            'category' => 'arena',
            'type' => 'arena_rank_down',
            'title' => 'ランク戦順位が低下しました',
        ]);
        $disabledNotification = CharacterNotification::query()->create([
            'character_id' => $character->id,
            'category' => 'market',
            'type' => 'market_material_sold',
            'title' => '市場で素材が売れました',
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (WebPushSubscription $stored): bool => $stored->is($subscription)),
                Mockery::on(fn (array $payload): bool => $payload['data']['notificationId'] === $enabledNotification->id)
            )
            ->andReturn(['success' => true, 'expired' => false]);

        $dispatcher = new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        );
        $firstResult = $dispatcher->dispatch();
        $secondResult = $dispatcher->dispatch();

        $this->assertSame(1, $firstResult['sent']);
        $this->assertSame(0, $secondResult['sent']);
        $this->assertSame($disabledNotification->id, $subscription->fresh()->last_notification_id);
        $this->assertDatabaseHas('character_notifications', ['id' => $enabledNotification->id]);
        $this->assertDatabaseHas('character_notifications', ['id' => $disabledNotification->id]);
    }

    public function test_admin_notification_is_sent_only_to_the_configured_admin_character(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $recipient = $this->createCharacter($admin, 'ヴァル');
        $otherUser = User::factory()->create();
        $other = $this->createCharacter($otherUser, '対象外');
        $this->configureWebPush('all', []);
        config()->set('web_push.admin_recipient_character_id', $recipient->id);

        $subscriptionService = app(WebPushSubscriptionService::class);
        $recipientSubscription = $subscriptionService->subscribe(
            $recipient,
            'https://push.example.test/admin-recipient',
            'adminPublicKey',
            'adminAuthToken',
        );
        $otherSubscription = $subscriptionService->subscribe(
            $other,
            'https://push.example.test/other-recipient',
            'otherPublicKey',
            'otherAuthToken',
        );
        app(WebPushPreferenceService::class)->save($recipient, []);

        $adminNotification = CharacterNotification::query()->create([
            'character_id' => $recipient->id,
            'category' => 'admin',
            'type' => AdminWebPushNotificationService::TYPE_BUG_REPORT,
            'title' => '新しい不具合報告があります',
            'url' => route('admin.bug-reports'),
        ]);
        $otherNotification = CharacterNotification::query()->create([
            'character_id' => $other->id,
            'category' => 'admin',
            'type' => AdminWebPushNotificationService::TYPE_BUG_REPORT,
            'title' => '対象外へ送ってはいけない通知',
            'url' => route('admin.bug-reports'),
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (WebPushSubscription $subscription): bool => $subscription->is($recipientSubscription)),
                Mockery::on(fn (array $payload): bool => $payload['body'] === '新しい不具合報告があります'
                    && $payload['tag'] === 'valzeria-admin-'.$adminNotification->id
                    && $payload['data']['url'] === '/admin/bug-reports'
                    && $payload['data']['notificationId'] === $adminNotification->id),
            )
            ->andReturn(['success' => true, 'expired' => false]);

        $result = (new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        ))->dispatch();

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame($adminNotification->id, $recipientSubscription->fresh()->last_notification_id);
        $this->assertSame($otherNotification->id, $otherSubscription->fresh()->last_notification_id);
    }

    public function test_admin_and_regular_notifications_are_announced_together_without_silent_loss(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $recipient = $this->createCharacter($admin, 'ヴァル');
        $this->configureWebPush('all', []);
        config()->set('web_push.admin_recipient_character_id', $recipient->id);

        $subscription = app(WebPushSubscriptionService::class)->subscribe(
            $recipient,
            'https://push.example.test/admin-and-regular',
            'combinedPublicKey',
            'combinedAuthToken',
        );
        app(WebPushPreferenceService::class)->save($recipient, ['market_material_sold']);

        $adminNotification = CharacterNotification::query()->create([
            'character_id' => $recipient->id,
            'category' => 'admin',
            'type' => AdminWebPushNotificationService::TYPE_BUG_REPORT,
            'title' => '新しい不具合報告があります',
            'url' => route('admin.bug-reports'),
        ]);
        $regularNotification = CharacterNotification::query()->create([
            'character_id' => $recipient->id,
            'category' => 'market',
            'type' => 'market_material_sold',
            'title' => '市場で素材が売れました',
            'url' => route('market.index'),
        ]);

        $sender = Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with(
                Mockery::on(fn (WebPushSubscription $stored): bool => $stored->is($subscription)),
                Mockery::on(fn (array $payload): bool => $payload['body'] === '管理画面と通知ベルに新着があります。'
                    && $payload['tag'] === 'valzeria-admin-'.$adminNotification->id
                    && $payload['data']['url'] === '/admin/bug-reports'
                    && $payload['data']['notificationId'] === $adminNotification->id),
            )
            ->andReturn(['success' => true, 'expired' => false]);

        $result = (new WebPushDispatchService(
            app(WebPushEligibilityService::class),
            app(WebPushPreferenceService::class),
            $sender,
            app(AdminWebPushNotificationService::class),
        ))->dispatch();

        $this->assertSame(1, $result['sent']);
        $this->assertSame($regularNotification->id, $subscription->fresh()->last_notification_id);
    }

    private function configureWebPush(string $mode, array $characterIds): void
    {
        config()->set('web_push.mode', $mode);
        config()->set('web_push.allowed_character_ids', $characterIds);
        config()->set('web_push.preview_mode', 'generic');
        config()->set('web_push.vapid.subject', 'mailto:test@example.test');
        config()->set('web_push.vapid.public_key', $this->base64Url("\x04".str_repeat('P', 64)));
        config()->set('web_push.vapid.private_key', $this->base64Url(str_repeat('K', 32)));
    }

    private function createCharacter(User $user, string $name): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 0,
        ]);
    }

    private function subscriptionPayload(): array
    {
        return [
            'endpoint' => 'https://push.example.test/subscription-1',
            'keys' => [
                'p256dh' => 'publicKey_123',
                'auth' => 'authToken_123',
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
