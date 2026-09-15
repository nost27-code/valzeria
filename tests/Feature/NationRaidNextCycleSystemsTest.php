<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\NationMembership;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidInvasionDamage;
use App\Models\NationRaidNationPreparation;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\Admin\NationRaidAnalyticsService;
use App\Services\Nation\NationRaidBattleTelemetryService;
use App\Services\Nation\NationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidLifecycleService;
use App\Services\Nation\Raid\NationRaidOutcomeService;
use App\Services\Nation\Raid\NationRaidPreparationService;
use App\Services\Nation\Raid\NationRaidReconstructionService;
use App\Services\Nation\Raid\NationRaidRewardService;
use App\Services\Nation\Raid\NationRaidSortieCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NationRaidNextCycleSystemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 2, 1)->setTime(9, 0));
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
        $this->travelBack();
        parent::tearDown();
    }

    public function test_logistics_freezes_active_members_and_applies_readiness_bonuses(): void
    {
        $character = $this->character();
        $nation = app(NationService::class)->create($character, '兵站確認国');
        $event = $this->scheduled(now()->addHours(72));

        app(NationRaidEventService::class)->freezePreparation($event, now());
        $preparation = NationRaidNationPreparation::query()->sole();
        $this->assertSame($nation->id, $preparation->nation_id_snapshot);
        $this->assertSame(1, $preparation->reference_active_count);
        $this->assertSame(2, $preparation->contribution_target);

        $service = app(NationRaidPreparationService::class);
        $loss = $service->recordNormalExplorationVictory($character, $this->battleLog($character, 'lose'), now()->addMinutes(30));
        $first = $service->recordNormalExplorationVictory($character, $this->battleLog($character), now()->addHour());
        $duplicate = $service->recordNormalExplorationVictory($character, $this->battleLog($character), now()->addHours(2));
        $second = $service->recordNormalExplorationVictory($character, $this->battleLog($character), now()->addDay()->addHour());

        $this->assertFalse($loss['recorded']);
        $this->assertTrue($first['recorded']);
        $this->assertFalse($duplicate['recorded']);
        $this->assertTrue($second['recorded']);
        $preparation->refresh();
        $this->assertSame(100, $preparation->readiness_percent);
        $this->assertSame(4, $preparation->earned_daily_free_grant);
        $this->assertSame(12, $preparation->earned_free_balance_cap);

        $this->travelTo($event->starts_at);
        app(NationRaidEventService::class)->activate($event->fresh(), now());
        $participation = NationRaidParticipation::query()->where('account_id', $character->user_id)->sole();
        $this->assertTrue($participation->is_nation_eligible);
        $this->assertSame(100, $participation->readiness_percent_snapshot);
        $this->assertSame(4, $participation->free_sortie_daily_grant_snapshot);
        $this->assertSame(12, $participation->free_sortie_balance_cap_snapshot);
    }

    public function test_free_sorties_are_spent_before_unlimited_voluntary_stamina_sorties_and_carry_over(): void
    {
        $character = $this->character();
        $saver = $this->character();
        $event = $this->activeEvent();
        $costs = app(NationRaidSortieCostService::class);
        $types = [];

        foreach (range(1, 4) as $attempt) {
            $cost = DB::transaction(function () use ($costs, $event, $character): array {
                $participant = NationRaidParticipation::query()->where('event_id', $event->id)
                    ->where('account_id', $character->user_id)->lockForUpdate()->firstOrFail();
                $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();

                return $costs->consumeLocked($event->fresh(), $participant, $lockedCharacter, 1);
            });
            $types[] = $cost['type'];
        }

        $this->assertSame(['daily_free', 'daily_free', 'daily_free', 'voluntary_stamina'], $types);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(3, NationRaidParticipation::query()
            ->where('account_id', $character->user_id)->sole()->free_sorties_used);

        $this->travel(1)->days();
        $nextDay = DB::transaction(function () use ($costs, $event, $character): array {
            $participant = NationRaidParticipation::query()->where('account_id', $character->user_id)
                ->lockForUpdate()->sole();
            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();

            return $costs->consumeLocked($event->fresh(), $participant, $lockedCharacter, 2);
        });
        $this->assertSame('daily_free', $nextDay['type']);
        $this->assertSame(3, $nextDay['free_balance_before']);
        $this->assertSame(2, $nextDay['free_balance_after']);
        $this->assertSame(250, $nextDay['stamina']['current']);
        $this->assertSame(240, $character->fresh()->explore_stamina);

        $this->travel(2)->days();
        $capped = DB::transaction(function () use ($costs, $event, $saver): array {
            $participant = NationRaidParticipation::query()->where('account_id', $saver->user_id)->lockForUpdate()->sole();
            $lockedCharacter = Character::query()->whereKey($saver->id)->lockForUpdate()->firstOrFail();

            return $costs->consumeLocked($event->fresh(), $participant, $lockedCharacter, 4);
        });
        $this->assertSame(9, $capped['free_balance_before']);
        $this->assertSame(8, $capped['free_balance_after']);

        $lateEntryStatus = $costs->status($event->fresh(), null, 4);
        $this->assertSame(9, $lateEntryStatus['free_balance']);
        $this->assertSame('daily_free', $lateEntryStatus['next_cost_type']);
    }

    public function test_started_raid_displays_the_preparation_applied_to_the_frozen_starting_nation(): void
    {
        $character = $this->character();
        $other = $this->character();
        app(NationService::class)->create($character, '移籍前国');
        $startingNation = app(NationService::class)->create($other, '開戦所属国');
        $event = $this->scheduled(now()->addHours(72));
        app(NationRaidEventService::class)->freezePreparation($event, now());
        NationMembership::query()->where('character_id', $character->id)->update(['nation_id' => $startingNation->id]);

        $this->travelTo($event->starts_at);
        app(NationRaidEventService::class)->activate($event->fresh(), now());
        $display = app(NationRaidPreparationService::class)->forCharacter($event->fresh(), $character);

        $this->assertSame($startingNation->fresh()->display_name, $display['nation_name']);
        $this->assertSame(0, $display['own_contribution_count']);
        $this->assertSame($startingNation->id, NationRaidParticipation::query()
            ->where('account_id', $character->user_id)->sole()->nation_id_snapshot);
    }

    public function test_non_ranking_rewards_become_claimable_during_the_raid_as_conditions_are_met(): void
    {
        $character = $this->character();
        $earlyCharacter = $this->character();
        $event = $this->activeEvent();
        $participation = NationRaidParticipation::query()->where('account_id', $character->user_id)->sole();
        $participation->update(['resolved_sorties' => 5, 'personal_damage_total' => 0]);

        DB::transaction(function () use ($event, $participation): void {
            $lockedEvent = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedParticipation = NationRaidParticipation::query()->whereKey($participation->id)->lockForUpdate()->firstOrFail();
            app(NationRaidRewardService::class)->prepareImmediateLocked($lockedEvent, $lockedParticipation);
        });

        $reward = NationRaidPersonalReward::query()->where('reward_key', 'participation')->sole();
        $this->assertSame('immediate', $reward->availability_type);
        $this->assertNotNull($reward->available_at);
        $this->assertDatabaseMissing('nation_raid_personal_rewards', ['reward_key' => 'personal_first']);

        app(NationRaidRewardService::class)->claim($event->fresh(), $character, $reward->id);
        $this->assertSame('claimed', $reward->fresh()->status);
        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => 'explore_stamina_small_bottle',
        ]);

        $earlyParticipation = NationRaidParticipation::query()->where('account_id', $earlyCharacter->user_id)->sole();
        $earlyParticipation->update(['resolved_sorties' => 15, 'personal_damage_total' => 2_000_000]);
        DB::transaction(function () use ($event, $earlyParticipation): void {
            app(NationRaidRewardService::class)->prepareImmediateLocked(
                NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail(),
                NationRaidParticipation::query()->whereKey($earlyParticipation->id)->lockForUpdate()->firstOrFail(),
            );
        });
        $this->assertDatabaseMissing('nation_raid_personal_rewards', [
            'character_id_snapshot' => $earlyCharacter->id,
            'reward_key' => 'stage10',
        ]);

        $event->update(['stage10_reached_at' => now()]);
        DB::transaction(function () use ($event): void {
            app(NationRaidRewardService::class)->prepareImmediateGlobalLocked(
                NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail(),
            );
        });
        $this->assertDatabaseHas('nation_raid_personal_rewards', [
            'character_id_snapshot' => $earlyCharacter->id,
            'reward_key' => 'stage10',
            'availability_type' => 'immediate',
        ]);

        $participation->update(['resolved_sorties' => 15, 'personal_damage_total' => 2_000_000]);
        DB::transaction(function () use ($event, $participation): void {
            app(NationRaidRewardService::class)->prepareImmediateLocked(
                NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail(),
                NationRaidParticipation::query()->whereKey($participation->id)->lockForUpdate()->firstOrFail(),
            );
        });
        $keys = NationRaidPersonalReward::query()->pluck('reward_key');
        $this->assertContains('damage2m', $keys);
        $this->assertContains('stage10', $keys);
        $this->assertNotContains('completion', $keys);
        $this->assertNotContains('personal_first', $keys);
    }

    public function test_invasion_damage_is_mitigated_and_normal_victories_complete_reconstruction(): void
    {
        $character = $this->character();
        $nation = app(NationService::class)->create($character, '復興確認国');
        $event = $this->draft(now()->subDays(8));
        $event->update([
            'status' => NationRaidEvent::STATUS_FINALIZING,
            'activated_at' => $event->starts_at,
            'finalization_started_at' => $event->ends_at,
            'total_target_hp' => 100,
            'current_cycle_no' => 1,
        ]);
        $preparation = NationRaidNationPreparation::query()->create([
            'event_id' => $event->id,
            'nation_id_snapshot' => $nation->id,
            'nation_id' => $nation->id,
            'nation_name_snapshot' => $nation->display_name,
            'reference_active_count' => 1,
            'contribution_target' => 2,
            'contribution_count' => 2,
            'readiness_percent' => 100,
            'earned_daily_free_grant' => 4,
            'earned_free_balance_cap' => 12,
            'applied_daily_free_grant' => 4,
            'applied_free_balance_cap' => 12,
            'frozen_at' => $event->starts_at->copy()->subHours(72),
            'finalized_at' => $event->starts_at,
        ]);
        NationRaidParticipation::query()->create([
            'event_id' => $event->id,
            'account_id' => $character->user_id,
            'character_id' => $character->id,
            'character_id_snapshot' => $character->id,
            'nation_id' => $nation->id,
            'nation_id_snapshot' => $nation->id,
            'is_nation_eligible' => true,
            'is_recently_active_snapshot' => true,
            'reference_active_count' => 1,
            'character_name_snapshot' => $character->name,
            'nation_name_snapshot' => $nation->display_name,
            'resolved_sorties' => 5,
        ]);
        NationRaidBossCycle::query()->create([
            'event_id' => $event->id,
            'cycle_no' => 1,
            'cycle_kind' => 'main',
            'stage_no' => 1,
            'max_hp' => 100,
            'current_hp' => 21,
            'current_form' => 'sealed_scale',
            'boss_species_key' => 'dragon',
            'parameter_snapshot' => [],
            'started_at' => $event->starts_at,
        ]);

        DB::transaction(function () use ($event): void {
            app(NationRaidOutcomeService::class)->finalizeLocked(
                NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail(),
            );
        });

        $this->assertSame('invasion', $event->fresh()->result_type);
        $this->assertSame(7900, $event->fresh()->result_progress_bps);
        $damage = NationRaidInvasionDamage::query()->sole();
        $this->assertSame($preparation->id, $damage->preparation_id);
        $this->assertSame(20, $damage->base_damage);
        $this->assertSame(10, $damage->readiness_mitigation);
        $this->assertSame(10, $damage->participation_mitigation);
        $this->assertSame(10, $damage->final_damage);
        $this->assertSame(1, $damage->reconstruction_required);
        $this->assertSame(3, $damage->result_snapshot['reconstruction_daily_cap']);

        $result = app(NationRaidReconstructionService::class)
            ->recordNormalExplorationVictory($character, $this->battleLog($character));
        $this->assertTrue($result['recorded']);
        $this->assertTrue($result['recovered']);
        $this->assertSame('recovered', $damage->fresh()->status);
    }

    public function test_outcome_boundaries_are_repelled_at_eighty_percent_and_exterminated_at_one_hundred(): void
    {
        foreach ([[20, NationRaidEvent::RESULT_REPELLED], [0, NationRaidEvent::RESULT_EXTERMINATED]] as [$remaining, $expected]) {
            $event = $this->draft(now()->subDays(8));
            $event->update([
                'status' => NationRaidEvent::STATUS_FINALIZING,
                'activated_at' => $event->starts_at,
                'finalization_started_at' => $event->ends_at,
                'total_target_hp' => 100,
                'current_cycle_no' => 1,
            ]);
            NationRaidBossCycle::query()->create([
                'event_id' => $event->id,
                'cycle_no' => 1,
                'cycle_kind' => 'main',
                'stage_no' => 1,
                'max_hp' => 100,
                'current_hp' => $remaining,
                'current_form' => 'sealed_scale',
                'boss_species_key' => 'dragon',
                'parameter_snapshot' => [],
                'started_at' => $event->starts_at,
                'defeated_at' => $remaining === 0 ? $event->ends_at : null,
            ]);

            DB::transaction(function () use ($event): void {
                app(NationRaidOutcomeService::class)->finalizeLocked(
                    NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail(),
                );
            });

            $this->assertSame($expected, $event->fresh()->result_type);
            $this->assertDatabaseMissing('nation_raid_invasion_damages', ['event_id' => $event->id]);
        }
    }

    public function test_lifecycle_finalizes_automatically_thirty_minutes_after_the_end(): void
    {
        $event = $this->scheduled(now());
        app(NationRaidEventService::class)->activate($event, now());
        $this->travelTo($event->ends_at);

        $atEnd = app(NationRaidLifecycleService::class)->advanceDue();
        $this->assertSame(1, $atEnd['closing']);
        $this->assertSame(0, $atEnd['finalized']);
        $this->travel(29)->minutes();
        $this->assertSame(0, app(NationRaidLifecycleService::class)->advanceDue()['finalized']);
        $this->travel(1)->minutes();
        $this->assertSame(1, app(NationRaidLifecycleService::class)->advanceDue()['finalized']);
        $this->assertSame(NationRaidEvent::STATUS_COMPLETED, $event->fresh()->status);
        $this->assertSame(NationRaidEvent::RESULT_INVASION, $event->fresh()->result_type);
    }

    public function test_lifecycle_reports_non_retryable_finalization_integrity_errors(): void
    {
        $event = $this->scheduled(now());
        app(NationRaidEventService::class)->activate($event, now());
        $event->update(['reward_policy_hash' => str_repeat('f', 64)]);
        $this->travelTo($event->ends_at);
        app(NationRaidLifecycleService::class)->advanceDue();
        $this->travel(30)->minutes();

        $result = app(NationRaidLifecycleService::class)->advanceDue();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['finalization_waiting']);
        $this->assertSame(NationRaidEvent::STATUS_FINALIZING, $event->fresh()->status);
    }

    public function test_analytics_reports_participation_free_usage_stamina_and_damage_concentration(): void
    {
        $first = $this->character();
        $second = $this->character();
        $nation = app(NationService::class)->create($first, '分析確認国');
        NationMembership::query()->create([
            'nation_id' => $nation->id,
            'character_id' => $second->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $event = $this->scheduled(now()->addHours(72));
        app(NationRaidEventService::class)->freezePreparation($event, now());

        foreach (range(1, 5) as $sortie) {
            $free = $sortie <= 3;
            app(NationRaidBattleTelemetryService::class)->record([
                'event_key' => $event->event_key,
                'battle_token' => bin2hex(random_bytes(32)),
                'ruleset_version' => $event->ruleset_version,
                'result_status' => 'resolved',
                'character_id' => $first->id,
                'nation_id' => $nation->id,
                'is_nation_eligible' => true,
                'turn_count' => 0,
                'applied_damage_total' => 100 * $sortie,
                'event_snapshot' => [
                    'sortie_cost_type' => $free ? 'daily_free' : 'voluntary_stamina',
                    'stamina_cost' => $free ? 0 : 10,
                    'ruleset_hash' => $event->ruleset_hash,
                ],
            ]);
        }

        $analysis = app(NationRaidAnalyticsService::class)->analyze(['event_key' => $event->event_key]);
        $engagement = $analysis['raid_engagement'];
        $this->assertSame(2, $engagement['reference_active_members']);
        $this->assertSame(1, $engagement['reference_participants']);
        $this->assertSame(50.0, $engagement['participation_rate']);
        $this->assertSame(50.0, $engagement['effective_participation_rate']);
        $this->assertSame(3, $engagement['free_sorties']);
        $this->assertSame(2, $engagement['voluntary_sorties']);
        $this->assertSame(60.0, $engagement['free_sortie_usage_rate']);
        $this->assertSame(20, $engagement['stamina_consumed']);
        $this->assertSame(100.0, $analysis['participant_distribution']['top_ten_fixed_damage_share']);
    }

    public function test_next_cycle_migration_refuses_down_after_preparation_is_frozen(): void
    {
        $character = $this->character();
        app(NationService::class)->create($character, '巻戻保護国');
        $event = $this->scheduled(now()->addHours(72));
        app(NationRaidEventService::class)->freezePreparation($event, now());
        $migration = require database_path('migrations/2026_09_15_140000_add_next_cycle_nation_raid_systems.php');

        try {
            $migration->down();
            $this->fail('兵站対象の固定後はmigrationを巻き戻せてはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }

        $this->assertDatabaseHas('nation_raid_events', [
            'id' => $event->id,
            'status' => NationRaidEvent::STATUS_SCHEDULED,
        ]);
        $this->assertDatabaseHas('nation_raid_nation_preparations', ['event_id' => $event->id]);
    }

    private function activeEvent(): NationRaidEvent
    {
        $event = $this->scheduled(now());

        return app(NationRaidEventService::class)->activate($event, now());
    }

    private function scheduled(\DateTimeInterface $start): NationRaidEvent
    {
        $event = $this->draft($start);
        $event = app(NationRaidEventService::class)->approveBalance(
            $event,
            User::factory()->create(['role' => 'admin']),
            'next cycle system test',
        );

        return app(NationRaidEventService::class)->schedule($event, $event->starts_at->copy()->subHours(72));
    }

    private function draft(\DateTimeInterface $start): NationRaidEvent
    {
        return app(NationRaidEventService::class)->createDraft(
            'next-cycle-'.bin2hex(random_bytes(5)),
            '次回レイド検証',
            $start,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function character(array $overrides = []): Character
    {
        return Character::query()->create($overrides + [
            'user_id' => User::factory()->create()->id,
            'name' => '次回レイド冒険者'.bin2hex(random_bytes(3)),
            'level' => 30,
            'hp_base' => 20_000,
            'mp_base' => 500,
            'attack_base' => 3_000,
            'defense_base' => 3_000,
            'magic_base' => 500,
            'spirit_base' => 3_000,
            'speed_base' => 1_000,
            'luck_base' => 100,
            'current_hp' => 20_000,
            'current_mp' => 500,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
            'last_battle_at' => now(),
        ]);
    }

    private function battleLog(Character $character, string $result = 'win'): BattleLog
    {
        $area = Area::query()->firstOrCreate(['slug' => 'raid-contribution-test'], ['name' => '貢献確認地']);
        $enemy = Enemy::query()->firstOrCreate(['area_id' => $area->id, 'name' => '貢献確認敵']);

        return BattleLog::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'enemy_id' => $enemy->id,
            'battle_type' => 'normal',
            'result' => $result,
            'log_text' => '通常探索勝利',
        ]);
    }
}
