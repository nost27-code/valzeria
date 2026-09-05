<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidHistoryService;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NationRaidHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config(['features.nation_competitive_raid_enabled' => true]);
    }

    public function test_history_requires_authentication_and_the_public_gate(): void
    {
        $this->get('/nation-raid/history')->assertRedirect('/');
        $character = $this->character();
        $this->actingAs($character->user);
        config(['features.nation_competitive_raid_enabled' => false]);
        DB::enableQueryLog();
        $this->get('/nation-raid/history')->assertNotFound();
        $raidQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'nation_raid_'));
        DB::disableQueryLog();
        $this->assertCount(0, $raidQueries);
    }

    public function test_empty_history_and_no_active_event_have_a_permanent_navigation_link(): void
    {
        $this->actingAs($this->character()->user);
        $this->get('/nation-raid/history')->assertOk()->assertSee('未受取の報酬はありません。')
            ->assertSee('確定した戦果はまだありません。');
        $this->get(route('nation-raid.index'))->assertOk()->assertSee(route('nation-raid.history'), false);
    }

    public function test_history_uses_frozen_results_and_identity_instead_of_live_caches(): void
    {
        $character = $this->character();
        $event = $this->event($character, '凍結された戦果');
        $event->participations()->update(['character_id' => null, 'personal_damage_total' => 1,
            'max_action_damage' => 1, 'resolved_sorties' => 1]);
        $character->update(['name' => '現在の名前']);
        $this->actingAs($character->user)->get('/nation-raid/history')->assertOk()
            ->assertSee('凍結された戦果')->assertSee('当時の冒険者')->assertSee('当時の国家')
            ->assertSee('750,000')->assertSee('50,000')->assertSee('15回')->assertSee('2位')
            ->assertSee(route('nation-raid.rewards', $event), false);
    }

    public function test_other_accounts_and_a_replaced_character_never_leak_into_the_list(): void
    {
        $character = $this->character();
        $own = $this->event($character, '本人の戦果');
        $this->reward($own, $character, '本人の報酬');
        $other = $this->character();
        $hidden = $this->event($other, '他人の秘密の戦果');
        $this->reward($hidden, $other, '他人の秘密の報酬');
        // Same account, different immutable Character identity: not transferable.
        $old = $this->event($character, '旧キャラクターの戦果');
        $old->participations()->update(['character_id_snapshot' => $character->id + 1000]);
        $this->reward($old, $character, '旧キャラクターの報酬')->update(['character_id_snapshot' => $character->id + 1000]);
        $this->actingAs($character->user)->get('/nation-raid/history?character_id='.$other->id.'&account_id='.$other->user_id)
            ->assertOk()->assertSee('本人の戦果')->assertSee('本人の報酬')
            ->assertDontSee('他人の秘密')->assertDontSee('旧キャラクター');
    }

    public function test_only_completed_events_are_listed_and_viewing_does_not_claim(): void
    {
        $character = $this->character();
        foreach (['draft', 'scheduled', 'active', 'finalizing', 'cancelled'] as $status) {
            $event = $this->event($character, '非公開-'.$status);
            $event->update(['status' => $status]);
            $this->reward($event, $character, '非公開の報酬-'.$status);
        }
        $event = $this->event($character, '終了したレイド');
        $reward = $this->reward($event, $character, '未受取の参加報酬');
        $this->actingAs($character->user);
        $before = $character->fresh()->getAttributes();
        foreach (range(1, 2) as $visit) {
            $this->get('/nation-raid/history')->assertOk()->assertSee('未受取の参加報酬')
                ->assertDontSee('非公開-')->assertDontSee('非公開の報酬')
                ->assertSee(route('nation-raid.rewards', $event).'#raid-reward-'.$reward->id, false);
        }
        $this->assertSame('pending', $reward->fresh()->status);
        $this->assertSame($before, $character->fresh()->getAttributes());
        foreach (['character_consumable_items', 'kiseki_transactions', 'character_notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_claimed_reward_disappears_but_past_result_remains_without_expiry(): void
    {
        $character = $this->character();
        $event = $this->event($character, 'ずっと残る戦果');
        $reward = $this->reward($event, $character, '受取前だけの見出し');
        $this->travel(2)->years();
        $this->actingAs($character->user)->get('/nation-raid/history')->assertOk()->assertSee('受取前だけの見出し');
        app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        $this->get('/nation-raid/history')->assertOk()->assertSee('ずっと残る戦果')
            ->assertSee('未受取の報酬はありません。')->assertDontSee('受取前だけの見出し');
    }

    public function test_pagination_is_separate_bounded_and_deterministic(): void
    {
        $character = $this->character();
        foreach (range(1, 12) as $number) {
            $event = $this->event($character, sprintf('履歴%02d', $number));
            $this->reward($event, $character, sprintf('報酬%02d', $number));
        }
        $this->actingAs($character->user);
        $this->get('/nation-raid/history')->assertOk()->assertViewHas('history', fn ($page) => $page->count() === 10)
            ->assertViewHas('pendingRewards', fn ($page) => $page->count() === 10)->assertSee('履歴12')->assertDontSee('履歴01');
        $this->get('/nation-raid/history?history_page=2&rewards_page=2')->assertOk()
            ->assertViewHas('history', fn ($page) => $page->count() === 2)
            ->assertViewHas('pendingRewards', fn ($page) => $page->count() === 2)->assertSee('履歴01')->assertDontSee('履歴12');
    }

    public function test_a_damaged_final_snapshot_is_not_recalculated_or_presented_as_zero(): void
    {
        $character = $this->character();
        $event = $this->event($character, '要確認の戦果');
        $event->update(['final_standings_hash' => str_repeat('0', 64)]);
        $this->actingAs($character->user)->get('/nation-raid/history')->assertOk()
            ->assertSee('戦果の記録を確認できません。時間をおいて確認してください。')->assertDontSee('750,000');
        $this->assertSame(str_repeat('0', 64), $event->fresh()->final_standings_hash);
    }

    public function test_rewards_page_links_back_to_history_and_has_reward_anchors(): void
    {
        $character = $this->character();
        $event = $this->event($character, '導線の戦果');
        $reward = $this->reward($event, $character, '参加報酬');
        $this->actingAs($character->user)->get(route('nation-raid.rewards', $event))->assertOk()
            ->assertSee(route('nation-raid.history'), false)->assertSee('id="raid-reward-'.$reward->id.'"', false);
    }

    public function test_missing_or_corrupt_personal_projection_never_falls_back_to_the_full_snapshot(): void
    {
        $character = $this->character();
        $event = $this->event($character, '投影欠損');
        $participant = $event->participations()->sole();
        $original = $participant->final_result_snapshot;
        $participant->update(['final_result_snapshot' => null, 'final_result_hash' => null]);
        $this->actingAs($character->user)->get('/nation-raid/history')->assertOk()->assertDontSee('750,000')
            ->assertSee('戦果の記録を確認できません');
        $original['record']['damage']++;
        $participant->update(['final_result_snapshot' => $original, 'final_result_hash' => str_repeat('0', 64)]);
        $this->get('/nation-raid/history')->assertOk()->assertDontSee('750,001')->assertSee('戦果の記録を確認できません');
    }

    public function test_completed_cli_rebuilds_only_missing_projections_without_granting_again(): void
    {
        $character = $this->character();
        $event = $this->event($character, '過去履歴の復元');
        $reward = $this->reward($event, $character, '保持される権利');
        $expected = $event->participations()->sole()->final_result_snapshot;
        $event->participations()->update(['final_result_snapshot' => null, 'final_result_hash' => null]);
        $dispatcher = DB::connection()->getEventDispatcher();
        $this->artisan('nation-raid:finalize', ['event' => $event->id, '--confirm-rewards' => true])->assertSuccessful();
        $this->assertSame($dispatcher, DB::connection()->getEventDispatcher());
        $this->assertSame($expected, $event->participations()->sole()->final_result_snapshot);
        $this->assertSame('pending', $reward->fresh()->status);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 1);
        $this->assertDatabaseCount('character_notifications', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_history_batches_relations_and_never_reads_battle_payloads(): void
    {
        $character = $this->character();
        foreach (range(1, 12) as $number) {
            $event = $this->event($character, '集計済み'.$number);
            $this->reward($event, $character, '集計済み報酬');
        }
        DB::enableQueryLog();
        $result = app(NationRaidHistoryService::class)->forCharacter($character);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(10, $result['history']);
        $this->assertLessThanOrEqual(6, count($queries));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('nation_raid_battle_results', $query['query']);
            $this->assertStringNotContainsString('final_standings_snapshot', $query['query']);
        }
        foreach ($result['history'] as $row) {
            $this->assertArrayNotHasKey('final_standings_snapshot', $row['event']->getAttributes());
        }
    }

    public function test_history_escapes_snapshot_names_and_reward_labels(): void
    {
        $character = $this->character();
        $name = '<script>alert("history")</script>';
        $event = $this->event($character, $name);
        $this->reward($event, $character, $name);
        $this->actingAs($character->user)->get('/nation-raid/history')->assertOk()
            ->assertSee(e($name), false)->assertDontSee($name, false);
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '履歴冒険者'.bin2hex(random_bytes(3))]);
    }

    private function event(Character $character, string $name): NationRaidEvent
    {
        $event = app(NationRaidEventService::class)->createDraft('history-'.bin2hex(random_bytes(6)), $name, now()->subDays(8)->startOfDay());
        $participation = NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => $character->user_id,
            'character_id' => $character->id, 'character_id_snapshot' => $character->id,
            'character_name_snapshot' => '当時の冒険者', 'nation_name_snapshot' => '当時の国家']);
        $row = ['participation_id' => $participation->id, 'account_id' => $character->user_id, 'character_id' => $character->id,
            'name' => '当時の冒険者', 'damage' => 750_000, 'resolved_sorties' => 15, 'max_action_damage' => 50_000,
            'rank' => 2, 'qualified' => true];
        $standings = ['is_final' => true, 'personal_total' => [$row], 'max_action' => [$row],
            'nation_total' => [], 'nation_per_capita' => [], 'unaffiliated_damage' => 0,
            'boss_damage' => 750_000, 'coordination_damage' => 0, 'resolved_sorties' => 15];
        $event->update(['status' => 'completed', 'activated_at' => $event->starts_at, 'finalized_at' => $event->ends_at,
            'final_standings_snapshot' => $standings, 'final_standings_hash' => app(NationRaidRewardPolicy::class)->hash($standings)]);
        DB::transaction(fn () => app(\App\Services\Nation\Raid\NationRaidFinalResultService::class)->storeLocked($event, $standings));

        return $event;
    }

    private function reward(NationRaidEvent $event, Character $character, string $label): NationRaidPersonalReward
    {
        return NationRaidPersonalReward::create(['event_id' => $event->id, 'character_id' => $character->id,
            'account_id_snapshot' => $character->user_id, 'character_id_snapshot' => $character->id,
            'reward_key' => 'participation', 'status' => 'pending', 'idempotency_key' => bin2hex(random_bytes(32)),
            'reward_snapshot' => ['label' => $label, 'bottles' => 3, 'policy_hash' => $event->reward_policy_hash]]);
    }
}
