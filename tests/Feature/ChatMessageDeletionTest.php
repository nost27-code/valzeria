<?php

namespace Tests\Feature;

use App\Livewire\ChatLog;
use App\Livewire\MessageBox;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\ChatMessageDeletionService;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatMessageDeletionTest extends TestCase
{
    use RefreshDatabase;

    public static function chatSurfaces(): array
    {
        return [
            'inline public' => [ChatLog::class, false, 'chat'],
            'drawer public' => [ChatLog::class, true, 'chat'],
            'inline private' => [ChatLog::class, false, 'private'],
            'drawer private' => [ChatLog::class, true, 'private'],
            'private conversation' => [MessageBox::class, false, 'private'],
        ];
    }

    #[DataProvider('chatSurfaces')]
    public function test_own_message_is_deleted_only_after_confirmation(string $component, bool $drawer, string $type): void
    {
        $sender = $this->character('送信者');
        $receiver = $this->character('相手');
        $log = $this->log($sender, $receiver, $type, '削除する自分の発言');
        $this->login($sender);

        $screen = Livewire::test($component, $component === ChatLog::class ? ['drawer' => $drawer] : []);
        if ($component === MessageBox::class) {
            $screen->call('openConversation', $receiver->id);
        } else {
            $screen->call('setTab', $type === 'private' ? 'private' : 'chat');
        }
        $screen->set('message', '入力中の文面')->assertSee('削除する自分の発言')
            ->assertSeeHtml('wire:click="confirmDeleteMessage('.$log->id.')"')
            ->call('deleteMessage');
        $this->assertDatabaseHas('public_logs', ['id' => $log->id]);

        $screen->call('confirmDeleteMessage', $log->id)
            ->assertSet('pendingDeletionLogId', $log->id)
            ->assertSee('このメッセージを削除しますか？')
            ->call('cancelDeleteMessage')
            ->assertSet('pendingDeletionLogId', null);
        $this->assertDatabaseHas('public_logs', ['id' => $log->id]);

        $screen->call('confirmDeleteMessage', $log->id)
            ->call('deleteMessage')
            ->assertHasNoErrors()
            ->assertSet('pendingDeletionLogId', null)
            ->assertSet('message', '入力中の文面')
            ->assertSee('メッセージを削除しました。')
            ->assertDontSee('削除する自分の発言')
            ->assertDispatched('chat-message-deleted', logId: $log->id)
            ->call('deleteMessage');
        $this->assertDatabaseMissing('public_logs', ['id' => $log->id]);

        $this->login($receiver);
        Livewire::test(ChatLog::class)->call('setTab', $type === 'private' ? 'private' : 'chat')
            ->assertDontSee('削除する自分の発言');
    }

    public function test_other_peoples_messages_and_system_or_admin_logs_cannot_be_deleted(): void
    {
        $sender = $this->character('本人');
        $other = $this->character('他人');
        $this->login($sender);
        $screen = Livewire::test(ChatLog::class);
        $forbidden = [
            $this->log($other, $sender, 'chat', '他人の全体発言'),
            $this->log($other, $sender, 'private', '受け取った発言'),
        ];
        foreach (['system', 'drop', 'guild', 'admin', 'notice', 'admin_private', 'admin_private_reply'] as $type) {
            $forbidden[] = $this->log($sender, $other, $type, '削除対象外の'.$type);
        }

        foreach ($forbidden as $log) {
            $screen->call('confirmDeleteMessage', $log->id)
                ->assertSet('pendingDeletionLogId', null)
                ->assertHasErrors('chatDeletion')
                ->call('deleteMessage');
            $this->assertFalse(app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id));
            $this->assertDatabaseHas('public_logs', ['id' => $log->id]);
        }
        $screen->assertDontSeeHtml('wire:click="confirmDeleteMessage('.$forbidden[0]->id.')"');
    }

    public function test_pending_message_id_cannot_be_replaced_from_the_browser(): void
    {
        $sender = $this->character('本人');
        $other = $this->character('他人');
        $mine = $this->log($sender, $other, 'chat', '本人の発言');
        $foreign = $this->log($other, $sender, 'chat', '他人の発言');
        $this->login($sender);
        $screen = Livewire::test(ChatLog::class)->call('confirmDeleteMessage', $mine->id);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $screen->set('pendingDeletionLogId', $foreign->id);
    }

    public function test_ownership_is_rechecked_when_the_current_character_changes(): void
    {
        $user = User::factory()->create();
        $first = Character::create(['user_id' => $user->id, 'name' => '最初のキャラ']);
        $second = Character::create(['user_id' => $user->id, 'name' => '別キャラ']);
        $log = $this->log($first, $second, 'chat', '確認した発言');
        $this->login($first);
        $screen = Livewire::test(ChatLog::class)->call('confirmDeleteMessage', $log->id);
        $this->withSession(['current_character_id' => $second->id]);

        $screen->call('deleteMessage')->assertHasErrors('chatDeletion');
        $this->assertDatabaseHas('public_logs', ['id' => $log->id]);
    }

    public function test_deleting_a_private_message_removes_only_its_recipient_notification(): void
    {
        $sender = $this->character('送信者');
        $receiver = $this->character('受信者');
        $service = app(PublicLogService::class);
        $service->addLog('private', '取り消したい本文', $sender, 1, $receiver->id);
        $log = PublicLog::where('message', '取り消したい本文')->firstOrFail();
        $service->addLog('private', '残す本文', $sender, 1, $receiver->id);
        $otherNotification = CharacterNotification::where('body', 'like', '%残す本文%')->firstOrFail();

        $this->assertTrue(app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id));
        $this->assertFalse(app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id));
        $this->assertDatabaseMissing('character_notifications', ['body' => '送信者さんから: 取り消したい本文']);
        $this->assertDatabaseHas('character_notifications', ['id' => $otherNotification->id]);
        $this->assertDatabaseHas('public_logs', ['message' => '残す本文']);
    }

    public function test_a_message_deleted_elsewhere_during_confirmation_returns_a_visible_error(): void
    {
        $sender = $this->character('送信者');
        $receiver = $this->character('相手');
        $log = $this->log($sender, $receiver, 'chat', '確認中の発言');
        $this->login($sender);
        $screen = Livewire::test(ChatLog::class)->call('confirmDeleteMessage', $log->id);
        app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);

        $screen->call('deleteMessage')->assertHasErrors('chatDeletion')
            ->assertSet('pendingDeletionLogId', null)
            ->assertSee('メッセージは削除済みか、削除できない状態です。');
    }

    public function test_private_tab_finds_messages_even_when_the_public_feed_is_busy(): void
    {
        $sender = $this->character('本人');
        $other = $this->character('相手');
        $this->log($sender, $other, 'private', '見えるべき個人発言');
        $this->log($other, $other, 'private', '第三者の秘密');
        for ($index = 0; $index < 210; $index++) {
            $this->log($other, $sender, 'system', '新しい公開ログ'.$index);
        }
        $this->login($sender);

        Livewire::test(ChatLog::class)->call('setTab', 'private')
            ->assertSee('見えるべき個人発言')->assertDontSee('第三者の秘密');
    }

    public function test_private_and_admin_conversations_show_the_latest_120_messages_in_order(): void
    {
        $sender = $this->character('本人');
        $other = $this->character('相手');
        for ($index = 1; $index <= 121; $index++) {
            $this->log($sender, $other, 'private', '個人発言'.$index);
            $this->log($sender, $sender, 'admin_private_reply', '管理人宛'.$index);
        }
        $this->login($sender);

        $screen = Livewire::test(MessageBox::class)->call('openConversation', $other->id)
            ->assertSee('個人発言121')
            ->assertViewHas('threadMessages', fn ($messages) => $messages->count() === 120
                && $messages->first()->message === '個人発言2'
                && $messages->last()->message === '個人発言121');
        $screen->call('openAdminConversation')->assertSee('管理人宛121')
            ->assertViewHas('threadMessages', fn ($messages) => $messages->count() === 120
                && $messages->first()->message === '管理人宛2'
                && $messages->last()->message === '管理人宛121')
            ->assertDontSee('削除');
    }

    private function character(string $name): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => $name]);
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
    }

    private function log(Character $sender, Character $receiver, string $type, string $message): PublicLog
    {
        return PublicLog::create([
            'type' => $type,
            'character_id' => $sender->id,
            'receiver_id' => $type === 'chat' ? null : $receiver->id,
            'message' => $message,
        ]);
    }
}
