<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminChatManager;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminChatPlayerChatExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_mount_the_admin_chat_component(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(AdminChatManager::class)
            ->assertForbidden();
    }

    public function test_copy_collects_only_adventurer_chat_messages_in_chronological_order(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $first = $this->character('アレス');
        $second = $this->character('ミリア');
        $adminCharacter = $this->character('運営キャラ', 'admin');

        $this->log('chat', 'マップの報酬が渋い気がする', '2026-07-20 18:03', $second);
        $this->log('chat', "装備の並び替えが\n欲しい", '2026-07-20 09:12', $first);
        $this->log('guild', 'ギルド発言は対象外', '2026-07-20 19:00', $first);
        $this->log('chat', '管理キャラの発言は除外される', '2026-07-20 19:10', $adminCharacter);
        $this->log('admin', '管理人からのお知らせ', '2026-07-20 19:20', null);
        $this->log('system', 'システム側の自動連絡', '2026-07-20 19:30', null);
        $this->log('private', '個人チャットは対象外', '2026-07-20 19:40', $first);

        $payload = null;

        Livewire::test(AdminChatManager::class)
            ->call('copyPlayerChat')
            ->assertDispatched('player-chat-extracted', function (string $event, array $params) use (&$payload): bool {
                $payload = $params;

                return true;
            });

        $this->assertSame(2, $payload['count']);

        $text = $payload['text'];
        $lines = explode("\n", $text);

        $this->assertStringContainsString('# ヴァルゼリアの冒険者 全体チャット 冒険者発言ログ', $lines[0]);
        $this->assertStringContainsString('件数: 2件', $text);
        $this->assertStringContainsString('対象期間: 2026-07-20 09:12 〜 2026-07-20 18:03', $text);

        $messageLines = array_values(array_filter(
            $lines,
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        $this->assertSame([
            '2026-07-20 09:12 【アレス】装備の並び替えが 欲しい',
            '2026-07-20 18:03 【ミリア】マップの報酬が渋い気がする',
        ], $messageLines);

        $this->assertStringNotContainsString('ギルド発言は対象外', $text);
        $this->assertStringNotContainsString('管理キャラの発言は除外される', $text);
        $this->assertStringNotContainsString('管理人からのお知らせ', $text);
        $this->assertStringNotContainsString('システム側の自動連絡', $text);
        $this->assertStringNotContainsString('個人チャットは対象外', $text);
    }

    public function test_copy_respects_the_selected_extract_limit_and_keeps_the_newest_messages(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $character = $this->character('アレス');

        for ($index = 1; $index <= 5; $index++) {
            $this->log('chat', '発言' . $index, '2026-07-20 10:0' . $index, $character);
        }

        $payload = null;

        Livewire::test(AdminChatManager::class)
            ->set('extractLimit', 200)
            ->call('copyPlayerChat')
            ->assertDispatched('player-chat-extracted', function (string $event, array $params) use (&$payload): bool {
                $payload = $params;

                return true;
            });

        $this->assertSame(5, $payload['count']);
        $this->assertStringContainsString('発言1', $payload['text']);
        $this->assertStringContainsString('発言5', $payload['text']);
    }

    public function test_unsupported_extract_limit_falls_back_to_the_default(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->log('chat', '発言', '2026-07-20 10:00', $this->character('アレス'));

        Livewire::test(AdminChatManager::class)
            ->set('extractLimit', 7)
            ->assertSet('extractLimit', 1000)
            ->call('copyPlayerChat')
            ->assertSet('extractLimit', 1000)
            ->assertDispatched('player-chat-extracted');
    }

    public function test_copy_reports_an_error_when_there_is_no_adventurer_chat(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->log('admin', '管理人からのお知らせ', '2026-07-20 19:20', null);

        Livewire::test(AdminChatManager::class)
            ->call('copyPlayerChat')
            ->assertNotDispatched('player-chat-extracted')
            ->assertSee('コピーできる冒険者の発言がありません。');
    }

    public function test_admin_chat_page_shows_the_extract_controls(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('admin.chat'))
            ->assertOk()
            ->assertSee('冒険者の発言だけ抽出（AI貼り付け用）')
            ->assertSee('発言をコピー')
            ->assertSee('遡る件数')
            ->assertSee('1,000件')
            ->assertSee('個人情報を含む可能性があります');
    }

    private function character(string $name, ?string $role = null): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create($role ? ['role' => $role] : [])->id,
            'name' => $name,
            'money' => 0,
            'explore_stamina' => 0,
        ]);
    }

    private function log(string $type, string $message, string $createdAt, ?Character $character): void
    {
        PublicLog::query()->create([
            'type' => $type,
            'message' => $message,
            'character_id' => $character?->id,
            'importance' => 1,
        ])->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }
}
