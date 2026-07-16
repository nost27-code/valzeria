<?php

namespace Tests\Feature;

use App\Livewire\Admin\BugReportManager;
use App\Livewire\Admin\PrivateChatLogManager;
use App\Livewire\MessageBox;
use App\Models\BugReport;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportAdminReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reply_and_read_the_reporter_thread(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reporterUser = User::factory()->create();
        $reporter = Character::query()->create([
            'user_id' => $reporterUser->id,
            'name' => '報告者冒険者',
            'explore_stamina' => 0,
        ]);
        $report = BugReport::query()->create([
            'user_id' => $reporterUser->id,
            'character_id' => $reporter->id,
            'body' => '不具合を報告します。',
            'status' => 'new',
        ]);

        $this->actingAs($admin);

        Livewire::test(BugReportManager::class)
            ->call('selectReport', $report->id)
            ->set('replyMessage', 'ご報告ありがとうございます。調査します。')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertSet('replyMessage', '');

        $this->assertDatabaseHas('public_logs', [
            'type' => 'admin_private',
            'character_id' => null,
            'receiver_id' => $reporter->id,
            'message' => 'ご報告ありがとうございます。調査します。',
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $reporter->id,
            'type' => 'admin_private_message',
        ]);

        PublicLog::query()->create([
            'type' => 'admin_private_reply',
            'character_id' => $reporter->id,
            'receiver_id' => $reporter->id,
            'message' => '再現手順を追加しました。',
        ]);

        Livewire::test(BugReportManager::class)
            ->call('selectReport', $report->id)
            ->assertSee('管理人')
            ->assertSee('再現手順を追加しました。');

        Livewire::test(PrivateChatLogManager::class)
            ->assertSee('管理人')
            ->assertSee('ご報告ありがとうございます。調査します。');
    }

    public function test_reporter_can_reply_to_the_admin_thread(): void
    {
        $reporterUser = User::factory()->create();
        $reporter = Character::query()->create([
            'user_id' => $reporterUser->id,
            'name' => '報告者冒険者',
            'explore_stamina' => 0,
        ]);
        PublicLog::query()->create([
            'type' => 'admin_private',
            'message' => '運営からの返答です。',
            'receiver_id' => $reporter->id,
            'importance' => 4,
        ]);

        $this->actingAs($reporterUser);

        Livewire::test(MessageBox::class)
            ->assertSee('管理人')
            ->call('openAdminConversation')
            ->assertSee('運営からの返答です。')
            ->set('message', '現在も同じ状態です。')
            ->call('confirmMessage')
            ->assertSet('confirmReceiverName', '管理人')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('message', '');

        $this->assertDatabaseHas('public_logs', [
            'type' => 'admin_private_reply',
            'character_id' => $reporter->id,
            'receiver_id' => $reporter->id,
            'message' => '現在も同じ状態です。',
        ]);
    }

    public function test_admin_thread_messages_are_not_returned_to_the_bottom_chat_log(): void
    {
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '受信者冒険者',
            'explore_stamina' => 0,
        ]);
        $otherCharacter = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '別の冒険者',
            'explore_stamina' => 0,
        ]);
        $service = app(PublicLogService::class);
        $service->addAdminPrivateMessage('非公開の管理人メッセージ', $recipient);
        $service->addAdminPrivateReply('非公開の冒険者返信', $recipient);

        $this->assertTrue($service->getRecentLogs(50, $recipient->id)
            ->every(fn (PublicLog $log) => ! in_array($log->type, ['admin_private', 'admin_private_reply'], true)));
        $this->assertTrue($service->getRecentLogs(50, $otherCharacter->id)
            ->every(fn (PublicLog $log) => ! in_array($log->type, ['admin_private', 'admin_private_reply'], true)));
    }
}
