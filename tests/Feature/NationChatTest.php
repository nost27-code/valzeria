<?php

namespace Tests\Feature;

use App\Livewire\ChatLog;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\NationMembership;
use App\Models\User;
use App\Services\Nation\NationChatService;
use App\Services\Nation\NationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class NationChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_persisted_and_read_only_by_current_members_of_the_same_nation(): void
    {
        $firstRuler = $this->character('第一国統治者');
        $firstNation = app(NationService::class)->create($firstRuler, '第一国');
        $firstCitizen = $this->character('第一国民');
        NationMembership::query()->create([
            'nation_id' => $firstNation->id,
            'character_id' => $firstCitizen->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);

        $secondRuler = $this->character('第二国統治者');
        app(NationService::class)->create($secondRuler, '第二国');
        $service = app(NationChatService::class);

        $firstMessage = $service->send($firstRuler, ' 第一国だけの相談です。 ', (string) Str::uuid());
        $service->send($secondRuler, '第二国だけの相談です。', (string) Str::uuid());

        $this->assertSame('第一国だけの相談です。', $firstMessage->message);
        $this->assertSame(
            ['第一国だけの相談です。'],
            $service->recentFor($firstCitizen)->pluck('message')->all(),
        );
        $this->assertSame(
            ['第二国だけの相談です。'],
            $service->recentFor($secondRuler)->pluck('message')->all(),
        );
        $this->assertDatabaseCount('public_logs', 0);
    }

    public function test_unaffiliated_and_former_members_cannot_read_or_send_nation_messages(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '秘密国');
        $formerMember = $this->character('元国民');
        $membership = NationMembership::query()->create([
            'nation_id' => $nation->id,
            'character_id' => $formerMember->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $outsider = $this->character('無所属');
        $service = app(NationChatService::class);
        $service->send($ruler, '国家機密です。', (string) Str::uuid());

        $this->assertCount(1, $service->recentFor($formerMember));
        $this->assertCount(0, $service->recentFor($outsider));
        $this->assertDomainFailure(
            fn () => $service->send($outsider, '侵入発言', (string) Str::uuid()),
            '国家へ所属していません',
        );

        $membership->delete();

        $this->assertCount(0, $service->recentFor($formerMember));
        $this->assertDomainFailure(
            fn () => $service->send($formerMember, '脱退後の発言', (string) Str::uuid()),
            '国家へ所属していません',
        );
    }

    public function test_repeated_request_id_is_idempotent(): void
    {
        $ruler = $this->character('統治者');
        app(NationService::class)->create($ruler, '二重防止国');
        $requestId = (string) Str::uuid();
        $service = app(NationChatService::class);

        $first = $service->send($ruler, '一度だけ送る', $requestId);
        $second = $service->send($ruler, '二重送信', $requestId);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('nation_chat_messages', 1);
        $this->assertDatabaseHas('nation_chat_messages', ['message' => '一度だけ送る']);
    }

    public function test_only_the_latest_fifty_messages_are_returned_newest_first(): void
    {
        $ruler = $this->character('統治者');
        app(NationService::class)->create($ruler, '履歴国');
        $service = app(NationChatService::class);

        foreach (range(1, 55) as $number) {
            $service->send($ruler, "発言{$number}", (string) Str::uuid());
        }

        $messages = $service->recentFor($ruler);

        $this->assertCount(50, $messages);
        $this->assertSame('発言55', $messages->first()->message);
        $this->assertSame('発言6', $messages->last()->message);
    }

    public function test_nation_screen_sends_escaped_messages_and_does_not_expose_them_to_other_nations(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('画面統治者');
        $nation = app(NationService::class)->create($ruler, '画面国');
        $this->actingAs($ruler->user);

        Livewire::test(NationScreen::class)
            ->assertSee('国家チャット')
            ->assertSee('自国の国民だけが閲覧・送信できます')
            ->set('nationChatMessage', '<script>alert(1)</script>')
            ->call('sendNationChatMessage')
            ->assertHasNoErrors()
            ->assertSet('nationChatMessage', '')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSeeHtml('<script>alert(1)</script>');

        $this->assertDatabaseHas('nation_chat_messages', [
            'nation_id' => $nation->id,
            'character_id' => $ruler->id,
            'message' => '<script>alert(1)</script>',
        ]);

        $otherRuler = $this->character('他国統治者');
        app(NationService::class)->create($otherRuler, '他国');
        $this->actingAs($otherRuler->user);
        Livewire::test(NationScreen::class)
            ->assertSee('国家チャット')
            ->assertDontSee('<script>alert(1)</script>', false);

        $outsider = $this->character('無所属');
        $this->actingAs($outsider->user);
        Livewire::test(NationScreen::class)
            ->assertDontSee('国家チャット')
            ->set('nationChatMessage', '権限外発言')
            ->call('sendNationChatMessage')
            ->assertHasErrors(['nationAction']);
    }

    public function test_nation_screen_rejects_messages_longer_than_the_existing_chat_limit(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('統治者');
        app(NationService::class)->create($ruler, '文字数国');
        $this->actingAs($ruler->user);

        Livewire::test(NationScreen::class)
            ->set('nationChatMessage', str_repeat('国', 101))
            ->call('sendNationChatMessage')
            ->assertHasErrors(['nationChatMessage']);

        $this->assertDatabaseCount('nation_chat_messages', 0);
    }

    public function test_bottom_chat_nation_tab_reads_and_sends_only_for_the_current_nation(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('常設チャット統治者');
        $nation = app(NationService::class)->create($ruler, '常設チャット国');
        $otherRuler = $this->character('常設チャット他国');
        app(NationService::class)->create($otherRuler, '常設チャット他国領');
        $service = app(NationChatService::class);
        $service->send($ruler, '自国だけに見える相談', (string) Str::uuid());
        $service->send($otherRuler, '他国だけに見える相談', (string) Str::uuid());
        $this->actingAs($ruler->user);

        Livewire::test(ChatLog::class)
            ->assertSeeHtml('data-chat-nation-tab')
            ->call('setTab', 'nation')
            ->assertSet('activeTab', 'nation')
            ->assertSee('自国だけに見える相談')
            ->assertDontSee('他国だけに見える相談')
            ->assertSeeHtml('data-chat-nation-target')
            ->set('message', '常設欄から国家へ送信')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('message', '')
            ->assertSee('常設欄から国家へ送信');

        $this->assertDatabaseHas('nation_chat_messages', [
            'nation_id' => $nation->id,
            'character_id' => $ruler->id,
            'message' => '常設欄から国家へ送信',
        ]);
        $this->assertDatabaseCount('public_logs', 0);
    }

    public function test_bottom_chat_nation_tab_blocks_unaffiliated_players_and_stays_hidden_when_disabled(): void
    {
        config()->set('features.nation_community_enabled', true);
        $outsider = $this->character('常設チャット無所属');
        $this->actingAs($outsider->user);

        Livewire::test(ChatLog::class)
            ->call('setTab', 'nation')
            ->assertSet('activeTab', 'nation')
            ->assertSee('国家へ所属すると、自国の国民だけで会話できます。')
            ->assertSee('国家へ所属すると送信できます。')
            ->assertDontSee('国家へメッセージ')
            ->set('message', '所属外からの発言')
            ->call('sendMessage')
            ->assertHasErrors(['message']);

        $this->assertDatabaseCount('nation_chat_messages', 0);

        config()->set('features.nation_community_enabled', false);

        Livewire::test(ChatLog::class)
            ->assertDontSeeHtml('data-chat-nation-tab')
            ->set('activeTab', 'nation')
            ->assertSet('activeTab', 'all')
            ->call('setTab', 'nation')
            ->assertSet('activeTab', 'all');
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'level' => 50,
            'last_battle_at' => now(),
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
        ]);
    }

    private function assertDomainFailure(\Closure $action, string $messagePart): void
    {
        try {
            $action();
            $this->fail('DomainException was not thrown.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());
        }
    }
}
