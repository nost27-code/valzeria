<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidEvent;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidCoordinationParticipant;
use App\Models\NationMembership;
use App\Models\NationRaidBattleTelemetryLog;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidSortieService;
use App\Services\Nation\Raid\NationRaidSortieCombatService;
use App\Services\Nation\Raid\NationRaidSettlementService;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTransactionRunner;
use App\Services\Nation\NationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NationRaidSortieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config()->set('features.nation_competitive_raid_enabled', true);
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', true);
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

    public function test_strategy_is_off_by_default_and_http_sorties_ignore_client_strategy(): void
    {
        $this->assertFalse((bool) config('nation_raid.strategy_enabled', false));
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $character = $this->character();
        $event = $this->event();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('nation-raid.show', $event))->assertOk()
            ->assertDontSee('name="strategy"', false)->assertDontSee('作戦を選ぶ')
            ->assertSee('ヴァルグレイドに挑む')->assertSee('装備を整える');

        foreach ([[], ['strategy' => ['fortify']]] as $payload) {
            $token = bin2hex(random_bytes(32));
            $url = route('nation-raid.show', ['event' => $event, 'battle' => $token]);
            $this->post(route('nation-raid.battle', $event), $payload + ['battle_token' => $token])
                ->assertSessionHasNoErrors()->assertRedirect($url);
            $battle = NationRaidBattleResult::where('battle_token', $token)->sole();
            $this->assertSame('resolved', $battle->status);
            $this->assertSame('boss_set', $battle->strategy);
            $this->assertSame('boss_set', $battle->summary['display']['strategy']);
            $this->get($url)->assertOk()->assertDontSee('作戦：')->assertSee('戦闘ログ');
        }
        $this->assertSame(230, $character->fresh()->explore_stamina);
        $this->assertSame(2, NationRaidDailyUsage::query()->sole()->used_count);
    }

    public function test_existing_uniform_hp_event_can_fight_without_rewriting_its_snapshot_or_history(): void
    {
        $character = $this->character();
        $event = $this->event();
        $snapshot = $event->ruleset_snapshot;
        $snapshot['version'] = 'nation-raid-phase1-v4-equipment-resistance';
        $snapshot['fixed']['boss_max_hp'] = 5_000_000;
        unset($snapshot['fixed']['total_target_hp']);
        foreach ($snapshot['stages'] as &$stage) {
            unset($stage['max_hp']);
        }
        unset($stage);
        $hash = hash('sha256', \App\Services\Nation\Raid\NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
        $event->update(['ruleset_snapshot' => $snapshot, 'ruleset_hash' => $hash,
            'ruleset_version' => $snapshot['version'], 'cycle_max_hp' => 5_000_000, 'total_target_hp' => 100_000_000]);
        $cycle = $event->cycles()->sole();
        $cycle->update(['max_hp' => 5_000_000, 'current_hp' => 5_000_000,
            'parameter_snapshot' => app(NationRaidEventService::class)->cycleParameterSnapshot(1, $event)]);
        $token = bin2hex(random_bytes(32));
        [$started] = app(NationRaidSortieService::class)->start($event, $character, 'boss_set', $token);
        $calculation = app(NationRaidSortieCombatService::class)->resolve($started);
        $result = app(NationRaidSettlementService::class)->resolve($started, $calculation);
        $this->assertSame('resolved', $result->status);
        $this->assertSame($hash, $result->summary['calculation']['engine_result']['rulesetHash']);
        $this->assertSame($hash, $event->fresh()->ruleset_hash);
        $this->assertSame(5_000_000 - $result->applied_damage_total, $cycle->fresh()->current_hp);
        $this->assertSame($result->id, app(NationRaidSortieService::class)->fight($event, $character, 'boss_set', $token)->id);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->used_count);
    }

    public function test_disabling_strategy_preserves_old_sortie_and_retry_without_spending_again(): void
    {
        config()->set('nation_raid.strategy_enabled', true);
        $character = $this->character();
        $other = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $token = bin2hex(random_bytes(32));
        $before = $service->fight($event, $character, 'intercept', $token);
        $this->assertSame('resolved', $before->status);
        $this->assertSame('intercept', $before->strategy);
        $hp = NationRaidBossCycle::query()->sole()->current_hp;

        config()->set('nation_raid.strategy_enabled', false);
        $after = $service->fight($event, $character, 'boss_set', $token);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->summary, $after->summary);
        $this->assertSame('intercept', $after->strategy);
        $this->assertSame($hp, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidBattleResult::query()->count());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('出撃情報が一致しません。');
        $service->fight($event, $other, 'boss_set', $token);
    }

    public function test_strategy_enabled_keeps_three_choices_and_request_validation(): void
    {
        config()->set('nation_raid.strategy_enabled', true);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $character = $this->character();
        $event = $this->event();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('nation-raid.show', $event))->assertOk()
            ->assertSee('name="strategy"', false)->assertSee('猛攻')->assertSee('迎撃')->assertSee('堅守');
        $this->post(route('nation-raid.battle', $event), ['battle_token' => bin2hex(random_bytes(32))])
            ->assertSessionHasErrors(['strategy']);
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertSame(0, NationRaidBattleResult::query()->count());
    }

    public function test_direct_sortie_service_cannot_enable_strategy_while_off(): void
    {
        config()->set('nation_raid.strategy_enabled', false);
        $character = $this->character();
        $battle = app(NationRaidSortieService::class)->fight($this->event(), $character, 'fortify', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $battle->status);
        $this->assertSame('boss_set', $battle->strategy);
    }

    public function test_official_sortie_persists_damage_and_consumes_once_even_when_reposted(): void
    {
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $token = bin2hex(random_bytes(32));
        $result = $service->fight($event, $character, 'assault', $token);

        $this->assertSame('resolved', $result->status);
        $this->assertGreaterThan(0, $result->applied_damage_total);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->used_count);
        $this->assertSame(10_000_000 - $result->applied_damage_total, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame($result->id, $service->fight($event, $character, 'assault', $token)->id);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidBattleResult::query()->count());
        $this->assertSame($result->applied_damage_total, $result->participation->personal_damage_total);
        $this->assertSame(0, $result->coordination_damage_total);
        $this->assertNotEmpty($result->turn_log);
        $telemetry = NationRaidBattleTelemetryLog::query()->sole();
        $this->assertSame('1.1', $telemetry->telemetry_schema_version);
        $this->assertCount($result->turn_count, $telemetry->turns);
        $this->assertSame($result->calculated_damage_total, array_sum($telemetry->damage_by_source));
        $this->assertSame($result->max_action_damage, $telemetry->max_action_damage);
        $this->assertSame('per_sortie_virtual_hp', $telemetry->event_snapshot['turn_hp_basis']);
        $this->assertSame(100_000, $telemetry->turns[0]['boss_hp_before']);
        $this->assertSame(10_000_000, $telemetry->boss_hp_before);
        $this->assertSame($result->summary['admission']['player']['abilities']['max_hp'], $telemetry->turns[0]['player_hp_before']);
        $this->assertNotContains('phase4_turn_metrics_not_adapted', $telemetry->quality_flags);
        $this->assertNotContains('player_turn_observation_missing', $telemetry->quality_flags);
        $this->assertNull($telemetry->turns[0]['player_action']['critical_count']);
        $this->assertStringNotContainsString($character->name, json_encode([$telemetry->player_snapshot, $telemetry->event_snapshot, $telemetry->turns], JSON_UNESCAPED_UNICODE));
        foreach ($result->turn_log as $index => $turn) {
            $this->assertSame($turn['enemy_damage']['beforeCap'] ?? null, $telemetry->turns[$index]['boss_action']['damage_before_cap']);
            $this->assertSame($turn['enemy_damage']['afterCap'] ?? null, $telemetry->turns[$index]['boss_action']['damage_after_cap']);
            $this->assertSame($turn['player_hp_after'], $telemetry->turns[$index]['player_hp_after']);
        }
    }

    public function test_missing_telemetry_does_not_roll_back_combat_and_recovery_never_refights_or_spends_again(): void
    {
        $writer = app(\App\Services\Nation\NationRaidBattleTelemetryService::class);
        (new \ReflectionProperty($writer, 'tableExists'))->setValue($writer, false);
        $this->app->instance(\App\Services\Nation\NationRaidBattleTelemetryService::class, $writer);
        $character = $this->character();
        $event = $this->event();
        $result = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $result->status);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $hp = NationRaidBossCycle::query()->sole()->current_hp;
        $this->assertSame(0, NationRaidBattleTelemetryLog::query()->count());

        $this->app->instance(\App\Services\Nation\NationRaidBattleTelemetryService::class, new \App\Services\Nation\NationRaidBattleTelemetryService);
        $this->mock(NationRaidSortieCombatService::class)->shouldNotReceive('resolve');
        $this->artisan('nation-raid:telemetry', ['event' => $event->id])->assertSuccessful();
        $stored = NationRaidBattleTelemetryLog::query()->sole();
        $this->artisan('nation-raid:telemetry', ['event' => $event->id])->assertSuccessful();
        $this->assertSame($stored->getAttributes(), NationRaidBattleTelemetryLog::query()->sole()->getAttributes());
        $this->assertSame($hp, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->used_count);
        $this->artisan('nation-raid:telemetry', ['event' => $event->id, '--limit' => 0])->assertFailed();
    }

    public function test_sixth_sortie_is_allowed_but_still_costs_stamina_and_replays_once(): void
    {
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        for ($i = 0; $i < 6; $i++) {
            $token = bin2hex(random_bytes(32));
            $result = $service->fight($event, $character, 'assault', $token);
            $this->assertSame('resolved', $result->status);
        }
        $this->assertSame($result->id, $service->fight($event, $character, 'assault', $token)->id);
        $this->assertSame(190, $character->fresh()->explore_stamina);
        $this->assertSame(6, NationRaidDailyUsage::query()->sole()->resolved_count);
        $this->assertSame(6, $result->day_sortie_no);
        $screen = app(\App\Services\Nation\Raid\NationRaidScreenService::class)->screen($event->fresh(), $character->fresh());
        $this->assertTrue($screen['can_challenge']);
        $this->assertSame(6, $screen['used_sorties']);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('nation-raid.show', $event))->assertOk()
            ->assertSee('回数制限なし')->assertSee('本日 6回出撃')->assertDontSee('本日の残り出撃');
    }

    public function test_sortie_256_keeps_daily_event_participation_and_telemetry_counts(): void
    {
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $first = $service->fight($event, $character, 'boss_set', bin2hex(random_bytes(32)));
        $first->participation->update(['resolved_sorties' => 255]);
        NationRaidDailyUsage::sole()->update(['used_count' => 255, 'resolved_count' => 255]);
        $result = $service->fight($event, $character, 'boss_set', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $result->status);
        $this->assertSame(256, $result->day_sortie_no);
        $this->assertSame(256, $result->event_sortie_no);
        $this->assertSame(256, $result->participation->resolved_sorties);
        $this->assertSame(256, NationRaidDailyUsage::sole()->used_count);
        $this->assertSame(256, NationRaidDailyUsage::sole()->resolved_count);
        $telemetry = NationRaidBattleTelemetryLog::where('battle_token_hash', hash('sha256', $result->battle_token))->sole();
        $this->assertSame(256, (int) $telemetry->day_sortie_no);
        $this->assertSame(256, (int) $telemetry->event_sortie_no);
    }

    public function test_telemetry_insert_error_keeps_committed_hp_and_stamina(): void
    {
        $character = $this->character();
        $event = $this->event();
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            CREATE TRIGGER force_raid_observation_insert_failure
            BEFORE INSERT ON nation_raid_battle_telemetry
            BEGIN
                SELECT RAISE(ABORT, 'forced observation failure');
            END
            SQL);
        try {
            $result = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->assertSame('resolved', $result->fresh()->status);
            $this->assertSame(240, $character->fresh()->explore_stamina);
            $this->assertSame(10_000_000 - $result->applied_damage_total, NationRaidBossCycle::query()->sole()->current_hp);
            $this->assertSame(0, NationRaidBattleTelemetryLog::query()->count());
        } finally {
            \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS force_raid_observation_insert_failure');
        }
    }

    public function test_stale_start_cycle_and_multi_cycle_carry_keep_damage_conserved(): void
    {
        $first = $this->character();
        $second = $this->character();
        $event = $this->event();
        [$a] = app(NationRaidSortieService::class)->start($event, $first, 'assault', bin2hex(random_bytes(32)));
        [$b] = app(NationRaidSortieService::class)->start($event, $second, 'assault', bin2hex(random_bytes(32)));
        $settlement = app(NationRaidSettlementService::class);
        $a = $settlement->resolve($a, $this->calculation($a, 320_000_100));
        $this->assertSame(10, $event->fresh()->current_cycle_no);
        $this->assertNotNull($event->fresh()->stage10_reached_at);
        $this->assertSame(199_999_900, $event->fresh()->cycles()->where('cycle_no', 10)->sole()->current_hp);
        $b = $settlement->resolve($b, $this->calculation($b, 6_600_000_900));
        $this->assertSame(1, $b->target_cycle_no);
        $this->assertSame(10, $b->damage_segments[0]['cycle_no']);
        $this->assertSame(21, $event->fresh()->current_cycle_no);
        $this->assertNotNull($event->fresh()->completed_at);
        $this->assertSame(999_999_000, $event->fresh()->cycles()->where('cycle_no', 21)->sole()->current_hp);
        $this->assertSame(20, $event->fresh()->cycles()->where('cycle_kind', 'main')->count());
        $this->assertSame(6_920_001_000, $a->applied_damage_total + $b->applied_damage_total);
        $this->assertSame($b->applied_damage_total, array_sum(array_column($b->damage_segments, 'damage')));
        $this->assertSame(0, $b->summary['display']['boss_remaining_hp']);
        $completed = $event->fresh()->completed_at->toIso8601String();
        [$echo] = app(NationRaidSortieService::class)->start($event, $first, 'assault', bin2hex(random_bytes(32)));
        $echo = $settlement->resolve($echo, $this->calculation($echo, 1_000_000_000));
        $this->assertNull($echo->target_stage_no);
        $this->assertSame(1, $event->fresh()->echo_defeated_count);
        $this->assertSame(22, $event->fresh()->current_cycle_no);
        $this->assertSame($completed, $event->fresh()->completed_at->toIso8601String());
        $this->assertSame($echo->id, $settlement->resolve($echo, [])->id);
        $this->assertSame(1, $event->fresh()->echo_defeated_count);
    }

    public function test_exact_four_stage_boundaries_spawn_the_next_hp_band_without_overflow(): void
    {
        $character = $this->character();
        $event = $this->event();
        $expectedMaxHp = [20_000_000, 200_000_000, 500_000_000, 1_000_000_000, 1_000_000_000];
        foreach ([40_000_000, 80_000_000, 800_000_000, 2_000_000_000, 4_000_000_000] as $index => $damage) {
            [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'boss_set', bin2hex(random_bytes(32)));
            $resolved = app(NationRaidSettlementService::class)->resolve($battle, $this->calculation($battle, $damage));
            $current = $event->cycles()->where('cycle_no', $event->fresh()->current_cycle_no)->sole();
            $this->assertSame(5 + 4 * $index, $current->cycle_no);
            $this->assertSame($expectedMaxHp[$index], $current->max_hp);
            $this->assertSame($current->max_hp, $current->current_hp);
            $this->assertSame($damage, $resolved->applied_damage_total);
            $this->assertSame($current->max_hp, $current->parameter_snapshot['boss']['max_hp']);
        }
        $this->assertNotNull($event->fresh()->completed_at);
        $this->assertSame(6_920_000_000, (int) $event->cycles()->where('cycle_kind', 'main')->sum('max_hp'));
    }

    public function test_error_refunds_stamina_and_daily_usage_exactly_once_without_damage(): void
    {
        $character = $this->character();
        $event = $this->event();
        $this->mock(NationRaidSortieCombatService::class)->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('injected'));
        $result = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('refunded', $result->status);
        $this->assertNotNull($result->aborted_at);
        $this->assertNotNull($result->refund_key);
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertSame(0, NationRaidDailyUsage::query()->sole()->used_count);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->refunded_count);
        $this->assertSame(10_000_000, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame(0, $result->participation->resolved_sorties);
        $this->assertSame(0, NationRaidBattleTelemetryLog::query()->count());
        app(NationRaidSettlementService::class)->refund($result);
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->refunded_count);
    }

    public function test_started_retry_does_not_recompute_and_expiry_recovers_even_with_gate_off(): void
    {
        $character = $this->character();
        $event = $this->event();
        $token = bin2hex(random_bytes(32));
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', $token);
        $this->mock(NationRaidSortieCombatService::class)->shouldNotReceive('resolve');
        $result = app(NationRaidSortieService::class)->fight($event, $character, 'assault', $token);
        $this->assertSame($battle->id, $result->id);
        $this->assertSame('started', $result->status);
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->travel(10)->minutes();
        // 自然回復・小瓶等で上限以上になっても返却10を切り捨てない。
        $character->update(['explore_stamina' => 500, 'explore_stamina_updated_at' => now()]);
        $this->artisan('nation-raid:recover-sorties')->assertSuccessful();
        $this->assertSame('refunded', $battle->fresh()->status);
        $this->assertSame(510, $character->fresh()->explore_stamina);
        $this->artisan('nation-raid:recover-sorties')->assertSuccessful();
        $this->assertSame(510, $character->fresh()->explore_stamina);
    }

    public function test_snapshot_only_combat_does_not_read_back_character_equipment_or_slots(): void
    {
        $character = $this->character();
        $event = $this->event();
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $combat = app(NationRaidSortieCombatService::class);
        $before = $combat->resolve($battle);
        $character->update(['attack_base' => 1, 'defense_base' => 1, 'hp_base' => 1]);
        CharacterStatusService::clearRequestCache($character->id);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $after = $combat->resolve($battle->fresh());
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/from ["`]?character(?:s|_items|_job_art_slots)["`]?/i', $query['query']);
        }
        $this->assertSame($before, $after);
        $this->assertSame(10, $character->fresh()->current_hp);
        $this->assertSame(1, $character->fresh()->current_mp);
    }

    public function test_nation_coordination_is_unique_non_refreshing_and_separate_from_personal_damage(): void
    {
        $first = $this->character();
        $second = $this->character();
        $nation = app(NationService::class)->create($first, '共闘');
        NationMembership::query()->create(['nation_id' => $nation->id, 'character_id' => $second->id, 'role' => 'citizen', 'joined_at' => now()]);
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $one = $service->fight($event, $first, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame(0, $one->coordination_damage_total);
        $two = $service->fight($event, $second, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame((int) floor($two->applied_damage_total * 0.03), $two->coordination_damage_total);
        $this->assertSame($two->applied_damage_total, $two->participation->personal_damage_total);
        $this->assertSame($two->applied_damage_total + $two->coordination_damage_total, $two->nation_damage_total);
        $joined = NationRaidCoordinationParticipant::query()->where('character_id_snapshot', $first->id)->sole()->window_joined_at;
        $this->travel(2)->hours();
        $service->fight($event, $first, 'assault', bin2hex(random_bytes(32)));
        $this->assertEquals($joined, NationRaidCoordinationParticipant::query()->where('character_id_snapshot', $first->id)->sole()->window_joined_at);
        $this->travel(1)->hours();
        $after = $service->fight($event, $first, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame(0, $after->coordination_damage_total);
        $this->assertSame(1, $after->summary['display']['coordination']['unique_count']);
    }

    public function test_end_boundary_allows_already_started_only_during_grace(): void
    {
        $character = $this->character();
        $second = $this->character();
        $event = $this->event();
        $this->travelTo($event->ends_at->copy()->subSecond());
        NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', 7)->update([
            'selected_lineage' => null, 'determined_at' => now(),
        ]);
        [$a] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        [$b] = app(NationRaidSortieService::class)->start($event, $second, 'assault', bin2hex(random_bytes(32)));
        $this->travelTo($event->ends_at);
        app(NationRaidEventService::class)->beginFinalization($event);
        $resolved = app(NationRaidSettlementService::class)->resolve($a, $this->calculation($a, 100));
        $this->assertSame('resolved', $resolved->status);
        try {
            app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Ended event must not accept a new sortie.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('受け付け', $e->getMessage());
        }
        $this->travel(10)->minutes();
        $this->assertSame(1, app(NationRaidSettlementService::class)->recoverExpired()['refunded']);
        $this->assertSame('refunded', $b->fresh()->status);
        $this->assertSame(9_999_900, NationRaidBossCycle::query()->sole()->current_hp);
    }

    public function test_official_http_is_owner_scoped_persistent_and_prg_with_no_trial_labels(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $character = $this->character();
        $other = $this->character();
        $event = $this->event();
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        $this->get(route('nation-raid.show', $event))->assertOk()->assertSee('battle_token', false)
            ->assertDontSee('ローカル確認')->assertDontSee('試遊用')
            ->assertSee('data-nation-raid-navigation', false)->assertSee('data-nation-raid-lineage-votes', false)
            ->assertSee('href="'.route('nation-raid.rankings', $event).'"', false);
        $token = bin2hex(random_bytes(32));
        $response = $this->post(route('nation-raid.battle', $event), [
            'battle_token' => $token, 'strategy' => 'assault', 'damage' => 9_999_999, 'stage' => 20, 'form' => 'exposed_core',
        ]);
        $url = route('nation-raid.show', ['event' => $event, 'battle' => $token]);
        $response->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('レイドボスへのダメージ')->assertSee('ボスへのダメージと出撃記録を保存しました。')
            ->assertDontSee('実際のレイドボスHPには反映していません')->assertDontSee('data-nation-raid-standings', false);
        $this->get($url)->assertOk();
        $this->assertSame(1, NationRaidBattleResult::query()->count());
        $this->assertSame(1, NationRaidBattleResult::query()->sole()->target_stage_no);
        $this->actingAs($other->user)->withSession(['current_character_id' => $other->id])->get($url)->assertNotFound();
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->get(route('nation-raid.show', $event))->assertNotFound();
    }

    protected function calculation(NationRaidBattleResult $battle, int $damage): array
    {
        $calculation = app(NationRaidSortieCombatService::class)->resolve($battle);
        // 合成damageは繰越算術検証専用。本番経路はengine結果以外を受け取らない。
        $calculation['engine_result']['calculatedBossDamage'] = $damage;
        $calculation['engine_result']['maxOneActionDamage'] = min(100, $damage);

        return $calculation;
    }

    public function test_preparation_runs_in_a_separate_transaction_after_reservation_and_is_frozen_once(): void
    {
        $character = $this->character();
        $event = $this->event();
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $runner = new class extends NationRaidTransactionRunner {
            public int $transactions = 0;
            public function run(callable $callback): mixed
            {
                $this->transactions++;
                return parent::run($callback);
            }
        };
        $this->app->instance(NationRaidTransactionRunner::class, $runner);
        $this->mock(CharacterStatusService::class)->shouldReceive('getFinalStats')->atLeast()->once()
            ->andReturnUsing(function () use ($runner, $stats, $character) {
                $this->assertSame(2, $runner->transactions);
                $this->assertSame('started', NationRaidBattleResult::sole()->status);
                $this->assertSame(240, $character->fresh()->explore_stamina);
                return $stats;
            });
        $token = bin2hex(random_bytes(32));
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', $token);
        $this->assertSame('nation-raid-admission-v2', $battle->summary['admission']['schema']);
        $this->assertNotEmpty($battle->summary['admission']['prepared_at']);
        foreach (['reservation_transaction_ms', 'reservation_lock_work_ms', 'player_capture_ms'] as $key) {
            $this->assertGreaterThanOrEqual(0, $battle->summary['operational'][$key]);
        }
        [$replay, $created] = app(NationRaidSortieService::class)->start($event, $character, 'assault', $token);
        $this->assertFalse($created);
        $this->assertSame($battle->summary, $replay->summary);
    }

    public function test_preparation_failure_refunds_without_running_combat_or_losing_the_token(): void
    {
        $character = $this->character();
        $event = $this->event();
        $this->mock(CharacterStatusService::class)->shouldReceive('getFinalStats')->once()->andThrow(new \RuntimeException('snapshot failed'));
        $this->mock(NationRaidSortieCombatService::class)->shouldNotReceive('resolve');
        $token = bin2hex(random_bytes(32));
        $service = app(NationRaidSortieService::class);
        $battle = $service->fight($event, $character, 'assault', $token);
        $this->assertSame('refunded', $battle->status);
        $this->assertSame('preparation_failed', $battle->failure_code);
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertSame(0, NationRaidDailyUsage::sole()->used_count);
        $this->assertSame(1, NationRaidDailyUsage::sole()->refunded_count);
        $this->assertSame($battle->id, $service->fight($event, $character, 'assault', $token)->id);
        $this->assertSame(1, NationRaidDailyUsage::sole()->refunded_count);
    }

    public function test_refund_256_keeps_the_right_and_counter_without_wrapping(): void
    {
        $character = $this->character();
        $event = $this->event();
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        NationRaidDailyUsage::sole()->update(['refunded_count' => 255]);
        $service = app(NationRaidSettlementService::class);
        $this->assertSame('refunded', $service->refund($battle)->status);
        $this->assertSame(256, NationRaidDailyUsage::sole()->refunded_count);
        $service->refund($battle);
        $this->assertSame(256, NationRaidDailyUsage::sole()->refunded_count);
        $this->assertSame(250, $character->fresh()->explore_stamina);
    }

    public function test_incomplete_preparation_and_failed_refund_are_recovered_after_the_deadline(): void
    {
        $character = $this->character();
        $event = $this->event();
        $runner = new class extends NationRaidTransactionRunner {
            private int $calls = 0;
            public function run(callable $callback): mixed
            {
                if (++$this->calls > 1) {
                    throw new \RuntimeException('Connection unavailable after reservation.');
                }
                return parent::run($callback);
            }
        };
        $this->app->instance(NationRaidTransactionRunner::class, $runner);
        $this->mock(NationRaidSortieCombatService::class)->shouldNotReceive('resolve');
        $battle = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('started', $battle->status);
        $this->assertArrayNotHasKey('player', $battle->summary['admission']);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->app->instance(NationRaidTransactionRunner::class, new NationRaidTransactionRunner);
        $this->travel(11)->minutes();
        $counts = app(NationRaidSettlementService::class)->recoverExpired();
        $this->assertSame(['refunded' => 1, 'failed' => 0], $counts);
        $this->assertSame('refunded', $battle->fresh()->status);
        $this->assertSame(0, NationRaidDailyUsage::sole()->used_count);
        $this->assertSame(1, NationRaidDailyUsage::sole()->refunded_count);
    }

    public function test_retry_rolls_back_partial_hp_updates_and_reuses_one_battle_calculation(): void
    {
        $character = $this->character();
        $event = $this->event();
        $runner = new NationRaidInjectedTransactionRunner;
        $runner->resolvedFailures = 2;
        $this->app->instance(NationRaidTransactionRunner::class, $runner);
        $realCombat = new NationRaidSortieCombatService;
        $this->mock(NationRaidSortieCombatService::class)->shouldReceive('resolve')->once()
            ->andReturnUsing(fn ($battle) => $realCombat->resolve($battle));
        $beforeLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        $battle = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $battle->status);
        $this->assertSame(3, $battle->settlement_attempts);
        $this->assertSame([1, 2], $runner->waits);
        $this->assertSame([$beforeLevel, $beforeLevel], $runner->waitLevels);
        $this->assertSame(10_000_000 - $battle->applied_damage_total, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, $battle->participation->resolved_sorties);
        $this->assertSame(1, NationRaidBattleTelemetryLog::query()->count());
    }

    public function test_exhausted_settlement_and_refund_leave_started_then_recovery_refunds_once(): void
    {
        $character = $this->character();
        $event = $this->event();
        $runner = new NationRaidInjectedTransactionRunner;
        $runner->resolvedFailures = 3;
        $runner->refundFailures = 3;
        $this->app->instance(NationRaidTransactionRunner::class, $runner);
        $battle = app(NationRaidSortieService::class)->fight($event, $character, 'assault', bin2hex(random_bytes(32)));
        $this->assertSame('started', $battle->status);
        $this->assertSame([1, 2, 1, 2], $runner->waits);
        $this->assertSame(10_000_000, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(0, $battle->participation->resolved_sorties);
        $this->assertSame(0, NationRaidBattleTelemetryLog::query()->count());
        $this->travel(10)->minutes();
        $this->assertSame(1, app(NationRaidSettlementService::class)->recoverExpired()['refunded']);
        $this->assertSame('refunded', $battle->fresh()->status);
        // 10分の自然回復後に、消費した10も上限で捨てずに返す。
        $this->assertSame(260, $character->fresh()->explore_stamina);
        $this->assertSame(0, NationRaidDailyUsage::query()->sole()->used_count);
    }

    public function test_lost_settlement_response_returns_committed_result_without_refund_or_recalculation(): void
    {
        $character = $this->character();
        $event = $this->event();
        $this->app->instance(NationRaidTransactionRunner::class, new class extends NationRaidTransactionRunner
        {
            public function run(callable $callback): mixed
            {
                $result = parent::run($callback);
                // transaction確定後に応答だけ失う。rollback失敗注入とは区別する。
                if ($result instanceof NationRaidBattleResult && $result->status === 'resolved') {
                    $error = new \PDOException('injected lost commit response');
                    $error->errorInfo = ['HY000', 2006, 'injected'];
                    throw $error;
                }

                return $result;
            }
        });
        $realCombat = new NationRaidSortieCombatService;
        $this->mock(NationRaidSortieCombatService::class)->shouldReceive('resolve')->once()
            ->andReturnUsing(fn ($battle) => $realCombat->resolve($battle));
        $token = bin2hex(random_bytes(32));
        $service = app(NationRaidSortieService::class);
        $battle = $service->fight($event, $character, 'assault', $token);
        $this->assertSame('resolved', $battle->status);
        $this->assertNull($battle->refund_key);
        $this->assertSame(240, $character->fresh()->explore_stamina);
        $this->assertSame(1, NationRaidDailyUsage::query()->sole()->used_count);
        $this->assertSame(0, NationRaidDailyUsage::query()->sole()->refunded_count);
        $this->assertSame(10_000_000 - $battle->applied_damage_total, NationRaidBossCycle::query()->sole()->current_hp);
        $this->assertSame($battle->id, $service->fight($event, $character, 'assault', $token)->id);
        $this->assertSame(1, $battle->participation->resolved_sorties);
    }

    public function test_runtime_job_art_and_lineage_survive_json_snapshot_without_live_slot_reads(): void
    {
        config()->set('battle.job_art_v2.loadout_v2', true);
        config()->set('battle.job_art_v2.normalized_sp', true);
        // このtestの対象は保存/復元。発動窓の確率でflakyにしない。
        $this->app->bind(\App\Services\JobArtV2RandomSource::class, static fn () => new class extends \App\Services\JobArtV2RandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        });
        $character = $this->character();
        $character->update(['current_job_id' => 49, 'hp_base' => 1_000_000_000]);
        $art = new \App\Models\Skill([
            'name' => '大錬成爆装', 'skill_type' => 'job_art', 'job_id' => 49, 'learn_rank' => 5,
            'art_cost' => 2, 'activation_rate' => 100, 'sp_cost_fixed' => 0,
            'effect_template' => 'PHYSICAL_DAMAGE', 'power' => 100, 'hit_count' => 1,
        ]);
        foreach (['id' => 4905, 'slot_no' => 1, 'job_art_rate' => 1.0, 'job_art_origin' => 'current',
            'job_art_activation_policy' => 'aggressive', 'job_art_slot_condition' => 'opponent_ultimate_preparing'] as $key => $value) {
            $art->setAttribute($key, $value);
        }
        $this->partialMock(\App\Services\JobArtService::class, function ($mock) use ($art): void {
            $mock->shouldReceive('battleArtsFor')->once()->andReturn(collect([$art]));
            $mock->shouldReceive('battleStrategy')->once()->andReturn(['mode' => 'auto', 'sp_policy' => 'aggressive', 'settings' => []]);
        });
        $event = $this->event();
        // 対抗予告がある再臨へ進めるfixture。微睡のT20まで待つと、
        // 奥義を装備していない錬成producerは触媒上限に達して選択不可になる。
        \Illuminate\Support\Facades\DB::transaction(function () use ($event): void {
            app(\App\Services\Nation\CompetitionEventCoordinatorService::class)->lock();
            $locked = NationRaidEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $cycle = $locked->cycles()->lockForUpdate()->sole();
            app(\App\Services\Nation\Raid\NationRaidSharedHpService::class)->apply($locked, $cycle, 60_000_000, 'personal');
            $locked->save();
        });
        $this->travel(1)->days();
        NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', 2)->update([
            'selected_lineage' => 'counter', 'determined_at' => now(),
        ]);
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'intercept', bin2hex(random_bytes(32)));
        $battle = $battle->fresh();
        $this->assertTrue($battle->summary['admission']['player']['counterplay_enabled']);
        $this->assertCount(5, $battle->job_art_slots_snapshot);
        $this->assertSame('49:5:大錬成爆装', $battle->job_art_slots_snapshot[0]['exact_identity']);
        $this->assertSame('transmute', $battle->job_art_slots_snapshot[0]['canonical_lineage']);
        $this->assertSame('transmute', $battle->job_art_slots_snapshot[0]['raid_lineage']);
        $this->assertNull($battle->job_art_slots_snapshot[1]['exact_identity']);
        $this->assertSame('opponent_ultimate_preparing', $battle->summary['admission']['player']['actor']['job_arts'][0]['job_art_slot_condition']);
        $character->update(['current_job_id' => 1]);
        $selection = app(\App\Services\JobArtV2SelectionService::class);
        $selectionTrace = [];
        $selectionMock = $this->mock(\App\Services\JobArtV2SelectionService::class);
        $selectionMock->shouldReceive('commitSuccessfulSelection')
            ->andReturnUsing(fn (...$arguments) => $selection->commitSuccessfulSelection(...$arguments));
        $selectionMock->shouldReceive('isEligible')->andReturnUsing(fn (...$arguments) => $selection->isEligible(...$arguments));
        $selectionMock->shouldReceive('selectForTurn')
            ->andReturnUsing(function (...$arguments) use ($selection, &$selectionTrace) {
                $selected = $selection->selectForTurn(...$arguments);
                $selectionTrace[$arguments[1]->turnCount] = $selected->blockedReasons;

                return $selected;
            });
        $calculation = app(NationRaidSortieCombatService::class)->resolve($battle);
        $this->assertSame($battle->summary['admission']['player']['boss_set_exact_identities'], $calculation['engine_result']['bossSetExactIdentities']);
        $this->assertTrue(str_contains(implode("\n", $calculation['player_battle_logs']), '大錬成爆装'), json_encode($selectionTrace));
        $executed = array_values(array_filter($calculation['player_turn_metrics'], fn ($turn) => $turn['skill_id'] === 4905));
        $this->assertNotEmpty($executed);
        foreach ($executed as $turn) {
            $this->assertSame('job_art', $turn['action_type']);
            $this->assertSame('49:5:大錬成爆装', $turn['exact_identity']);
            $this->assertGreaterThanOrEqual(0, $turn['sp_spent']);
        }
        $this->assertSame('resolved', app(NationRaidSettlementService::class)->resolve($battle, $calculation)->status);
    }

    public function test_missing_stamina_wrong_character_and_unpublished_rules_never_reserve(): void
    {
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $character->update(['explore_stamina' => 9]);
        try {
            $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Insufficient stamina was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('探索力10', $e->getMessage());
        }
        $this->assertSame(9, $character->fresh()->explore_stamina);
        $this->assertSame(0, NationRaidDailyUsage::query()->count());
        $character->update(['explore_stamina' => 250]);
        $participation = $event->participations()->where('account_id', $character->user_id)->sole();
        $participation->update(['character_id' => null]);
        try {
            $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Recreated character was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('一致', $e->getMessage());
        }
        $participation->update(['character_id' => $character->id]);
        $event->update(['ruleset_hash' => str_repeat('f', 64)]);
        try {
            $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Unknown ruleset was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ルール', $e->getMessage());
        }
        $this->assertSame(0, NationRaidBattleResult::query()->count());
        $this->assertSame(250, $character->fresh()->explore_stamina);
    }

    public function test_next_raid_day_does_not_silently_fallback_when_vote_snapshot_is_missing(): void
    {
        $character = $this->character();
        $event = $this->event();
        $this->travel(1)->days();
        try {
            app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Day two cannot become an unannounced observation day.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('集計中', $e->getMessage());
        }
        $this->assertSame(0, NationRaidBattleResult::query()->count());
        NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', 2)->update([
            'selected_lineage' => 'aim', 'determined_at' => now(),
        ]);
        $battle = app(NationRaidSortieService::class)->fight($event, $character, 'intercept', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $battle->status);
        $this->assertSame(2, $battle->raid_day);
        $this->assertSame('aim', $battle->dominant_lineage);
        $this->assertSame('照準', $battle->summary['display']['dominant_lineage_label']);
    }

    public function test_equipped_max_sp_is_frozen_and_used_by_actual_output_scaling(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.enabled' => true, 'battle.job_art_v2.rank5_v6' => true]);
        $character = $this->character();
        $character->update(['current_job_id' => 1, 'hp_base' => 1_000_000_000]);
        $armor = \App\Models\Item::create(['name' => 'SP確認鎧', 'type' => 'armor', 'armor_rank' => 'G', 'mp_bonus' => 10_000, 'is_active' => true]);
        \App\Models\CharacterItem::create(['character_id' => $character->id, 'item_id' => $armor->id,
            'is_equipped' => true, 'equipped_slot' => 'armor']);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertGreaterThan($stats['pre_equipment']['mp'], $stats['max_mp']);
        $art = new \App\Models\Skill(['name' => '斬撃', 'skill_type' => 'job_art', 'job_id' => 1,
            'learn_rank' => 1, 'art_cost' => 0, 'activation_rate' => 100, 'sp_cost_fixed' => 4,
            'effect_template' => 'PHYSICAL_DAMAGE', 'power' => 100, 'hit_count' => 1]);
        foreach (['id' => 1001, 'slot_no' => 1, 'job_art_rate' => 1.0, 'job_art_origin' => 'current',
            'job_art_activation_policy' => 'aggressive', 'job_art_slot_condition' => 'always'] as $key => $value) {
            $art->setAttribute($key, $value);
        }
        $this->partialMock(\App\Services\JobArtService::class, function ($mock) use ($art): void {
            $mock->shouldReceive('battleArtsFor')->once()->andReturn(collect([$art]));
            $mock->shouldReceive('battleStrategy')->once()->andReturn(['mode' => 'auto', 'sp_policy' => 'aggressive', 'sp_output' => 'max', 'settings' => []]);
        });
        $calculator = app(NationRaidObservedSpCalculator::class);
        $this->app->instance(\App\Services\JobArtV2SpCostCalculator::class, $calculator);
        [$battle] = app(NationRaidSortieService::class)->start($this->event(), $character, 'boss_set', bin2hex(random_bytes(32)));
        $this->assertSame($stats['max_mp'], $battle->summary['admission']['player']['actor']['sp_power_reference']);
        $character->characterItems()->update(['is_equipped' => false]);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $result = app(NationRaidSortieCombatService::class)->resolve($battle->fresh());
        $this->assertNotEmpty($calculator->committed);
        $actual = $calculator->committed[0];
        $scaling = app(\App\Services\JobArtV2SpPowerScalingService::class);
        $this->assertSame($stats['max_mp'], $actual->powerReference);
        $this->assertSame($scaling->variableCostFor($stats['max_mp'], 1, 'max'), $actual->variableCost);
        $this->assertSame($scaling->bonusPartsFor($stats['max_mp'], 'max')['total'], $actual->bonusBps);
        $this->assertGreaterThan(0, $actual->variableCost);
        $this->assertTrue($actual->powerScalingApplies);
        $turn = collect($result['player_turn_metrics'])->firstWhere('skill_id', 1001);
        $this->assertNotNull($turn);
        $this->assertSame($actual->totalCost, $turn['sp_spent']);
    }

    protected function character(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'レイド冒険者'.bin2hex(random_bytes(3)), 'level' => 30,
            'hp_base' => 20_000, 'mp_base' => 500,
            'attack_base' => 3_000, 'defense_base' => 3_000,
            'magic_base' => 500, 'spirit_base' => 3_000,
            'speed_base' => 1_000, 'luck_base' => 100,
            'current_hp' => 10, 'current_mp' => 1,
            'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(), 'last_battle_at' => now(),
        ]);
    }

    protected function event(): NationRaidEvent
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('sortie-'.bin2hex(random_bytes(6)), '国家対抗レイド', now());
        $event = $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test-only fixture, not balance approval');
        $event = $service->schedule($event, now()->subHours(72));

        return $service->activate($event);
    }
}

/** 実DBのrollbackは通すが、競合とsleepだけを差し替える失敗注入fixture。 */
class NationRaidInjectedTransactionRunner extends NationRaidTransactionRunner
{
    public int $resolvedFailures = 0;
    public int $refundFailures = 0;
    public array $waits = [];
    public array $waitLevels = [];

    public function run(callable $callback): mixed
    {
        return parent::run(function (int $attempt) use ($callback) {
            $result = $callback($attempt);
            if ($result instanceof NationRaidBattleResult
                && (($result->status === 'resolved' && $this->resolvedFailures-- > 0)
                    || ($result->status === 'refunded' && $this->refundFailures-- > 0))) {
                $error = new \PDOException('injected concurrency error');
                $error->errorInfo = ['40001', 1213, 'injected'];
                throw $error;
            }

            return $result;
        });
    }

    protected function waitBeforeRetry(int $attempt): void
    {
        $this->waits[] = $attempt;
        $this->waitLevels[] = \Illuminate\Support\Facades\DB::transactionLevel();
    }
}

/** Actual prepared-battle SP commits, without replacing cost or power calculation. */
class NationRaidObservedSpCalculator extends \App\Services\JobArtV2SpCostCalculator
{
    public array $committed = [];

    public function commitForActor(\App\Services\Battle\BattleActor $actor, \App\Models\Skill $skill): ?\App\Services\JobArtV2SpPowerScalingResult
    {
        $result = parent::commitForActor($actor, $skill);
        if ($result !== null) { $this->committed[] = $result; }
        return $result;
    }
}
