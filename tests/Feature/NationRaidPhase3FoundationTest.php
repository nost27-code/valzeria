<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CompetitionEventCoordinator;
use App\Models\NationMembership;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationWarService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidParticipationSnapshotService;
use App\Services\Nation\Raid\NationRaidRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NationRaidPhase3FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_hp_is_frozen_in_each_event_and_new_format_missing_hp_is_rejected(): void
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('staged-hp-contract', '段階HP検証', now()->addDays(4));
        foreach (range(1, 20) as $stage) {
            $this->assertSame(10_000_000 * (1 + intdiv($stage - 1, 4)),
                $service->cycleParameterSnapshot($stage, $event)['boss']['max_hp']);
        }
        $snapshot = $event->ruleset_snapshot;
        $snapshot['stages'][4]['max_hp'] = 23_000_000;
        $event->ruleset_snapshot = $snapshot;
        $this->assertSame(23_000_000, $service->cycleParameterSnapshot(5, $event)['boss']['max_hp']);
        unset($snapshot['stages'][4]['max_hp']);
        $event->ruleset_snapshot = $snapshot;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('HP snapshot');
        $service->cycleParameterSnapshot(5, $event);
    }

    public function test_legacy_uniform_hp_snapshot_does_not_adopt_new_stage_hp(): void
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('legacy-hp-contract', '旧HP検証', now()->addDays(4));
        $snapshot = $event->ruleset_snapshot;
        $snapshot['version'] = 'nation-raid-phase1-v4-equipment-resistance';
        foreach ($snapshot['stages'] as &$stage) {
            unset($stage['max_hp']);
        }
        unset($stage);
        $event->ruleset_snapshot = $snapshot;
        $event->cycle_max_hp = 5_000_000;
        $this->assertSame(5_000_000, $service->cycleParameterSnapshot(20, $event)['boss']['max_hp']);
    }

    public function test_phase_three_schema_and_singleton_coordinator_exist(): void
    {
        foreach ([
            'competition_event_coordinators',
            'nation_raid_events',
            'nation_raid_boss_cycles',
            'nation_raid_participations',
            'nation_raid_daily_usages',
            'nation_raid_battle_results',
            'nation_raid_daily_lineage_snapshots',
            'nation_raid_coordination_participants',
            'nation_raid_personal_rewards',
            'nation_raid_nation_rewards',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing Phase 3 table: {$table}");
        }

        $coordinator = CompetitionEventCoordinator::query()->sole();
        $this->assertSame(CompetitionEventCoordinator::GLOBAL_SLOT, $coordinator->slot_key);
        $this->assertNull($coordinator->active_type);
        $this->assertSame(0, $coordinator->lock_version);
    }

    public function test_unapproved_balance_cannot_be_scheduled_and_admin_approval_is_audited(): void
    {
        $start = CarbonImmutable::parse('2030-01-10 09:00:00', config('app.timezone'));
        $event = app(NationRaidEventService::class)->createDraft('valgreid-2030-01', '国家対抗レイド', $start);

        $this->assertSame(NationRaidEvent::STATUS_DRAFT, $event->status);
        $this->assertSame(20, $event->stage_count);
        $this->assertSame(10_000_000, $event->cycle_max_hp);
        $this->assertSame(600_000_000, $event->total_target_hp);
        $this->assertSame(168, (int) $event->starts_at->diffInHours($event->ends_at));
        $this->assertSame(app(NationRaidRules::class)->rulesetHash(), $event->ruleset_hash);

        try {
            app(NationRaidEventService::class)->schedule($event, $start->subHours(72));
            $this->fail('An unapproved balance must not be scheduled.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('バランス裁定', $exception->getMessage());
        }

        $player = User::factory()->create(['role' => 'user']);
        try {
            app(NationRaidEventService::class)->approveBalance($event, $player, 'not allowed');
            $this->fail('A normal user must not approve raid balance.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('管理者', $exception->getMessage());
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $approved = app(NationRaidEventService::class)->approveBalance(
            $event,
            $admin,
            'docs/decisions/approved-phase2-balance.md#sha256:example',
        );
        $scheduled = app(NationRaidEventService::class)->schedule($approved, $start->subHours(72));

        $this->assertSame(NationRaidEvent::STATUS_SCHEDULED, $scheduled->status);
        $this->assertSame($admin->id, $scheduled->balance_approved_by_user_id);
        $this->assertNotNull($scheduled->balance_approved_at);
        $this->assertSame('nation_raid', CompetitionEventCoordinator::query()->sole()->active_type);
    }

    public function test_activation_freezes_account_and_nation_eligibility_and_creates_first_cycle(): void
    {
        $start = CarbonImmutable::parse('2030-02-10 09:00:00', config('app.timezone'));
        $publication = $start->subHours(72);
        $this->enableRaidPreflight();

        $active = $this->character(User::factory()->create(), '現役国民', $publication->subHour());
        $nation = app(NationService::class)->create($active, '蒼竜');
        $inactive = $this->character(User::factory()->create(), '休眠国民', $publication->subDays(8));
        NationMembership::query()->create([
            'nation_id' => $nation->id,
            'character_id' => $inactive->id,
            'role' => 'citizen',
            'joined_at' => $publication->subDay(),
        ]);
        $unaffiliated = $this->character(User::factory()->create(), '無所属', $publication->subHour());
        $this->character(User::factory()->create(['role' => 'admin']), '管理者', $publication->subHour());
        $this->character(User::factory()->create([
            'email' => 'tester_phase3@valzeria.local',
        ]), '検証用', $publication->subHour());
        $this->character(User::factory()->create([
            'email' => 'guest_123e4567-e89b-12d3-a456-426614174999@example.com',
            'password' => null,
            'google_id' => null,
        ]), 'ゲスト', $publication->subHour());
        $this->character(User::factory()->create(), '凍結', $publication->subHour(), true);

        $event = $this->approvedDraft('valgreid-2030-02', $start);
        $event = app(NationRaidEventService::class)->schedule($event, $publication);
        $this->assertSame(1, $event->published_nation_counts_snapshot[(string) $nation->id]['active_count']);

        $activated = app(NationRaidEventService::class)->activate($event, $start);

        $this->assertSame(NationRaidEvent::STATUS_ACTIVE, $activated->status);
        $this->assertSame(1, $activated->current_cycle_no);
        $this->assertEqualsCanonicalizing(
            ['休眠国民', '現役国民', '無所属'],
            $activated->participations->pluck('character_name_snapshot')->all(),
        );
        $activeSnapshot = NationRaidParticipation::query()->where('character_id', $active->id)->sole();
        $inactiveSnapshot = NationRaidParticipation::query()->where('character_id', $inactive->id)->sole();
        $unaffiliatedSnapshot = NationRaidParticipation::query()->where('character_id', $unaffiliated->id)->sole();
        $this->assertTrue($activeSnapshot->is_nation_eligible);
        $this->assertSame(1, $activeSnapshot->reference_active_count);
        $this->assertFalse($inactiveSnapshot->is_nation_eligible);
        $this->assertSame($nation->id, $inactiveSnapshot->nation_id);
        $this->assertFalse($unaffiliatedSnapshot->is_nation_eligible);
        $this->assertNull($unaffiliatedSnapshot->nation_id);

        $cycle = NationRaidBossCycle::query()->sole();
        $this->assertSame(NationRaidBossCycle::KIND_MAIN, $cycle->cycle_kind);
        $this->assertSame(1, $cycle->stage_no);
        $this->assertNull($cycle->echo_no);
        $this->assertSame(NationRaidRules::BOSS_MAX_HP, $cycle->current_hp);
        $this->assertSame(NationRaidRules::FORM_SEALED_SCALE, $cycle->current_form);
        $this->assertSame(NationRaidRules::BOSS_SPECIES_KEY, $cycle->boss_species_key);
        $this->assertSame($activated->ruleset_hash, $cycle->parameter_snapshot['ruleset_hash']);
        $this->assertSame($activated->ruleset_snapshot['stages'][0], $cycle->parameter_snapshot['stage']);
        $this->assertSame($activated->ruleset_snapshot['forms'], $cycle->parameter_snapshot['forms']);

        $this->assertSame(1, $activated->raidDayAt($start));
        $this->assertSame(7, $activated->raidDayAt($activated->ends_at->copy()->subSecond()));
        $this->assertNull($activated->raidDayAt($activated->ends_at));
        $this->assertTrue($activated->acceptsNewSortiesAt($start));
    }

    public function test_activation_rejects_a_tampered_ruleset_snapshot(): void
    {
        $start = CarbonImmutable::parse('2030-02-20 09:00:00', config('app.timezone'));
        $this->enableRaidPreflight();
        $event = app(NationRaidEventService::class)->schedule(
            $this->approvedDraft('valgreid-2030-02-tampered', $start),
            $start->subHours(72),
        );
        $snapshot = $event->ruleset_snapshot;
        $snapshot['fixed']['boss_defense'] = 9_999;
        $event->update(['ruleset_snapshot' => $snapshot]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('snapshotの整合性');
        app(NationRaidEventService::class)->activate($event->refresh(), $start);
    }

    public function test_late_entry_is_personal_only_and_cannot_replace_the_frozen_character(): void
    {
        $start = CarbonImmutable::parse('2030-02-25 09:00:00', config('app.timezone'));
        $this->enableRaidPreflight();
        $event = app(NationRaidEventService::class)->schedule(
            $this->approvedDraft('valgreid-2030-02-late', $start),
            $start->subHours(72),
        );
        $event = app(NationRaidEventService::class)->activate($event, $start);

        $user = User::factory()->create();
        $lateCharacter = $this->character($user, '遅れてきた国民', $start);
        $nation = app(NationService::class)->create($lateCharacter, '新興国');
        $participation = app(NationRaidParticipationSnapshotService::class)->createLateEntry($event, $lateCharacter);

        $this->assertTrue($participation->is_late_entry);
        $this->assertFalse($participation->is_nation_eligible);
        $this->assertNull($participation->nation_id);
        $this->assertSame(0, $participation->reference_active_count);
        $this->assertNotNull($nation->id);

        $replacement = $this->character($user, '差し替えCharacter', $start);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('参加Characterと一致しません');
        app(NationRaidParticipationSnapshotService::class)->createLateEntry($event, $replacement);
    }

    public function test_overlapping_raid_reservations_are_rejected_but_adjacent_windows_are_allowed(): void
    {
        $start = CarbonImmutable::parse('2030-03-10 09:00:00', config('app.timezone'));
        $first = app(NationRaidEventService::class)->schedule(
            $this->approvedDraft('valgreid-2030-03-a', $start),
            $start->subHours(72),
        );
        $this->assertSame(NationRaidEvent::STATUS_SCHEDULED, $first->status);

        $overlap = $this->approvedDraft('valgreid-2030-03-b', $start->addDay());
        try {
            app(NationRaidEventService::class)->schedule($overlap, $start->subHours(73));
            $this->fail('Overlapping raid reservations must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('別の国家対抗レイド', $exception->getMessage());
        }

        $adjacent = $this->approvedDraft('valgreid-2030-03-c', $first->ends_at);
        $scheduled = app(NationRaidEventService::class)->schedule($adjacent, $start->subHours(73));
        $this->assertSame(NationRaidEvent::STATUS_SCHEDULED, $scheduled->status);

        try {
            NationRaidBossCycle::query()->create([
                'event_id' => $first->id,
                'cycle_no' => 1,
                'cycle_kind' => 'main',
                'stage_no' => 1,
                'max_hp' => 1,
                'current_hp' => 1,
                'current_form' => 'sealed_scale',
                'boss_species_key' => 'dragon',
                'parameter_snapshot' => [],
                'started_at' => $start,
            ]);
            NationRaidBossCycle::query()->create([
                'event_id' => $first->id,
                'cycle_no' => 1,
                'cycle_kind' => 'main',
                'stage_no' => 2,
                'max_hp' => 1,
                'current_hp' => 1,
                'current_form' => 'sealed_scale',
                'boss_species_key' => 'dragon',
                'parameter_snapshot' => [],
                'started_at' => $start,
            ]);
            $this->fail('The event/cycle unique key must reject duplicate cycle numbers.');
        } catch (QueryException) {
            $this->assertSame(1, NationRaidBossCycle::query()->where('event_id', $first->id)->count());
        }
    }

    public function test_scheduled_raid_blocks_nation_war_declaration_in_the_same_window(): void
    {
        $now = CarbonImmutable::parse('2030-04-01 09:00:00', config('app.timezone'));
        $this->travelTo($now);
        config()->set('features.nation_war_enabled', true);
        app(GameSettingService::class)->set('nation_war.declaration_enabled', '1');
        app(GameSettingService::class)->set('nation_war.reference_damage', '1000');

        $raidStart = $now->addDays(4);
        app(NationRaidEventService::class)->schedule(
            $this->approvedDraft('valgreid-2030-04', $raidStart),
            $now,
        );

        $attacker = $this->character(User::factory()->create(), '宣戦国王', $now);
        $defender = $this->character(User::factory()->create(), '防衛国王', $now);
        $attackerNation = app(NationService::class)->create($attacker, '宣戦国');
        $defenderNation = app(NationService::class)->create($defender, '防衛国');
        $attackerNation->update(['founded_at' => $now->subDays(8)]);
        $defenderNation->update(['founded_at' => $now->subDays(8)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('国家対抗レイドの予定期間');
        app(NationWarService::class)->declare(
            NationMembership::query()->where('character_id', $attacker->id)->sole(),
            $defenderNation,
        );
    }

    public function test_pause_resume_and_finalization_keep_new_sorties_closed_at_boundaries(): void
    {
        $start = CarbonImmutable::parse('2030-05-10 09:00:00', config('app.timezone'));
        $this->enableRaidPreflight();
        $event = app(NationRaidEventService::class)->schedule(
            $this->approvedDraft('valgreid-2030-05', $start),
            $start->subHours(72),
        );
        $event = app(NationRaidEventService::class)->activate($event, $start);

        $paused = app(NationRaidEventService::class)->pauseSorties($event, '必要flagの差分を検知');
        $this->assertFalse($paused->acceptsNewSortiesAt($start->addHour()));
        $this->assertSame('必要flagの差分を検知', $paused->sorties_pause_reason);

        $resumed = app(NationRaidEventService::class)->resumeSorties($paused);
        $this->assertTrue($resumed->acceptsNewSortiesAt($start->addHour()));

        $finalizing = app(NationRaidEventService::class)->beginFinalization($resumed, $resumed->ends_at);
        $this->assertSame(NationRaidEvent::STATUS_FINALIZING, $finalizing->status);
        $this->assertFalse($finalizing->acceptsNewSortiesAt($finalizing->ends_at));

        $this->travelTo($finalizing->ends_at->copy()->addMinutes(10));
        app(\App\Services\Nation\Raid\NationRaidDailyLineageService::class)->finalizeDue();
        $completed = app(NationRaidEventService::class)->completeFinalization($finalizing, $finalizing->ends_at->addMinutes(10));
        $this->assertSame(NationRaidEvent::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->finalized_at);
        $this->assertNull(CompetitionEventCoordinator::query()->sole()->active_type);
    }

    private function approvedDraft(string $eventKey, \DateTimeInterface $startsAt): NationRaidEvent
    {
        $event = app(NationRaidEventService::class)->createDraft($eventKey, '国家対抗レイド', $startsAt);
        $admin = User::factory()->create(['role' => 'admin']);

        return app(NationRaidEventService::class)->approveBalance(
            $event,
            $admin,
            'tests/Feature/NationRaidPhase3FoundationTest.php',
        );
    }

    private function enableRaidPreflight(): void
    {
        config()->set('features.nation_competitive_raid_enabled', true);
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', true);
        config()->set('features.nation_war_enabled', false);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
    }

    private function character(
        User $user,
        string $name,
        CarbonImmutable $lastBattleAt,
        bool $frozen = false,
    ): Character {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'last_battle_at' => $lastBattleAt,
            'is_frozen' => $frozen,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
        ]);
    }
}
