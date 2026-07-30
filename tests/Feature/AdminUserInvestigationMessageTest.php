<?php

namespace Tests\Feature;

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
                'latest_reply.url',
                route('admin.user-investigation', ['user_id' => $targetUser->id]) . '#investigation-message'
            );

        $this->get(route('admin.user-investigation', ['user_id' => $targetUser->id]))
            ->assertOk()
            ->assertSee('admin-private-reply-bell', false)
            ->assertSee('管理人個別メッセージの返信を確認')
            ->assertSee('data-admin-reply-popover', false)
            ->assertSee('ユーザー調査ですべて確認')
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
}
