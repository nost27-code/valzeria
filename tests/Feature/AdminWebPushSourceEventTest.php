<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\Character;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\AdminWebPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWebPushSourceEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_bug_report_and_contact_form_notify_only_the_configured_admin_character(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $recipient = $this->createCharacter($admin, 'ヴァル');
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $otherAdminCharacter = $this->createCharacter($otherAdmin, '別の管理者');
        $reporter = User::factory()->create();
        $reporterCharacter = $this->createCharacter($reporter, '報告者');
        config()->set('web_push.admin_recipient_character_id', $recipient->id);

        $this->actingAs($reporter)
            ->withSession(['current_character_id' => $reporterCharacter->id])
            ->post(route('bug-reports.store'), [
                'kind' => BugReport::KIND_BUG,
                'body' => '戦闘結果画面で表示が崩れる不具合があります。',
            ])
            ->assertRedirect(route('bug-reports.create'));

        $this->post(route('legal.contact.store'), [
            'sender_name' => '問い合わせ者',
            'sender_email' => 'contact@example.test',
            'category' => 'other',
            'subject' => '確認してほしい内容があります',
            'body' => '管理画面で確認してほしい問い合わせ本文です。',
        ])->assertRedirect(route('legal.contact'));

        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $recipient->id,
            'type' => AdminWebPushNotificationService::TYPE_BUG_REPORT,
            'title' => '新しい不具合報告があります',
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $recipient->id,
            'type' => AdminWebPushNotificationService::TYPE_CONTACT_MESSAGE,
            'title' => '新着メールがあります',
        ]);
        $this->assertSame(
            [$recipient->id],
            \App\Models\CharacterNotification::query()
                ->distinct()
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $otherAdminCharacter->id,
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $reporterCharacter->id,
        ]);
    }

    public function test_non_admin_configured_character_fails_closed(): void
    {
        $player = User::factory()->create();
        $character = $this->createCharacter($player, '管理者ではない冒険者');
        config()->set('web_push.admin_recipient_character_id', $character->id);
        $report = BugReport::query()->create([
            'body' => '通知先の権限確認用の不具合報告です。',
            'status' => 'new',
        ]);

        $notification = app(AdminWebPushNotificationService::class)->notifyBugReport($report);

        $this->assertNull($notification);
        $this->assertDatabaseCount('character_notifications', 0);
    }

    public function test_suggestion_uses_a_suggestion_admin_notification_title(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $recipient = $this->createCharacter($admin, 'ヴァル');
        config()->set('web_push.admin_recipient_character_id', $recipient->id);
        $suggestion = BugReport::query()->create([
            'kind' => BugReport::KIND_SUGGESTION,
            'body' => '国家レイドを改善してほしいです。',
            'status' => 'new',
        ]);

        $notification = app(AdminWebPushNotificationService::class)->notifyBugReport($suggestion);

        $this->assertNotNull($notification);
        $this->assertSame('新しい改善要望があります', $notification->title);
    }

    private function createCharacter(User $user, string $name): Character
    {
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 0,
        ]);

        $valmon = ValmonMaster::query()->create([
            'valmon_key' => 'admin-push-test-'.$character->id,
            'name' => '通知確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmon->id,
            'level' => 1,
            'exp' => 0,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return $character;
    }
}
