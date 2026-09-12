<?php

namespace Tests\Feature;

use App\Livewire\Admin\BugReportManager;
use App\Models\BugReport;
use App\Models\Character;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_submit_a_suggestion_from_the_shared_form(): void
    {
        [$user, $character] = $this->createPlayer();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('bug-reports.create'))
            ->assertOk()
            ->assertSee('ご意見・不具合を送る')
            ->assertSee('改善の要望')
            ->assertSee('不具合報告');

        $this->post(route('bug-reports.store'), [
            'kind' => BugReport::KIND_SUGGESTION,
            'body' => '国家レイドの改善案を管理人へ届けたいです。',
        ])
            ->assertRedirect(route('bug-reports.create'))
            ->assertSessionHas('status', '改善の要望を受け付けました。届けていただきありがとうございます。今後の改善検討に活用します。');

        $this->assertDatabaseHas('bug_reports', [
            'user_id' => $user->id,
            'character_id' => $character->id,
            'kind' => BugReport::KIND_SUGGESTION,
            'body' => '国家レイドの改善案を管理人へ届けたいです。',
            'status' => 'new',
        ]);
    }

    public function test_shared_form_rejects_an_unknown_kind(): void
    {
        [$user, $character] = $this->createPlayer();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('bug-reports.store'), [
                'kind' => 'other',
                'body' => 'この種類は保存されてはいけません。',
            ])
            ->assertSessionHasErrors('kind');

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_shared_form_submission_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('bug-reports.store');

        $this->assertNotNull($route);
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    public function test_admin_can_filter_suggestions_and_copy_the_review_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, $character] = $this->createPlayer();
        $legacyReport = BugReport::query()->create([
            'character_id' => $character->id,
            'body' => '探索後の表示が崩れます。',
            'status' => 'new',
        ]);
        $suggestion = BugReport::query()->create([
            'character_id' => $character->id,
            'kind' => BugReport::KIND_SUGGESTION,
            'body' => '国家レイドの作戦を共有しやすくしてほしいです。',
            'status' => 'new',
        ]);

        $this->actingAs($admin);

        $this->assertSame(BugReport::KIND_BUG, $legacyReport->fresh()->kind);

        Livewire::test(BugReportManager::class)
            ->set('kind', BugReport::KIND_SUGGESTION)
            ->assertSee('国家レイドの作戦を共有しやすくしてほしいです。')
            ->assertDontSee('探索後の表示が崩れます。')
            ->call('selectReport', $suggestion->id)
            ->assertSee('Codex検討用にコピー')
            ->assertSee('改善要望の検討依頼')
            ->assertSee('未確定の仕様や数値は推測せず、要裁定として示してください。');
    }

    public function test_admin_reply_to_a_suggestion_uses_a_suggestion_notification_context(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, $character] = $this->createPlayer();
        $suggestion = BugReport::query()->create([
            'character_id' => $character->id,
            'kind' => BugReport::KIND_SUGGESTION,
            'body' => '国家レイドの要望です。',
            'status' => 'new',
        ]);

        $this->actingAs($admin);

        Livewire::test(BugReportManager::class)
            ->call('selectReport', $suggestion->id)
            ->set('replyMessage', 'ご意見ありがとうございます。検討します。')
            ->call('sendReply')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => 'admin_private_message',
            'body' => '改善要望への返答: ご意見ありがとうございます。検討します。',
        ]);
    }

    private function createPlayer(): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '要望送信者',
            'explore_stamina' => 0,
        ]);
        $valmonMaster = ValmonMaster::query()->create([
            'valmon_key' => 'bug-report-suggestion-'.$character->id,
            'name' => '要望確認モン',
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
}
