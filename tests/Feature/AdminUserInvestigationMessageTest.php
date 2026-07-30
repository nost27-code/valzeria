<?php

namespace Tests\Feature;

use App\Livewire\Admin\PublicLogManager;
use App\Livewire\Admin\UserInvestigationManager;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_a_private_thread_from_user_investigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '個別連絡対象',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->assertSee('管理人個別メッセージ')
            ->assertSee('送信先：個別連絡対象')
            ->set('adminMessage', '管理人から確認したいことがあります。')
            ->call('sendAdminMessage')
            ->assertHasNoErrors()
            ->assertSet('adminMessage', '')
            ->assertSee('管理人から確認したいことがあります。');

        $this->assertDatabaseHas('public_logs', [
            'type' => 'admin_private',
            'character_id' => null,
            'receiver_id' => $character->id,
            'message' => '管理人から確認したいことがあります。',
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => 'admin_private_message',
            'title' => '管理人からメッセージが届きました',
            'body' => '管理人からの個別連絡: 管理人から確認したいことがあります。',
        ]);

        PublicLog::query()->create([
            'type' => 'admin_private_reply',
            'character_id' => $character->id,
            'receiver_id' => $character->id,
            'message' => '確認しました。',
        ]);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->assertSee('管理人から確認したいことがあります。')
            ->assertSee('確認しました。');
    }

    public function test_admin_message_rejects_a_character_outside_the_selected_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '本来の対象',
            'explore_stamina' => 0,
        ]);
        $otherCharacter = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '別ユーザーの冒険者',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->set('selectedCharacterId', $otherCharacter->id)
            ->set('adminMessage', '誤送信されてはいけない内容')
            ->call('sendAdminMessage')
            ->assertHasErrors(['adminMessage']);

        $this->assertDatabaseMissing('public_logs', [
            'type' => 'admin_private',
            'receiver_id' => $otherCharacter->id,
            'message' => '誤送信されてはいけない内容',
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $otherCharacter->id,
            'type' => 'admin_private_message',
        ]);
    }

    public function test_admin_is_notified_while_the_latest_thread_message_is_a_player_reply(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '返信通知対象',
            'explore_stamina' => 0,
        ]);
        $logService = app(PublicLogService::class);

        $logService->addAdminPrivateMessage('状況を教えてください。', $character);
        $logService->addAdminPrivateReply('確認して返信しました。', $character);
        $logService->addAdminPrivateReply('追加情報もあります。', $character);

        $this->assertSame(1, $logService->pendingAdminReplyCount());
        $this->assertSame('追加情報もあります。', $logService->pendingAdminReplies(1)->first()?->message);

        $this->actingAs($admin)
            ->getJson(route('admin.private-replies.status'))
            ->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('latest_reply.character_name', '返信通知対象')
            ->assertJsonPath('latest_reply.message', '追加情報もあります。')
            ->assertJsonPath('replies.0.message', '追加情報もあります。')
            ->assertJsonPath(
                'latest_reply.resolve_url',
                route('admin.private-replies.resolve', ['reply' => $logService->pendingAdminReplies(1)->first()])
            )
            ->assertJsonPath(
                'latest_reply.url',
                route('admin.user-investigation', ['user_id' => $targetUser->id]) . '#investigation-message'
            );

        $this->get(route('admin.user-investigation', ['user_id' => $targetUser->id]))
            ->assertOk()
            ->assertSee('admin-private-reply-bell', false)
            ->assertSee('管理人個別メッセージの返信を確認')
            ->assertSee('data-admin-reply-popover', false)
            ->assertSee('ユーザー調査ですべて確認')
            ->assertSee('対応済みにする')
            ->assertSee('data-admin-reply-badge', false);

        $logService->addAdminPrivateMessage('追加情報を確認しました。', $character);

        $this->assertSame(0, $logService->pendingAdminReplyCount());
        $this->getJson(route('admin.private-replies.status'))
            ->assertOk()
            ->assertJsonPath('pending_count', 0)
            ->assertJsonPath('latest_reply', null)
            ->assertJsonCount(0, 'replies');
    }

    public function test_private_reply_status_returns_the_latest_three_pending_threads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $logService = app(PublicLogService::class);

        foreach (range(1, 4) as $index) {
            $user = User::factory()->create();
            $character = Character::query()->create([
                'user_id' => $user->id,
                'name' => "返信対象{$index}",
                'explore_stamina' => 0,
            ]);
            $logService->addAdminPrivateReply("未対応返信{$index}", $character);
        }

        $this->actingAs($admin)
            ->getJson(route('admin.private-replies.status'))
            ->assertOk()
            ->assertJsonPath('pending_count', 4)
            ->assertJsonCount(3, 'replies')
            ->assertJsonPath('replies.0.character_name', '返信対象4')
            ->assertJsonPath('replies.0.message', '未対応返信4')
            ->assertJsonPath('replies.1.character_name', '返信対象3')
            ->assertJsonPath('replies.2.character_name', '返信対象2')
            ->assertJsonMissing(['character_name' => '返信対象1']);
    }

    public function test_private_reply_status_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.private-replies.status'))
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_resolve_a_pending_reply_without_deleting_the_conversation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '対応済み対象',
            'explore_stamina' => 0,
        ]);
        $logService = app(PublicLogService::class);
        $logService->addAdminPrivateReply('確認をお願いします。', $character);
        $reply = PublicLog::query()->where('type', 'admin_private_reply')->sole();

        $this->actingAs($admin)
            ->postJson(route('admin.private-replies.resolve', ['reply' => $reply]))
            ->assertOk()
            ->assertJson([
                'resolved' => true,
                'message' => '通知を対応済みにしました。会話履歴は残っています。',
            ]);

        $this->assertSame(0, $logService->pendingAdminReplyCount());
        $this->assertDatabaseHas('public_logs', [
            'id' => $reply->id,
            'type' => 'admin_private_reply',
            'receiver_id' => $character->id,
            'message' => '確認をお願いします。',
        ]);
        $this->assertDatabaseHas('public_logs', [
            'type' => 'admin_private_resolved',
            'character_id' => null,
            'receiver_id' => $character->id,
            'message' => "返信通知 #{$reply->id} を管理画面で対応済みにしました。",
        ]);
        $this->assertDatabaseCount('character_notifications', 0);

        $logService->addAdminPrivateReply('追加で確認したいことがあります。', $character);

        $this->assertSame(1, $logService->pendingAdminReplyCount());
        $this->assertSame('追加で確認したいことがあります。', $logService->pendingAdminReplies(1)->first()?->message);
    }

    public function test_resolving_the_same_reply_twice_is_rejected_without_duplicate_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '二重操作対象',
            'explore_stamina' => 0,
        ]);
        $logService = app(PublicLogService::class);
        $logService->addAdminPrivateReply('一度だけ対応済みにする返信', $character);
        $reply = PublicLog::query()->where('type', 'admin_private_reply')->sole();

        $this->actingAs($admin)
            ->postJson(route('admin.private-replies.resolve', ['reply' => $reply]))
            ->assertOk();

        $this->postJson(route('admin.private-replies.resolve', ['reply' => $reply]))
            ->assertStatus(409)
            ->assertJsonPath('resolved', false);

        $this->assertSame(
            1,
            PublicLog::query()
                ->where('type', 'admin_private_resolved')
                ->where('receiver_id', $character->id)
                ->count()
        );
    }

    public function test_resolve_private_reply_is_admin_only(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '権限確認対象',
            'explore_stamina' => 0,
        ]);
        app(PublicLogService::class)->addAdminPrivateReply('権限確認用の返信', $character);
        $reply = PublicLog::query()->where('type', 'admin_private_reply')->sole();

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.private-replies.resolve', ['reply' => $reply]))
            ->assertRedirect('/admin/login');

        $this->assertDatabaseMissing('public_logs', [
            'type' => 'admin_private_resolved',
            'receiver_id' => $character->id,
        ]);
    }

    public function test_resolution_audit_log_is_protected_from_regular_public_log_deletion(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '監査ログ保護対象',
            'explore_stamina' => 0,
        ]);
        $logService = app(PublicLogService::class);
        $logService->addAdminPrivateReply('監査ログ保護用の返信', $character);
        $logService->resolvePendingAdminReply(
            PublicLog::query()->where('type', 'admin_private_reply')->sole()
        );
        $auditLog = PublicLog::query()->where('type', 'admin_private_resolved')->sole();

        Livewire::test(PublicLogManager::class)
            ->call('deleteOne', $auditLog->id);

        $this->assertDatabaseHas('public_logs', [
            'id' => $auditLog->id,
            'type' => 'admin_private_resolved',
        ]);
    }
}
