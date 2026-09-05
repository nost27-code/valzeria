<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\Nation\Raid\NationRaidDailyLineageService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidSettlementService;
use App\Services\Nation\Raid\NationRaidSortieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NationRaidDailyLineageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        foreach (['nation_competitive_raid_enabled', 'nation_community_enabled', 'nation_development_enabled'] as $flag) {
            config()->set("features.{$flag}", true);
        }
        config()->set('features.nation_war_enabled', false);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
    }

    protected function tearDown(): void
    {
        foreach (Character::query()->pluck('id') as $id) {
            CharacterStatusService::clearRequestCache((int) $id);
        }
        $this->travelBack();
        parent::tearDown();
    }

    public function test_draft_fixes_one_seed_for_all_days_and_activation_records_observation(): void
    {
        $event = app(NationRaidEventService::class)->createDraft('vote-seed', '国家対抗レイド', now());
        $rows = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->get();
        $this->assertCount(7, $rows);
        $this->assertCount(1, $rows->pluck('tie_break_seed')->unique());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $rows->first()->tie_break_seed);
        $this->assertTrue($rows->every(fn ($row) => $row->determined_at === null));
        $event = $this->activate($event);
        $first = $rows->firstWhere('raid_day', 1)->fresh();
        $this->assertNotNull($first->determined_at);
        $this->assertNull($first->selected_lineage);
        $this->assertSame([], $first->adopted_sets_snapshot);
    }

    public function test_only_first_resolved_set_votes_once_per_lineage_including_unaffiliated_and_late_entry(): void
    {
        $a = $this->character();
        $event = $this->event();
        $first = $this->resolved($event, $a, ['aim', 'aim', 'hunt']);
        $ignored = $this->resolved($event, $a, ['field']);
        $late = $this->character();
        $lateBattle = $this->resolved($event, $late, ['hunt']);
        $this->assertTrue($lateBattle->participation->is_late_entry);
        $this->assertSame(1, $first->summary['daily_resolution_no']);
        $this->assertSame(2, $ignored->summary['daily_resolution_no']);
        $this->travel(1)->days();
        $snapshot = app(NationRaidDailyLineageService::class)->finalizeDay($event, 2);
        $this->assertSame('hunt', $snapshot->selected_lineage);
        $this->assertSame(['aim' => 1, 'hunt' => 2], array_filter($snapshot->vote_counts));
        $this->assertSame([$first->id, $lateBattle->id], array_column($snapshot->adopted_sets_snapshot, 'battle_result_id'));
        $this->assertSame(5, count($snapshot->adopted_sets_snapshot[0]['slots']));
        $this->assertSame('hunt', app(NationRaidSortieService::class)->lineageForDay($event, 2));
        $this->assertSame('hunt', app(NationRaidSortieService::class)->fight($event, $a, 'intercept', bin2hex(random_bytes(32)))->dominant_lineage);
    }

    public function test_pending_previous_day_waits_until_refund_and_failed_sorties_have_no_votes(): void
    {
        $a = $this->character();
        $event = $this->event();
        $this->travelTo($event->starts_at->copy()->addDay()->subSecond());
        [$pending] = app(NationRaidSortieService::class)->start($event, $a, 'assault', bin2hex(random_bytes(32)));
        $this->travel(1)->seconds();
        $this->assertNull(app(NationRaidDailyLineageService::class)->finalizeDay($event, 2));
        $this->assertNull(NationRaidDailyLineageSnapshot::query()->where('raid_day', 2)->sole()->determined_at);
        $this->travel(10)->minutes();
        app(NationRaidSettlementService::class)->recoverExpired();
        $this->assertSame('refunded', $pending->fresh()->status);
        $snapshot = app(NationRaidDailyLineageService::class)->finalizeDay($event, 2);
        $this->assertNull($snapshot->selected_lineage);
        $this->assertSame([], $snapshot->adopted_sets_snapshot);
        $this->assertSame(0, array_sum($snapshot->vote_counts));
    }

    public function test_tie_is_reproducible_and_finalized_snapshot_is_not_rewritten(): void
    {
        $a = $this->character();
        $event = $this->event();
        $battle = $this->resolved($event, $a, ['aim', 'hunt']);
        $this->travel(1)->days();
        $service = app(NationRaidDailyLineageService::class);
        $first = $service->finalizeDay($event, 2);
        $order = $first->votes_snapshot['tie_break_order'];
        $expected = array_values(array_intersect($order, ['aim', 'hunt']))[0];
        $this->assertSame($expected, $first->selected_lineage);
        $saved = $first->getRawOriginal();
        $battle->update(['job_art_slots_snapshot' => $this->slots(['break'])]);
        $this->travel(1)->hours();
        $this->assertSame($saved, $service->finalizeDay($event, 2)->getRawOriginal());
    }

    public function test_future_day_cannot_be_finalized_and_invalid_slots_fail_closed(): void
    {
        $a = $this->character();
        $event = $this->event();
        $battle = $this->resolved($event, $a, ['aim']);
        $this->assertNull(app(NationRaidDailyLineageService::class)->finalizeDay($event, 2));
        $battle->update(['job_art_slots_snapshot' => null]);
        $this->travel(1)->days();
        try {
            app(NationRaidDailyLineageService::class)->finalizeDay($event, 2);
            $this->fail('Missing snapshot must not turn into zero votes.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('編成', $e->getMessage());
        }
        $this->assertNull(NationRaidDailyLineageSnapshot::query()->where('raid_day', 2)->sole()->determined_at);
    }

    public function test_daily_command_catches_up_and_repeats_without_new_rows(): void
    {
        $this->event();
        $this->travel(3)->days();
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->artisan('nation-raid:finalize-lineages')->assertSuccessful();
        $this->assertSame(4, NationRaidDailyLineageSnapshot::query()->whereNotNull('determined_at')->count());
        $before = NationRaidDailyLineageSnapshot::query()->get()->map->getRawOriginal()->all();
        $this->artisan('nation-raid:finalize-lineages')->assertSuccessful();
        $this->assertSame($before, NationRaidDailyLineageSnapshot::query()->get()->map->getRawOriginal()->all());
    }

    public function test_missing_resolution_order_and_mismatched_seed_do_not_publish_guessed_votes(): void
    {
        $a = $this->character();
        $event = $this->event();
        $battle = $this->resolved($event, $a, ['aim']);
        $summary = $battle->summary;
        unset($summary['daily_resolution_no']);
        $battle->update(['summary' => $summary]);
        $this->travel(1)->days();
        $this->artisan('nation-raid:finalize-lineages')->assertFailed();
        $this->assertNull(NationRaidDailyLineageSnapshot::query()->where('raid_day', 2)->sole()->determined_at);
        $battle->update(['summary' => [...$summary, 'daily_resolution_no' => 1]]);
        NationRaidDailyLineageSnapshot::query()->where('raid_day', 2)->update(['tie_break_seed' => str_repeat('f', 64)]);
        $this->artisan('nation-raid:finalize-lineages')->assertFailed();
        $this->assertNull(NationRaidDailyLineageSnapshot::query()->where('raid_day', 2)->sole()->determined_at);
    }

    private function resolved(NationRaidEvent $event, Character $character, array $lineages): NationRaidBattleResult
    {
        $battle = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $battle->status);
        // 投票fixtureだけを差し替える。実戦闘の編成はadmission時に保存する。
        $battle->update(['job_art_slots_snapshot' => $this->slots($lineages)]);
        return $battle;
    }

    private function slots(array $lineages): array
    {
        return array_map(fn ($slot) => [
            'slot' => $slot, 'skill_id' => isset($lineages[$slot - 1]) ? $slot : null,
            'exact_identity' => isset($lineages[$slot - 1]) ? "1:1:投票技{$slot}" : null,
            'canonical_lineage' => $lineages[$slot - 1] ?? null,
            'raid_lineage' => $lineages[$slot - 1] ?? null,
        ], range(1, 5));
    }

    private function character(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '投票冒険者'.bin2hex(random_bytes(3)), 'level' => 30,
            'hp_base' => 20_000, 'mp_base' => 500, 'attack_base' => 3_000, 'defense_base' => 3_000,
            'magic_base' => 500, 'spirit_base' => 3_000, 'speed_base' => 1_000, 'luck_base' => 100,
            'current_hp' => 10, 'current_mp' => 1, 'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(), 'last_battle_at' => now(),
        ]);
    }

    private function event(): NationRaidEvent
    {
        return $this->activate(app(NationRaidEventService::class)->createDraft('vote-'.bin2hex(random_bytes(6)), '国家対抗レイド', now()));
    }

    private function activate(NationRaidEvent $event): NationRaidEvent
    {
        $service = app(NationRaidEventService::class);
        $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test-only fixture');
        $service->schedule($event, now()->subHours(72));
        return $service->activate($event);
    }
}
