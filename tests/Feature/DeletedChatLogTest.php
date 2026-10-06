<?php

namespace Tests\Feature;

use App\Livewire\Admin\DeletedChatLogManager;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\ChatMessageDeletionLog;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\ChatMessageDeletionService;
use App\Services\PublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DeletedChatLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function types(): array
    {
        return [['chat'], ['private']];
    }

    #[DataProvider('types')]
    public function test_deletion_preserves_evidence_once_with_identity_and_timestamps(string $type): void
    {
        [$sender, $receiver, $log] = $this->message($type);
        $service = app(ChatMessageDeletionService::class);
        $this->assertTrue($service->deleteOwnMessage($sender, $log->id));
        $this->assertFalse($service->deleteOwnMessage($sender, $log->id));
        $this->assertDatabaseMissing('public_logs', ['id' => $log->id]);
        $this->assertSame(1, ChatMessageDeletionLog::count());
        $archive = ChatMessageDeletionLog::firstOrFail();
        $this->assertSame($log->id, $archive->public_log_id);
        $this->assertSame($type, $archive->type);
        $this->assertSame($sender->id, $archive->character_id);
        $this->assertSame($sender->user_id, $archive->user_id);
        $this->assertSame('送信者', $archive->sender_name);
        $this->assertSame($log->message, $archive->message);
        $this->assertTrue($archive->sent_at->equalTo($log->created_at));
        $this->assertNotNull($archive->deleted_at);
        $this->assertSame($type === 'private' ? $receiver->id : null, $archive->receiver_id);
        $this->assertSame($type === 'private' ? $receiver->user_id : null, $archive->receiver_user_id);
        $this->assertSame($type === 'private' ? '受信者' : null, $archive->receiver_name);
        $this->assertFalse($service->deleteOwnMessage($receiver, $log->id));
    }

    public function test_archiving_failure_keeps_original_and_notification(): void
    {
        [$sender, $receiver, $log] = $this->message('private');
        $event = 'eloquent.creating: '.ChatMessageDeletionLog::class;
        Event::listen($event, fn () => throw new RuntimeException('archive failed'));
        try {
            app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);
            $this->fail('Deletion must fail when evidence cannot be saved.');
        } catch (RuntimeException $e) {
            $this->assertSame('archive failed', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseHas('public_logs', ['id' => $log->id]);
        $this->assertSame(1, CharacterNotification::where('character_id', $receiver->id)->count());
        $this->assertSame(0, ChatMessageDeletionLog::count());
    }

    public function test_failure_after_archiving_rolls_back_archive_and_notification_deletion(): void
    {
        [$sender, $receiver, $log] = $this->message('private');
        $event = 'eloquent.deleting: '.PublicLog::class;
        Event::listen($event, fn () => throw new RuntimeException('delete failed'));
        try {
            app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);
            $this->fail('Expected failed deletion.');
        } catch (RuntimeException $e) {
            $this->assertSame('delete failed', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseHas('public_logs', ['id' => $log->id]);
        $this->assertSame(1, CharacterNotification::where('character_id', $receiver->id)->count());
        $this->assertSame(0, ChatMessageDeletionLog::count());
    }

    public function test_foreign_and_non_chat_messages_do_not_create_evidence(): void
    {
        [$sender, $receiver, $log] = $this->message('chat');
        $service = app(ChatMessageDeletionService::class);
        $this->assertFalse($service->deleteOwnMessage($receiver, $log->id));
        foreach (['system', 'guild', 'admin', 'admin_private', 'admin_private_reply'] as $type) {
            $log->update(['type' => $type]);
            $this->assertFalse($service->deleteOwnMessage($sender, $log->id));
        }
        $this->assertSame(0, ChatMessageDeletionLog::count());
    }

    public function test_admin_can_search_snapshot_even_after_characters_change_and_body_is_escaped(): void
    {
        [$sender, $receiver, $log] = $this->message('private', '<script>alert("archive")</script>');
        app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);
        $sender->update(['name' => '改名後']);
        $receiver->delete();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.deleted-chat-logs'))->assertOk()->assertSee('送信者')->assertSee('受信者')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("archive")</script>', false);
        Livewire::test(DeletedChatLogManager::class)->set('searchQuery', '送信者')
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1)
            ->set('typeFilter', 'chat')->assertViewHas('logs', fn ($logs) => $logs->total() === 0)
            ->set('typeFilter', 'private')->set('searchQuery', (string) $sender->user_id)
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1)
            ->set('searchQuery', '存在しない名前')->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
        $this->assertSame(1, ChatMessageDeletionLog::count());
    }

    public function test_guest_and_normal_user_cannot_read_the_archive(): void
    {
        [$sender, , $log] = $this->message('chat');
        app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);
        $this->get(route('admin.deleted-chat-logs'))->assertRedirect();
        $this->actingAs($sender->user)->get(route('admin.deleted-chat-logs'))->assertRedirect('/admin/login');
        Livewire::test(DeletedChatLogManager::class)->assertForbidden();
    }

    public function test_revoked_admin_role_is_rechecked_on_component_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $screen = Livewire::test(DeletedChatLogManager::class);
        $admin->update(['role' => 'user']);
        $screen->call('$refresh')->assertForbidden();
    }

    public function test_rollback_cannot_drop_existing_evidence(): void
    {
        [$sender, , $log] = $this->message('chat');
        app(ChatMessageDeletionService::class)->deleteOwnMessage($sender, $log->id);
        $migration = require database_path('migrations/2026_10_07_090000_create_chat_message_deletion_logs_table.php');
        try {
            $migration->down();
            $this->fail('Existing evidence must be kept.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('証跡', $e->getMessage());
        }
        $this->assertSame(1, ChatMessageDeletionLog::count());
    }

    private function message(string $type, string $body = '保存する本文'): array
    {
        $sender = Character::create(['user_id' => User::factory()->create()->id, 'name' => '送信者']);
        $receiver = Character::create(['user_id' => User::factory()->create()->id, 'name' => '受信者']);
        app(PublicLogService::class)->addLog($type, $body, $sender, 1, $type === 'private' ? $receiver->id : null);
        $log = PublicLog::where('message', $body)->firstOrFail();

        return [$sender, $receiver, $log];
    }
}
