<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidCoordinationParticipant;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidCoordinationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidPortalService;
use App\Services\Nation\Raid\NationRaidRankingService;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NationRaidPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(12, 0));
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_war_enabled' => false,
            'features.nation_community_enabled' => true, 'features.nation_development_enabled' => true]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
        $this->withoutMiddleware(CheckCharacterSelected::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_top_and_rankings_are_read_only_and_link_battle_and_rewards(): void
    {
        $character = $this->character();
        $event = $this->event();
        $characterBefore = $character->fresh()->getAttributes();
        $eventBefore = $event->getAttributes();
        $cycleBefore = $event->cycles()->sole()->getAttributes();
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->actingAs($character->user)->get(route('nation-raid.index'))
            ->assertRedirect(route('nation-raid.top', $event));
        foreach (['top', 'rankings', 'rewards'] as $page) {
            $response = $this->get(route('nation-raid.'.$page, $event))->assertOk()
                ->assertSee('data-nation-raid-navigation', false)
                ->assertSee('href="'.route('nation-raid.show', $event).'"', false)
                ->assertSee('href="'.route('nation-raid.rankings', $event).'"', false)
                ->assertSee('href="'.route('nation-raid.rewards', $event).'"', false);
            if ($page !== 'rewards') {
                $response->assertDontSee('name="battle_token"', false);
            }
        }
        $this->get(route('nation-raid.top', $event))->assertSee('10,000,000')->assertSee('第一形態');
        $this->get(route('nation-raid.rankings', $event))->assertSee('まだ国家の出撃記録がありません。');
        $this->assertSame([], $writes);
        $this->assertSame($characterBefore, $character->fresh()->getAttributes());
        $this->assertSame($eventBefore, $event->fresh()->getAttributes());
        $this->assertSame($cycleBefore, $event->cycles()->sole()->getAttributes());
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
        $this->assertDatabaseCount('nation_raid_coordination_participants', 0);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
    }

    public function test_rankings_include_coordination_keep_ties_and_highlight_frozen_nation(): void
    {
        $character = $this->character();
        $event = $this->event();
        $nation = $this->nation('現在の名ではなく保存名を使う');
        $own = NationRaidParticipation::where('event_id', $event->id)->where('account_id', $character->user_id)->sole();
        $own->update(['nation_id' => $nation->id, 'nation_id_snapshot' => $nation->id,
            'nation_name_snapshot' => '蒼天王国', 'is_nation_eligible' => true, 'reference_active_count' => 3]);
        $this->battle($own, 1000, 60);
        $this->battle($this->participant($event, '紅蓮王国'), 2000, 60);
        $this->battle($this->participant($event, '白銀王国'), 2060, 0);
        $this->battle($this->participant($event, '無所属', false), 99999, 0);
        foreach ([1, 2, 3] as $id) {
            $this->member($event, $nation->id, 100 + $id, now()->subMinutes($id));
        }
        $before = app(NationRaidRankingService::class)->standings($event);
        $portal = app(NationRaidPortalService::class)->build($event, $character);
        $this->assertSame([1, 1, 3], array_column($portal['nations'], 'rank'));
        $this->assertSame([2060, 2060, 1060], array_column($portal['nations'], 'damage'));
        $this->assertSame(1000, $portal['own_nation']['damage_gap']);
        $this->assertSame('蒼天王国', $portal['own_nation']['name']);
        $this->assertSame('3人共闘・+6%連携ボーナス中！', $portal['own_nation']['coordination']['steps'][0]['label']);
        $this->assertSame($before, app(NationRaidRankingService::class)->standings($event));
        // 解散等で生FKが失われても、開始時の国家帰属で自国を識別する。
        $own->update(['nation_id' => null]);
        $this->actingAs($character->user)->get(route('nation-raid.rankings', $event))->assertOk()
            ->assertSee('data-raid-own-nation', false)->assertSee('上の順位まであと1,000ダメージ')
            ->assertSee('3人共闘・+6%連携ボーナス中！')->assertSee('99,999')
            ->assertDontSee('現在の名ではなく保存名を使う');
    }

    public function test_coordination_uses_strict_three_hour_windows_and_a_batch_read_without_refreshing_them(): void
    {
        $event = $this->event();
        $nation = $this->nation('連携確認');
        $other = $this->event('other', false);
        // At 180 minutes exactly the member is expired; repeated sorties do not extend the window.
        foreach ([180, 179, 178, 177, 176, 175, 174] as $id => $minutes) {
            $this->member($event, $nation->id, 100 + $id, now()->subMinutes($minutes));
        }
        $this->member($other, $nation->id, 999, now());
        $this->member($event, $nation->id + 100, 998, now());
        $before = NationRaidCoordinationParticipant::all()->toArray();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $states = app(NationRaidCoordinationService::class)->liveForNations($event, [$nation->id]);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $steps = $states[$nation->id]['steps'];
        $this->assertSame([6, 5, 4, 3, 2, 1, 0], array_column($steps, 'count'));
        $this->assertSame([12, 12, 9, 6, 3, 0, 0], array_column($steps, 'percent'));
        $this->assertSame([0, 60000, 120000, 180000, 240000, 300000, 360000], array_column($steps, 'after_ms'));
        $this->assertFalse($steps[5]['active']);
        $this->assertSame($before, NationRaidCoordinationParticipant::all()->toArray());
    }

    public function test_badges_stop_at_event_end_and_do_not_advertise_paused_or_finished_events(): void
    {
        $event = $this->event();
        $nation = $this->nation('終了確認');
        $this->member($event, $nation->id, 101, now());
        $this->member($event, $nation->id, 102, now());
        $event->update(['ends_at' => now()->addSeconds(30)]);
        $service = app(NationRaidCoordinationService::class);
        $steps = $service->liveForNations($event, [$nation->id])[$nation->id]['steps'];
        $this->assertSame(30000, $steps[1]['after_ms']);
        $this->assertFalse($steps[1]['active']);
        $event->update(['sorties_paused_at' => now()]);
        $this->assertSame([], $service->liveForNations($event, [$nation->id]));
        $event->update(['sorties_paused_at' => null, 'ends_at' => now()]);
        $this->assertSame([], $service->liveForNations($event, [$nation->id]));
        foreach (['finalizing', 'completed', 'scheduled'] as $status) {
            $event->update(['status' => $status, 'ends_at' => now()->addHour()]);
            $this->assertSame([], $service->liveForNations($event, [$nation->id]));
        }
    }

    public function test_completed_ranking_remains_frozen_and_corruption_is_not_reported_as_zero(): void
    {
        $character = $this->character();
        $event = $this->event();
        $p = $this->participant($event, '<script>保存名</script>');
        $this->battle($p, 1000, 0);
        $standings = app(NationRaidRankingService::class)->standings($event);
        $standings['is_final'] = true;
        $event->update(['status' => 'completed', 'final_standings_snapshot' => $standings,
            'final_standings_hash' => app(NationRaidRewardPolicy::class)->hash($standings)]);
        $this->battle($p, 2000, 0);
        $this->actingAs($character->user)->get(route('nation-raid.rankings', $event))->assertOk()
            ->assertSee('最終順位')->assertSee('1,000')->assertDontSee('3,000')
            ->assertSee('&lt;script&gt;保存名&lt;/script&gt;', false)->assertDontSee('<script>保存名</script>', false)
            ->assertDontSee('連携ボーナス中！');
        $event->update(['final_standings_hash' => str_repeat('0', 64)]);
        $this->get(route('nation-raid.rankings', $event))->assertOk()->assertSee('戦績を確認中です。')
            ->assertDontSee('まだ国家の出撃記録がありません。');
    }

    public function test_publication_gate_and_event_visibility_apply_to_new_pages(): void
    {
        $character = $this->character();
        $event = $this->event();
        $this->actingAs($character->user);
        foreach (['draft', 'scheduled', 'cancelled'] as $status) {
            $event->update(['status' => $status]);
            foreach (['top', 'rankings'] as $page) {
                $this->get(route('nation-raid.'.$page, $event))->assertNotFound();
            }
        }
        $event->update(['status' => 'active']);
        config()->set('features.nation_competitive_raid_enabled', false);
        foreach (['top', 'rankings'] as $page) {
            $this->get(route('nation-raid.'.$page, $event))->assertNotFound();
        }
    }

    public function test_guest_cannot_read_new_pages_and_paused_top_keeps_other_destinations(): void
    {
        $event = $this->event();
        foreach (['top', 'rankings'] as $page) {
            $this->get(route('nation-raid.'.$page, $event))->assertRedirect();
        }
        $event->update(['sorties_paused_at' => now()]);
        $this->actingAs($this->character()->user)->get(route('nation-raid.top', $event))->assertOk()
            ->assertSee('出撃一時停止')->assertDontSee('装備を確認して出撃準備へ')
            ->assertSee('href="'.route('nation-raid.rankings', $event).'"', false)
            ->assertSee('href="'.route('nation-raid.rewards', $event).'"', false);
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '入口確認者',
            'level' => 30, 'last_battle_at' => now(), 'explore_stamina' => 250,
            'explore_stamina_max' => 250, 'explore_stamina_updated_at' => now()]);
    }

    private function event(string $key = 'portal', bool $activate = true): NationRaidEvent
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft($key, 'ヴァルグレイド討伐戦', now()->subHours(3));
        if (! $activate) {
            return $event;
        }
        $event = $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test fixture only');
        $event = $service->schedule($event, $event->starts_at->copy()->subHours(72));
        return $service->activate($event);
    }

    private function nation(string $name): Nation
    {
        return Nation::create(['name' => $name, 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now()]);
    }

    private function participant(NationRaidEvent $event, string $name, bool $eligible = true): NationRaidParticipation
    {
        $nation = $eligible ? $this->nation($name) : null;
        return NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => User::factory()->create()->id,
            'nation_id' => $nation?->id, 'nation_id_snapshot' => $nation?->id, 'is_nation_eligible' => $eligible,
            'reference_active_count' => 3, 'character_name_snapshot' => '冒険者', 'nation_name_snapshot' => $name]);
    }

    private function member(NationRaidEvent $event, int $nationId, int $characterId, $joined): void
    {
        $participation = NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => User::factory()->create()->id,
            'character_id_snapshot' => $characterId, 'nation_id_snapshot' => $nationId, 'character_name_snapshot' => '共闘者']);
        NationRaidCoordinationParticipant::create(['event_id' => $event->id, 'participation_id' => $participation->id, 'nation_id_snapshot' => $nationId,
            'character_id_snapshot' => $characterId, 'window_joined_at' => $joined, 'last_resolved_at' => now()]);
    }

    private function battle(NationRaidParticipation $p, int $damage, int $bonus): void
    {
        NationRaidBattleResult::create(['event_id' => $p->event_id, 'participation_id' => $p->id, 'account_id' => $p->account_id,
            'battle_token' => bin2hex(random_bytes(32)), 'sortie_seed' => str_repeat('a', 64), 'status' => 'resolved',
            'nation_id' => $p->nation_id, 'raid_day' => 1, 'day_sortie_no' => 1, 'event_sortie_no' => 1,
            'target_cycle_no' => 1, 'target_cycle_kind' => 'main', 'target_stage_no' => 1, 'target_form' => 'sealed_scale',
            'target_parameter_snapshot' => [], 'boss_species_key' => 'dragon', 'strategy' => 'boss_set',
            'calculated_damage_total' => $damage, 'applied_damage_total' => $damage, 'coordination_damage_total' => $bonus,
            'nation_damage_total' => $p->is_nation_eligible ? $damage + $bonus : 0, 'max_action_damage' => 10,
            'started_at' => now(), 'resolution_deadline_at' => now()->addMinutes(10), 'resolved_at' => now()]);
    }
}
