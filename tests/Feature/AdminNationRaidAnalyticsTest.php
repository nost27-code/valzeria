<?php

namespace Tests\Feature;

use App\Livewire\Admin\NationRaidAnalyticsManager;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidBattleTelemetryLog;
use App\Models\User;
use App\Services\Admin\NationRaidAnalyticsService;
use App\Services\Nation\NationRaidBattleTelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNationRaidAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_is_protected_and_explains_collection_and_codex_copy(): void
    {
        $this->get(route('admin.nation-raid-analytics'))->assertRedirect();

        $normalUser = User::factory()->create(['role' => 'user']);
        $this->actingAs($normalUser)
            ->get(route('admin.nation-raid-analytics'))
            ->assertRedirect('/admin/login');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get(route('admin.nation-raid-analytics'))
            ->assertOk()
            ->assertSee('国家対抗レイド 戦闘分析')
            ->assertSee('収集設計：何を集め、何を改善できるか')
            ->assertSee('無所属の出撃も個人・全体分析には含め')
            ->assertSee('Codex貼り付け用にコピー')
            ->assertSee('# 国家対抗レイド改善分析依頼');

        Livewire::actingAs($admin)
            ->test(NationRaidAnalyticsManager::class)
            ->assertSee('対象データがまだありません。')
            ->call('resetFilters')
            ->assertSet('affiliation', 'all');
    }

    public function test_recorder_is_idempotent_sanitized_and_fail_open_for_unaffiliated_sorties(): void
    {
        $character = $this->createCharacter('保存確認冒険者');
        $service = app(NationRaidBattleTelemetryService::class);
        $rawToken = 'raw-battle-token-that-must-not-be-stored';

        $first = $service->record([
            'event_key' => 'raid-2026-09-a',
            'battle_token' => $rawToken,
            'ruleset_version' => 'raid-v1',
            'result_status' => 'resolved',
            'character_id' => $character->id,
            'nation_id' => null,
            'is_nation_eligible' => true,
            'player_level' => 255,
            'turn_count' => 2,
            'applied_damage_total' => 12345,
            'calculated_damage_total' => 13000,
            'max_action_damage' => 9000,
            'loadout_lineages' => ['field', ['invalid-shape'], 'unknown'],
            'loadout_snapshot' => [[
                'slot_no' => 1,
                'skill_id' => 10,
                'name' => '計測用戦技',
                'lineage' => 'field',
            ]],
            'turns' => [$this->turn(1, 5000, 1000)],
            'event_snapshot' => $this->eventSnapshot(),
            'player_snapshot' => [
                'level' => 255,
                'power' => 9999,
                'attack' => 300,
                'name' => $character->name,
                'email' => $character->user->email,
            ],
        ]);

        $duplicate = $service->record([
            'event_key' => 'raid-2026-09-a',
            'battle_token' => $rawToken,
            'ruleset_version' => 'raid-v1',
            'result_status' => 'resolved',
            'applied_damage_total' => 99999999,
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($duplicate);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertDatabaseCount('nation_raid_battle_telemetry', 1);

        $stored = NationRaidBattleTelemetryLog::query()->firstOrFail();
        $this->assertSame(hash('sha256', $rawToken), $stored->battle_token_hash);
        $this->assertSame(12345, (int) $stored->applied_damage_total);
        $this->assertFalse($stored->is_nation_eligible);
        $this->assertSame(['field'], $stored->loadout_lineages);
        $this->assertArrayNotHasKey('name', $stored->player_snapshot);
        $this->assertArrayNotHasKey('email', $stored->player_snapshot);
        $this->assertContains('unknown_loadout_lineage', $stored->quality_flags);
        $this->assertContains('nation_eligibility_without_nation', $stored->quality_flags);
        $this->assertContains('turn_detail_count_mismatch', $stored->quality_flags);
        $this->assertContains('max_action_damage_mismatch', $stored->quality_flags);
        $this->assertSame(5000, (int) $stored->max_action_damage);
        $this->assertNotSame($rawToken, $stored->battle_token_hash);
    }

    public function test_analysis_reports_nation_unaffiliated_turn_and_reward_metrics_without_identifiers(): void
    {
        $nation = Nation::query()->create([
            'name' => '分析用国家名',
            'description' => '分析テスト',
            'founded_at' => now(),
        ]);
        $first = $this->createCharacter('分析冒険者A');
        $second = $this->createCharacter('分析冒険者B');
        $unaffiliated = $this->createCharacter('無所属冒険者C');

        $this->recordSortie('token-a1', $first, $nation, 1000, 700, 'field', 'field', 2);
        $this->recordSortie('token-a2', $first, $nation, 2000, 1200, 'field', 'guard', 2);
        $this->recordSortie('token-b1', $second, $nation, 3000, 2500, 'guard', 'field', 3);
        $this->recordSortie('token-u1', $unaffiliated, null, 500, 400, 'aim', null, 1);

        $service = app(NationRaidAnalyticsService::class);
        $analysis = $service->analyze(['event_key' => 'raid-2026-09-a']);

        $this->assertTrue($analysis['table_available']);
        $this->assertTrue($analysis['has_records']);
        $this->assertSame(4, $analysis['summary']['resolved_sorties']);
        $this->assertSame(6500, $analysis['summary']['total_applied_damage']);
        $this->assertSame(3000, $analysis['summary']['max_sortie_damage']);
        $this->assertSame(2500, $analysis['summary']['max_action_damage']);

        $this->assertCount(1, $analysis['nation_competition']);
        $this->assertSame('国家順位1', $analysis['nation_competition'][0]['anonymous_nation']);
        $this->assertSame(6000, $analysis['nation_competition'][0]['total_damage']);
        $this->assertSame(3000, $analysis['nation_competition'][0]['damage_per_participant']);
        $this->assertSame(2000, $analysis['nation_competition'][0]['damage_per_active_member']);
        $this->assertSame(3, $analysis['nation_competition'][0]['active_count_snapshot']);
        $this->assertSame(2500, $analysis['nation_competition'][0]['max_action_damage']);

        $unaffiliatedBucket = collect($analysis['nation_sizes'])->firstWhere('bucket', 'unaffiliated_or_ineligible');
        $this->assertNotNull($unaffiliatedBucket);
        $this->assertSame(500, $unaffiliatedBucket['total_damage']);

        $field = collect($analysis['lineages'])->firstWhere('lineage', 'field');
        $this->assertSame(1, $field['targeted_sorties']);
        $this->assertSame(1, $field['untargeted_sorties']);
        $this->assertSame(50.0, $field['targeted_vs_untargeted_percent']);
        $this->assertSame(4, $analysis['turns'][0]['samples']);
        $this->assertSame(3, $analysis['turns'][1]['samples']);

        $this->assertSame(3, $analysis['reward_reach']['linked_participants']);
        $this->assertSame(1, $analysis['reward_reach']['valid_participants']);
        $this->assertSame(1, $analysis['reward_reach']['threshold_reach'][0]['participants']);

        $prompt = $analysis['codex_prompt'];
        $this->assertStringContainsString('# 国家対抗レイド改善分析依頼', $prompt);
        $this->assertStringContainsString('nation_competition', $prompt);
        $this->assertStringContainsString('participant_distribution', $prompt);
        $this->assertStringNotContainsString($first->name, $prompt);
        $this->assertStringNotContainsString($unaffiliated->name, $prompt);
        $this->assertStringNotContainsString($nation->name, $prompt);
        $this->assertStringNotContainsString('token-a1', $prompt);
        $this->assertStringNotContainsString('character_id', $prompt);
        $this->assertStringNotContainsString('nation_id', $prompt);

        $unaffiliatedOnly = $service->analyze([
            'event_key' => 'raid-2026-09-a',
            'affiliation' => 'unaffiliated',
        ]);
        $this->assertSame(1, $unaffiliatedOnly['summary']['resolved_sorties']);
        $this->assertSame(500, $unaffiliatedOnly['summary']['total_applied_damage']);
        $this->assertSame([], $unaffiliatedOnly['nation_competition']);
        $this->assertSame(1, $unaffiliatedOnly['reward_reach']['linked_participants']);
        $this->assertSame(0, $unaffiliatedOnly['reward_reach']['valid_participants']);
        $this->assertSame(0, $unaffiliatedOnly['reward_reach']['threshold_reach'][0]['participants']);
    }

    public function test_recorder_flags_and_limits_malformed_turn_telemetry(): void
    {
        $character = $this->createCharacter('ターン品質確認');
        $turns = [$this->turn(1, 100, 10), $this->turn(1, 200, 20)];
        foreach (range(2, 20) as $turn) {
            $turns[] = $this->turn($turn, 100 * $turn, 10 * $turn);
        }

        $stored = app(NationRaidBattleTelemetryService::class)->record([
            'event_key' => 'raid-malformed-turns',
            'battle_token' => 'malformed-turn-token',
            'ruleset_version' => 'raid-v1',
            'result_status' => 'resolved',
            'character_id' => $character->id,
            'turn_count' => 25,
            'reached_turn_twenty' => true,
            'max_action_damage' => 1,
            'turns' => $turns,
            'event_snapshot' => $this->eventSnapshot(),
        ]);

        $this->assertNotNull($stored);
        $this->assertSame(20, (int) $stored->turn_count);
        $this->assertFalse($stored->reached_turn_twenty);
        $this->assertCount(20, $stored->turns);
        $this->assertContains('turn_count_clamped', $stored->quality_flags);
        $this->assertContains('turn_detail_truncated', $stored->quality_flags);
        $this->assertContains('duplicate_turn_number', $stored->quality_flags);
        $this->assertContains('non_contiguous_turn_numbers', $stored->quality_flags);
        $this->assertContains('reached_turn_twenty_mismatch', $stored->quality_flags);
        $this->assertContains('max_action_damage_mismatch', $stored->quality_flags);

        $analysis = app(NationRaidAnalyticsService::class)->analyze(['event_key' => 'raid-malformed-turns']);
        $this->assertSame(0.0, $analysis['summary']['turn_twenty_rate']);
        $this->assertSame(1, $analysis['turns'][0]['samples']);
        $this->assertSame(1, $analysis['turns'][1]['samples']);
    }

    public function test_cap_unknown_values_and_equipment_distributions_are_not_silently_zero_filled(): void
    {
        $writer = app(NationRaidBattleTelemetryService::class);
        foreach ([true, false] as $observed) {
            $turn = $this->turn(1, 1000, 100);
            unset($turn['boss_action']['damage_before_cap'], $turn['boss_action']['damage_after_cap']);
            if ($observed) {
                $turn['boss_action']['damage_before_cap'] = 200;
                $turn['boss_action']['damage_after_cap'] = 120;
            }
            $writer->record([
                'event_key' => 'measured-cap', 'battle_token' => 'measured-cap-'.(int) $observed,
                'result_status' => 'resolved', 'ruleset_version' => 'v4', 'turn_count' => 1,
                'end_reason' => 'defeated', 'turns' => [$turn],
                'event_snapshot' => $observed ? [
                    'killer_raw_rate' => 0.6, 'killer_effective_rate' => 1.0,
                    'killer_rate_cap' => 1.0, 'killer_rate_multiplier' => 2.0, 'armor_resistance_rate' => 0.12,
                ] : [],
            ]);
        }
        $analysis = app(NationRaidAnalyticsService::class)->analyze(['event_key' => 'measured-cap']);
        $this->assertSame(2, $analysis['turns'][0]['samples']);
        $this->assertSame(1, $analysis['turns'][0]['cap_samples']);
        $this->assertSame(1, $analysis['turns'][0]['cap_hits']);
        $this->assertEquals(100, $analysis['turns'][0]['cap_hit_rate']);
        $this->assertNull($analysis['turns'][1]['cap_hit_rate']);
        $this->assertEquals(100, $analysis['summary']['defeat_rate']);
        $this->assertSame(1, $analysis['equipment_effects']['unavailable_sorties']);
        $this->assertSame(1, $analysis['equipment_effects']['matched_sorties']);
        $this->assertSame(1, $analysis['equipment_effects']['cap_reached_sorties']);
        $this->assertEquals(1.2, $analysis['equipment_effects']['cap_before_rate_max']);
    }

    public function test_max_action_derivation_excludes_dot_and_counter_sources(): void
    {
        $character = $this->createCharacter('最大行動確認');
        $turn = $this->turn(1, 1000, 100);
        $turn['player_action']['damage_by_source'] = [
            'job_art_direct' => 500,
            'dot' => 300,
            'counter' => 200,
            'eclipse_backlash' => 5000,
        ];

        $stored = app(NationRaidBattleTelemetryService::class)->record([
            'event_key' => 'raid-max-action-definition',
            'battle_token' => 'max-action-definition-token',
            'ruleset_version' => 'raid-v1',
            'result_status' => 'resolved',
            'character_id' => $character->id,
            'turn_count' => 1,
            'max_action_damage' => 1000,
            'turns' => [$turn],
            'event_snapshot' => $this->eventSnapshot(),
        ]);

        $this->assertNotNull($stored);
        $this->assertSame(500, (int) $stored->max_action_damage);
        $this->assertSame(5000, $stored->turns[0]['player_action']['damage_by_source']['eclipse_backlash']);
        $this->assertContains('max_action_damage_mismatch', $stored->quality_flags);

        $dotAndCounterOnly = $this->turn(1, 500, 100);
        $dotAndCounterOnly['player_action']['damage_by_source'] = [
            'dot' => 300,
            'counter' => 200,
        ];
        $dotOnlyStored = app(NationRaidBattleTelemetryService::class)->record([
            'event_key' => 'raid-max-action-definition',
            'battle_token' => 'max-action-dot-only-token',
            'ruleset_version' => 'raid-v1',
            'result_status' => 'resolved',
            'character_id' => $character->id,
            'turn_count' => 1,
            'max_action_damage' => 500,
            'turns' => [$dotAndCounterOnly],
            'event_snapshot' => $this->eventSnapshot(),
        ]);

        $this->assertNotNull($dotOnlyStored);
        $this->assertSame(0, (int) $dotOnlyStored->max_action_damage);
        $this->assertContains('max_action_damage_mismatch', $dotOnlyStored->quality_flags);
    }

    public function test_unmeasured_legacy_sorties_do_not_dilute_counterplay_denial_rates(): void
    {
        foreach ([true, false] as $observed) {
            app(NationRaidBattleTelemetryService::class)->record([
                'event_key' => 'denial-denominator', 'battle_token' => 'denial-denominator-'.(int) $observed,
                'ruleset_version' => 'v4', 'result_status' => 'resolved', 'turn_count' => 20,
                'turns' => array_map(fn ($turn) => $this->turn($turn, 100, 20), range(1, 20)),
                'counterplay_metrics' => $observed ? ['telegraphs_seen' => 4, 'aim_sp_pressure' => 1] : ['telegraphs_seen' => 4],
            ]);
        }
        $analysis = app(NationRaidAnalyticsService::class)->analyze(['event_key' => 'denial-denominator']);
        $aim = collect($analysis['counterplay'])->firstWhere('metric', 'aim_sp_pressure');
        $this->assertSame(1, $aim['observed_sorties']);
        $this->assertSame(1, $aim['count']);
        $this->assertEquals(100, $aim['per_turn_twenty_rate']);
        $unobserved = collect($analysis['counterplay'])->firstWhere('metric', 'transmute_resource_slow');
        $this->assertSame(0, $unobserved['observed_sorties']);
        $this->assertNull($unobserved['per_turn_twenty_rate']);
    }

    public function test_inconsistent_nation_active_snapshots_disable_per_capita_value(): void
    {
        $nation = Nation::query()->create([
            'name' => '人数揺れ確認国',
            'description' => '分析テスト',
            'founded_at' => now(),
        ]);
        $first = $this->createCharacter('人数揺れA');
        $second = $this->createCharacter('人数揺れB');

        $this->recordSortie('active-count-a', $first, $nation, 1000, 700, 'field', 'field', 2, 3);
        $this->recordSortie('active-count-b', $second, $nation, 2000, 1200, 'guard', 'guard', 2, 4);

        $analysis = app(NationRaidAnalyticsService::class)->analyze(['event_key' => 'raid-2026-09-a']);

        $this->assertNull($analysis['nation_competition'][0]['damage_per_active_member']);
        $this->assertFalse($analysis['nation_competition'][0]['active_count_snapshot_consistent']);
        $this->assertSame(1, $analysis['data_quality']['inconsistent_nation_active_count_groups']);
    }

    public function test_recorder_fails_open_when_table_is_unavailable_or_insert_fails(): void
    {
        $unavailable = new NationRaidBattleTelemetryService;
        $tableExists = new \ReflectionProperty($unavailable, 'tableExists');
        $tableExists->setValue($unavailable, false);

        $this->assertNull($unavailable->record([
            'event_key' => 'raid-table-unavailable',
            'battle_token' => 'table-unavailable-token',
            'result_status' => 'resolved',
        ]));

        DB::statement(<<<'SQL'
            CREATE TRIGGER force_nation_raid_telemetry_failure
            BEFORE INSERT ON nation_raid_battle_telemetry
            BEGIN
                SELECT RAISE(ABORT, 'forced telemetry failure');
            END
            SQL);

        try {
            $this->assertNull(app(NationRaidBattleTelemetryService::class)->record([
                'event_key' => 'raid-insert-failure',
                'battle_token' => 'insert-failure-token',
                'ruleset_version' => 'raid-v1',
                'result_status' => 'resolved',
            ]));
            $this->assertDatabaseCount('nation_raid_battle_telemetry', 0);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS force_nation_raid_telemetry_failure');
        }
    }

    public function test_mixed_stage_hp_does_not_use_one_cycles_hp_as_an_event_wide_denominator(): void
    {
        $character = $this->createCharacter('段階HP観測');
        foreach ([10_000_000, 50_000_000] as $index => $hp) {
            app(NationRaidBattleTelemetryService::class)->record([
                'event_key' => 'mixed-stage-hp', 'battle_token' => 'mixed-stage-hp-'.$index,
                'character_id' => $character->id, 'result_status' => 'resolved',
                'applied_damage_total' => 100_000, 'calculated_damage_total' => 100_000,
                'event_snapshot' => [...$this->eventSnapshot(), 'boss_max_hp' => $hp],
            ]);
        }
        $summary = app(NationRaidAnalyticsService::class)->analyze(['event_key' => 'mixed-stage-hp'])['summary'];
        $this->assertSame(200_000, $summary['total_applied_damage']);
        $this->assertNull($summary['estimated_defeats_from_damage']);
        $this->assertNull($summary['sorties_for_one_defeat_at_average']);
    }

    private function recordSortie(
        string $token,
        Character $character,
        ?Nation $nation,
        int $damage,
        int $maxActionDamage,
        string $loadoutLineage,
        ?string $adaptiveLineage,
        int $turnCount,
        int $nationActiveCount = 3,
    ): void {
        $turns = [];
        $remainingDamage = max(0, $damage - $maxActionDamage);
        foreach (range(1, $turnCount) as $turn) {
            $turnDamage = $turn === 1
                ? $maxActionDamage
                : intdiv($remainingDamage, max(1, $turnCount - 1));
            $turns[] = $this->turn($turn, $turnDamage, 100 * $turn);
        }

        $record = app(NationRaidBattleTelemetryService::class)->record([
            'event_key' => 'raid-2026-09-a',
            'battle_token' => $token,
            'ruleset_version' => 'raid-v1',
            'raid_day' => 1,
            'result_status' => 'resolved',
            'end_reason' => $turnCount >= 3 ? 'turn_limit' : 'player_defeated',
            'character_id' => $character->id,
            'nation_id' => $nation?->id,
            'is_nation_eligible' => $nation !== null,
            'nation_active_count' => $nation === null ? 0 : $nationActiveCount,
            'player_level' => 100,
            'player_power' => 5000 + $damage,
            'boss_phase' => 'sealed_scale',
            'adaptive_lineage' => $adaptiveLineage,
            'turn_count' => $turnCount,
            'boss_hp_before' => 100000000,
            'boss_hp_after' => 100000000 - $damage,
            'calculated_damage_total' => $damage,
            'applied_damage_total' => $damage,
            'max_action_damage' => $maxActionDamage,
            'damage_taken_total' => 500 * $turnCount,
            'healing_total' => 100,
            'loadout_lineages' => [$loadoutLineage],
            'damage_by_source' => ['job_art_direct' => $damage],
            'counterplay_metrics' => [
                'telegraphs_seen' => 1,
                'guards_20' => $loadoutLineage === 'guard' ? 1 : 0,
            ],
            'turns' => $turns,
            'event_snapshot' => $this->eventSnapshot(),
            'player_snapshot' => ['level' => 100, 'power' => 5000 + $damage],
        ]);

        $this->assertNotNull($record);
    }

    /** @return array<string, mixed> */
    private function turn(int $turn, int $playerDamage, int $bossDamage): array
    {
        return [
            'turn' => $turn,
            'boss_phase' => 'sealed_scale',
            'player_hp_before' => 1000,
            'player_hp_after' => $turn === 2 ? 0 : 900,
            'boss_hp_before' => 100000000,
            'boss_hp_after' => 100000000 - $playerDamage,
            'player_action' => [
                'action_type' => 'job_art',
                'action_key' => 'test_art',
                'lineage' => 'field',
                'damage_total' => $playerDamage,
                'damage_by_source' => ['job_art_direct' => $playerDamage],
            ],
            'boss_action' => [
                'action_key' => 'test_boss_art',
                'action_name' => '予告攻撃',
                'lineage' => 'guard',
                'telegraphed' => true,
                'damage_before_cap' => $bossDamage,
                'damage_after_cap' => $bossDamage,
                'damage_final' => $bossDamage,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function eventSnapshot(): array
    {
        return [
            'boss_name' => '計測用レイドボス',
            'boss_max_hp' => 100000000,
            'max_turns' => 20,
            'attempts_per_day' => 5,
            'duration_days' => 7,
            'valid_participation_sorties' => 2,
            'reward_thresholds' => ['damage' => [2000, 5000]],
            'ruleset_hash' => 'test-ruleset-hash',
        ];
    }

    private function createCharacter(string $name): Character
    {
        $user = User::factory()->create(['role' => 'user']);

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'level' => 100,
            'last_seen_at' => now(),
        ]);
    }
}
