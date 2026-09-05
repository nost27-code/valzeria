<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\Nation\Raid\NationRaidDailyLineageService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidLifecycleService;
use App\Services\Nation\Raid\NationRaidOperationsService;
use App\Services\Nation\Raid\NationRaidSettlementService;
use App\Services\Nation\Raid\NationRaidSortieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NationRaidLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config([
            'features.nation_competitive_raid_enabled' => true,
            'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true,
            'features.nation_war_enabled' => false,
        ]);
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

    public function test_empty_lifecycle_does_not_create_an_event_or_approve_balance(): void
    {
        $this->artisan('nation-raid:lifecycle')->assertSuccessful();
        $this->assertDatabaseCount('nation_raid_events', 0);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_scheduled_event_starts_at_its_boundary_and_retries_without_reinitializing(): void
    {
        $event = $this->scheduled(now()->addHour());
        $lifecycle = app(NationRaidLifecycleService::class);
        $this->assertSame(0, $lifecycle->advanceDue()['started']);
        $this->assertSame('scheduled', $event->fresh()->status);
        $this->travel(1)->hours();
        $this->assertSame(1, $lifecycle->advanceDue()['started']);
        $saved = $event->fresh()->getRawOriginal();
        $this->assertSame(0, $lifecycle->advanceDue()['started']);
        $this->assertSame($saved, $event->fresh()->getRawOriginal());
        $this->assertDatabaseCount('nation_raid_boss_cycles', 1);
        $this->assertDatabaseHas('nation_raid_boss_cycles', ['event_id' => $event->id, 'current_hp' => 10_000_000]);
    }

    public function test_off_gate_defers_start_and_a_missed_window_never_starts_or_auto_cancels(): void
    {
        $event = $this->scheduled(now());
        config()->set('features.nation_competitive_raid_enabled', false);
        $result = app(NationRaidLifecycleService::class)->advanceDue();
        $this->assertSame(1, $result['deferred']);
        $this->assertSame('scheduled', $event->fresh()->status);
        $this->travelTo($event->ends_at);
        config()->set('features.nation_competitive_raid_enabled', true);
        $result = app(NationRaidLifecycleService::class)->advanceDue();
        $this->assertSame(1, $result['missed']);
        $this->assertSame('scheduled', $event->fresh()->status);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_required_flag_or_unapproved_snapshot_cannot_be_bypassed_by_scheduler(): void
    {
        $event = $this->scheduled(now());
        config()->set('battle.job_art_v2.resources', false);
        $this->assertSame(1, app(NationRaidLifecycleService::class)->advanceDue()['failed']);
        config()->set('battle.job_art_v2.resources', true);
        $event->update(['balance_approved_at' => null]);
        $this->assertSame(1, app(NationRaidLifecycleService::class)->advanceDue()['failed']);
        $this->assertSame('scheduled', $event->fresh()->status);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_expired_active_event_closes_with_gate_off_and_refunds_only_after_deadline(): void
    {
        [$event, $character, $battle] = $this->pendingNearEnd();
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->travelTo($event->ends_at);
        $result = app(NationRaidLifecycleService::class)->advanceDue();
        $this->assertSame(1, $result['closing']);
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertSame('started', $battle->fresh()->status);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->artisan('nation-raid:recover-sorties')->assertSuccessful();
        $this->assertSame('started', $battle->fresh()->status);
        $this->travelTo($battle->resolution_deadline_at);
        $this->artisan('nation-raid:recover-sorties')->assertSuccessful();
        $this->artisan('nation-raid:recover-sorties')->assertSuccessful();
        $this->assertSame('refunded', $battle->fresh()->status);
        // 期限までの自然回復で250へ戻った後、消費した10を上限で切らず返す既存契約。
        $this->assertSame(260, $character->fresh()->explore_stamina);
        $this->assertSame(0, app(NationRaidLifecycleService::class)->advanceDue()['closing']);
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertNull($event->fresh()->finalized_at);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('nation_raid_nation_rewards', 0);
    }

    public function test_aborted_sortie_blocks_low_level_completion_until_refund_is_saved(): void
    {
        [$event, , $battle] = $this->pendingNearEnd();
        $battle->update(['status' => NationRaidBattleResult::STATUS_ABORTED, 'aborted_at' => now()]);
        $this->travelTo($event->ends_at);
        app(NationRaidEventService::class)->beginFinalization($event);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('未確定または未返却');
        app(NationRaidEventService::class)->completeFinalization($event);
    }

    public function test_manual_recovery_is_event_scoped_and_dashboard_does_not_expose_saved_payloads(): void
    {
        [$first, , $a] = $this->pendingNearEnd();
        $this->travelTo($first->ends_at);
        [$second, , $b] = $this->pendingNearEnd();
        $this->travelTo($b->resolution_deadline_at);
        $dashboard = app(NationRaidOperationsService::class)->dashboardData();
        $this->assertCount(2, $dashboard['pending']);
        $this->assertSame([1, 1], array_column($dashboard['events'], 'stale_count'));
        $this->assertStringNotContainsString($a->battle_token, json_encode($dashboard));
        $this->assertStringNotContainsString($b->sortie_seed, json_encode($dashboard));
        $this->assertSame(1, app(NationRaidSettlementService::class)->recoverExpired(eventId: $first->id)['refunded']);
        $this->assertSame('refunded', $a->fresh()->status);
        $this->assertSame('started', $b->fresh()->status);
        $this->assertSame(0, app(NationRaidSettlementService::class)->recoverExpired(eventId: $first->id)['refunded']);
        $this->assertSame(1, app(NationRaidSettlementService::class)->recoverExpired(eventId: $second->id)['refunded']);
    }

    private function scheduled(\DateTimeInterface $start): NationRaidEvent
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('lifecycle-'.bin2hex(random_bytes(4)), '国家対抗レイド', $start);
        $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test fixture only');
        return $service->schedule($event, $event->starts_at->copy()->subHours(72));
    }

    private function pendingNearEnd(): array
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '終了境界の冒険者', 'level' => 30,
            'hp_base' => 20_000, 'mp_base' => 500, 'attack_base' => 3_000, 'defense_base' => 3_000,
            'magic_base' => 500, 'spirit_base' => 3_000, 'speed_base' => 1_000, 'luck_base' => 100,
            'current_hp' => 10, 'current_mp' => 1, 'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(), 'last_battle_at' => now(),
        ]);
        $event = $this->scheduled(now());
        app(NationRaidEventService::class)->activate($event);
        $this->travelTo($event->ends_at->copy()->subSeconds(30));
        app(NationRaidDailyLineageService::class)->finalizeDue();
        [$battle] = app(NationRaidSortieService::class)->start($event->fresh(), $character, 'assault', bin2hex(random_bytes(32)));
        return [$event, $character, $battle];
    }
}
