<?php

namespace Tests\Feature;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\Skill;
use App\Models\User;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Services\JobArtService;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2SelectionService;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidPassiveBossActionProfileProvider;
use App\Services\Nation\Raid\Simulation\NationRaidReadOnlyDatabaseGuard;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationActionProfileProvider;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationSnapshotBuilder;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationSnapshotValidator;
use App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnActionProfileBridge;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class NationRaidPhase2SnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $statusCacheCharacterIds = [];

    protected function tearDown(): void
    {
        foreach ($this->statusCacheCharacterIds as $characterId) {
            CharacterStatusService::clearRequestCache($characterId);
        }

        parent::tearDown();
    }

    public function test_builder_uses_final_stats_excludes_admin_and_guest_and_emits_no_direct_identifiers(): void
    {
        config()->set('battle.job_art_v2.dynamic_single', true);
        config()->set('battle.job_art_v2.hit_resolution', true);
        config()->set('battle.job_art_v2.damage_application', true);
        config()->set('battle.job_art_v2.resources', true);

        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_test_job', 'name' => 'Phase2試験職', 'rank' => 'normal',
        ]);
        $normal = $this->character(User::factory()->create(['role' => 'user']), '通常対象', $job, $now);
        $weapon = Item::query()->create([
            'name' => 'Phase2竜特攻試験剣',
            'type' => 'weapon',
            'weapon_rank' => 'G',
            'str_bonus' => 0,
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $normal->id,
            'item_id' => $weapon->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'killer_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'killer_damage_rate' => 0.30,
        ]);
        $armor = Item::query()->create([
            'name' => 'Phase2竜耐性試験鎧',
            'type' => 'armor',
            'armor_rank' => 'G',
            'def_bonus' => 0,
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $normal->id,
            'item_id' => $armor->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'resist_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'species_damage_reduction_rate' => 0.25,
        ]);
        $admin = $this->character(User::factory()->create(['role' => 'admin']), '管理対象', $job, $now);
        $guest = $this->character(User::factory()->create([
            'email' => 'guest_123e4567-e89b-12d3-a456-426614174000@example.com',
            'password' => null,
            'google_id' => null,
        ]), 'ゲスト対象', $job, $now);
        $frozen = $this->character(User::factory()->create(['role' => 'user']), '凍結対象', $job, $now);
        $frozen->update(['is_frozen' => true]);
        $this->battleLog($normal, $now->subHour(), 12_345);
        $this->battleLog($admin, $now->subHour(), 99_999);
        $this->battleLog($guest, $now->subHour(), 88_888);
        $this->battleLog($frozen, $now->subHour(), 77_777);

        $this->app->instance(NationRaidSimulationActionProfileProvider::class, new class implements NationRaidSimulationActionProfileProvider
        {
            public function profilesFor(Character $character, int $profileCount): array
            {
                $actions = [];
                foreach (range(1, 20) as $turn) {
                    $actions[] = [
                        'turn' => $turn,
                        'damage_sources' => [['kind' => 'direct', 'damage' => 1_000, 'hit_count' => 1, 'defense_ignore_50_damage' => 1_050]],
                        'selected_counterplay_identity' => null, 'boss_debuff_keys_applied' => [],
                        'counterplay_hit' => true, 'hunting_mark_count' => 0, 'break_mark_count' => 0,
                    ];
                }

                return [['profile_no' => 1, 'actions' => $actions]];
            }

            public function modelVersion(): string
            {
                return 'test-authoritative-v1';
            }

            public function authoritativeForBalanceGate(): bool
            {
                return true;
            }
        });

        CharacterStatusService::clearRequestCache((int) $normal->id);
        $expected = app(CharacterStatusService::class)->getFinalStats($normal);
        $snapshot = app(NationRaidSimulationSnapshotBuilder::class)->build($now, profileCount: 1);
        $validation = app(NationRaidSimulationSnapshotValidator::class)->validate($snapshot);

        $this->assertTrue($validation['ready'], json_encode($validation, JSON_UNESCAPED_UNICODE));
        $this->assertSame(4, $snapshot['population_report']['candidate_characters']);
        $this->assertSame(1, $snapshot['population_report']['included_characters']);
        $this->assertSame(1, $snapshot['population_report']['excluded_admin_or_tester']);
        $this->assertSame(1, $snapshot['population_report']['excluded_guest']);
        $this->assertSame(1, $snapshot['population_report']['excluded_frozen']);
        $this->assertSame(1, $snapshot['population_report']['unaffiliated_characters']);
        $this->assertSame(1, $snapshot['population_report']['raid_killer_matched_characters']);
        $this->assertSame(0, $snapshot['population_report']['raid_killer_unmatched_characters']);
        $this->assertSame(0, $snapshot['population_report']['raid_killer_unavailable_characters']);
        $this->assertSame(1.0, $snapshot['population_report']['raid_killer_match_rate']);
        $this->assertSame(0.60, $snapshot['population_report']['raid_killer_average_damage_rate']);
        $this->assertSame(0.60, $snapshot['population_report']['raid_killer_max_damage_rate']);
        $this->assertSame(0.60, $snapshot['population_report']['raid_killer_max_raw_combined_damage_rate']);
        $this->assertSame(0, $snapshot['population_report']['raid_killer_cap_binding_characters']);
        $this->assertSame([
            ['damage_rate' => 0.60, 'characters' => 1],
        ], $snapshot['population_report']['raid_killer_damage_rate_distribution']);
        $this->assertSame($expected['max_hp'], $snapshot['characters'][0]['abilities']['max_hp']);
        $this->assertSame($expected['def'], $snapshot['characters'][0]['abilities']['defense']);
        $this->assertSame($expected['spr'], $snapshot['characters'][0]['abilities']['spirit']);
        $this->assertSame([660], $snapshot['characters'][0]['activity']['minute_of_day_samples']);
        $this->assertTrue($snapshot['coordination_timing_model']['authoritative_for_balance_gate']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['coordination_timing_model_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['action_profile_cache_hash']);
        $this->assertSame(NationRaidRules::BOSS_SPECIES_KEY, $snapshot['boss_species_key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['raid_killer_contract_hash']);
        $this->assertSame([
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'matched' => true,
            'damage_rate' => 0.60,
            'damage_rate_cap' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP,
            'effects' => [[
                'source' => 'affix',
                'species_key' => NationRaidRules::BOSS_SPECIES_KEY,
                'damage_rate' => 0.30,
            ]],
        ], $snapshot['characters'][0]['raid_killer']);
        $this->assertSame([
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'matched' => true,
            'damage_reduction_rate' => 0.25,
            'damage_reduction_rate_cap' => NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
        ], $snapshot['characters'][0]['raid_resistance']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['characters'][0]['action_profile_cache_hash']);

        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['通常対象', '管理対象', 'ゲスト対象', '凍結対象', $normal->user->email, '"character_id"', '"user_id"', '"nation_id"'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $json);
        }
    }

    public function test_read_only_guard_rejects_dml_rolls_back_and_restores_connection(): void
    {
        $before = User::query()->count();

        try {
            app(NationRaidReadOnlyDatabaseGuard::class)->run(
                static fn () => User::factory()->create(),
            );
            $this->fail('Read-only database guard accepted DML.');
        } catch (QueryException) {
            $this->assertSame($before, User::query()->count());
        }

        User::factory()->create();
        $this->assertSame($before + 1, User::query()->count());
    }

    public function test_builder_materializes_hashes_and_validates_a_reviewed_resolved_context_plan(): void
    {
        config()->set('battle.job_art_v2.dynamic_single', true);
        config()->set('battle.job_art_v2.hit_resolution', true);
        config()->set('battle.job_art_v2.damage_application', true);
        config()->set('battle.job_art_v2.resources', true);

        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_resolved_cache_job', 'name' => 'Phase2解決済み職', 'rank' => 'normal',
        ]);
        $character = $this->character(
            User::factory()->create(['role' => 'user']),
            '解決済みcache対象',
            $job,
            $now,
        );
        $character->update(['hp_base' => 1_000_000_000, 'current_hp' => 1_000_000_000]);
        $this->battleLog($character, $now->subHour(), 12_345);

        $plan = [[
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ]];
        $snapshot = app(NationRaidSimulationSnapshotBuilder::class)->build(
            extractedAt: $now,
            profileCount: 1,
            resolvedContexts: $plan,
            resolvedContextCoverageComplete: true,
        );
        $validation = app(NationRaidSimulationSnapshotValidator::class)->validate($snapshot);

        $this->assertTrue($validation['ready'], json_encode($validation, JSON_UNESCAPED_UNICODE));
        $this->assertSame('nation-raid-phase2-snapshot-v6', $snapshot['schema_version']);
        $this->assertTrue($snapshot['resolved_context_plan_coverage_complete']);
        $this->assertTrue($snapshot['resolved_context_profile_authoritative']);
        $this->assertCount(1, $snapshot['characters']);
        $this->assertCount(1, $snapshot['characters'][0]['resolved_context_profiles']);
        $profile = $snapshot['characters'][0]['resolved_context_profiles'][0];
        $this->assertSame(1, $profile['context']['stage']);
        $this->assertSame(NationRaidRules::FORM_SEALED_SCALE, $profile['context']['starting_form']);
        $this->assertSame(NationRaidRules::STRATEGY_ASSAULT, $profile['context']['strategy']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $profile['profile_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['resolved_context_profile_cache_hash']);
    }

    public function test_snapshot_builder_rejects_non_seven_day_active_window_before_querying(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(NationRaidSimulationSnapshotBuilder::class)->build(activeDays: 6);
    }

    public function test_passive_boss_probe_collects_twenty_current_engine_actions_without_persistence(): void
    {
        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_probe_job', 'name' => 'Phase2採取職', 'rank' => 'normal',
        ]);
        $character = $this->character(User::factory()->create(['role' => 'user']), '採取対象', $job, $now);
        $before = $character->fresh()->only(['current_hp', 'current_mp']);
        $battleLogsBefore = BattleLog::query()->count();

        $profiles = app(NationRaidPassiveBossActionProfileProvider::class)->profilesFor($character, 1);

        $this->assertCount(1, $profiles);
        $this->assertCount(20, $profiles[0]['actions']);
        $this->assertSame(range(1, 20), array_column($profiles[0]['actions'], 'turn'));
        foreach ($profiles[0]['actions'] as $action) {
            $this->assertArrayHasKey('selected_counterplay_identity', $action);
            $this->assertArrayNotHasKey('eligible_counterplay_identities', $action);
        }
        $this->assertSame($battleLogsBefore, BattleLog::query()->count());
        $this->assertSame($before, $character->fresh()->only(['current_hp', 'current_mp']));
        $provider = app(NationRaidPassiveBossActionProfileProvider::class);
        $this->assertSame(
            'current-boss-passive-probe-v4-valgreid-dragon-killer',
            $provider->modelVersion(),
        );
        $this->assertFalse($provider->authoritativeForBalanceGate());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_passive_boss_probe_does_not_reseed_or_consume_the_process_global_mt19937(): void
    {
        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_rng_isolation_job', 'name' => 'Phase2乱数隔離職', 'rank' => 'normal',
        ]);
        $character = $this->character(User::factory()->create(['role' => 'user']), '乱数隔離対象', $job, $now);

        mt_srand(20260902, MT_RAND_MT19937);
        $firstExpected = mt_rand();
        $nextExpected = mt_rand();

        mt_srand(20260902, MT_RAND_MT19937);
        $this->assertSame($firstExpected, mt_rand());

        app(NationRaidPassiveBossActionProfileProvider::class)->profilesFor($character, 1);

        $this->assertSame($nextExpected, mt_rand());
    }

    public function test_turn_by_turn_bridge_calls_selection_once_in_order_and_keeps_selection_window_closed_for_application(): void
    {
        config()->set('battle.job_art_v2.dynamic_single', true);
        config()->set('battle.job_art_v2.hit_resolution', true);
        config()->set('battle.job_art_v2.damage_application', true);
        config()->set('battle.job_art_v2.resources', true);

        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_bridge_job', 'name' => 'Phase2橋渡し職', 'rank' => 'normal',
        ]);
        $character = $this->character(User::factory()->create(['role' => 'user']), '橋渡し対象', $job, $now);
        $character->update(['hp_base' => 1_000_000_000, 'current_hp' => 1_000_000_000]);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $before = $character->fresh()->only(['current_hp', 'current_mp']);
        $battleLogsBefore = BattleLog::query()->count();

        $bridge = app(NationRaidTurnByTurnActionProfileBridge::class);
        $input = new NationRaidBattleInput(
            stage: 13,
            cycleCurrentHp: NationRaidRules::BOSS_MAX_HP,
            cycleMaxHp: NationRaidRules::BOSS_MAX_HP,
            sourceCycleId: 'bridge-test-cycle',
            dominantLineage: 'counter',
            seed: 20260902,
            strategy: NationRaidRules::STRATEGY_INTERCEPT,
            player: new NationRaidPlayerSnapshot(
                maxHp: (int) $stats['max_hp'],
                defense: (int) $stats['def'],
                spirit: (int) $stats['spr'],
                maxSp: (int) $stats['max_mp'],
                counterplayEnabled: true,
            ),
        );
        $result = $bridge->resolveProfile($character->fresh(), $input);
        $repeat = $bridge->resolveProfile($character->fresh(), $input);

        $this->assertSame(range(1, $result->battleResult->turnsCompleted), $result->selectionOrder);
        $this->assertSame(
            array_fill_keys(range(1, $result->battleResult->turnsCompleted), 1),
            $result->selectionCallsByTurn,
        );
        $this->assertNotContains(false, $result->telegraphClosedAfterSelection, true);
        $this->assertCount($result->battleResult->turnsCompleted, $result->actions);
        $this->assertTrue($result->bossIsolation['guard_state_absent']);
        $this->assertTrue($result->bossIsolation['counter_stance_absent']);
        $this->assertSame(0, $result->bossIsolation['timed_effect_count']);
        $this->assertSame(0, $result->bossIsolation['resource_slow_charges']);
        $this->assertSame(NationRaidRules::VIRTUAL_MAX_HP, $result->bossIsolation['virtual_max_hp']);
        $this->assertSame([NationRaidRules::BOSS_SPECIES_KEY], $result->bossIsolation['species_keys']);
        $this->assertSame([], $result->knownGaps);
        $this->assertNotEmpty($result->playerBattleLogs);
        $this->assertSame($result->playerBattleLogs, $repeat->playerBattleLogs);
        $this->assertSame($result->battleResult->toArray(), $repeat->battleResult->toArray());
        $this->assertSame($result->actions, $repeat->actions);
        $this->assertSame($result->playerTurnMetrics, $repeat->playerTurnMetrics);
        $this->assertCount($result->battleResult->turnsCompleted, $result->playerTurnMetrics);
        foreach ($result->playerTurnMetrics as $index => $metrics) {
            $this->assertSame($index + 1, $metrics['turn']);
            $this->assertSame('normal', $metrics['action_type']);
            $this->assertNull($metrics['skill_id']);
            $this->assertSame(0, $metrics['sp_spent']);
            $this->assertGreaterThanOrEqual(0, $metrics['healing']);
            $this->assertSame($result->battleResult->turns[$index]['player_hp_after'], $metrics['player_hp_after']);
        }
        $this->assertSame($battleLogsBefore, BattleLog::query()->count());
        $this->assertSame($before, $character->fresh()->only(['current_hp', 'current_mp']));
    }

    public function test_turn_by_turn_bridge_applies_equipped_dragon_resistance_to_raid_damage(): void
    {
        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->create([
            'key' => 'phase2_resistance_bridge_job', 'name' => 'Phase2耐性職', 'rank' => 'normal',
        ]);
        $character = $this->character(User::factory()->create(['role' => 'user']), '耐性橋渡し対象', $job, $now);
        $character->update(['hp_base' => 1_000_000_000, 'current_hp' => 1_000_000_000]);
        $armor = Item::query()->create([
            'name' => '竜耐性橋渡し鎧',
            'type' => 'armor',
            'armor_rank' => 'G',
            'def_bonus' => 0,
            'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $armor->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'resist_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'species_damage_reduction_rate' => 0.25,
        ]);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());

        $result = app(NationRaidTurnByTurnActionProfileBridge::class)->resolveProfile(
            $character->fresh(),
            new NationRaidBattleInput(
                stage: 1,
                cycleCurrentHp: NationRaidRules::BOSS_MAX_HP,
                cycleMaxHp: NationRaidRules::BOSS_MAX_HP,
                sourceCycleId: 'bridge-resistance-cycle',
                dominantLineage: null,
                seed: 20260903,
                strategy: NationRaidRules::STRATEGY_ASSAULT,
                player: new NationRaidPlayerSnapshot(
                    maxHp: (int) $stats['max_hp'],
                    defense: (int) $stats['def'],
                    spirit: (int) $stats['spr'],
                    maxSp: (int) $stats['max_mp'],
                    finalDamageReductionRate: 0.25,
                ),
            ),
        );

        $this->assertSame(
            0.25,
            $result->battleResult->turns[0]['enemy_damage']['playerDefense']['legacy_reduction_rate'],
        );
        $this->assertContains('有効防御：竜耐性 -25%', $result->playerBattleLogs);
    }

    public function test_turn_by_turn_bridge_selects_transmute_in_raid_window_without_leaking_resource_slow_to_boss_actor(): void
    {
        config()->set('battle.job_art_v2.loadout_v2', true);
        config()->set('battle.job_art_v2.dynamic_single', true);
        config()->set('battle.job_art_v2.normalized_sp', true);
        config()->set('battle.job_art_v2.hit_resolution', true);
        config()->set('battle.job_art_v2.damage_application', true);
        config()->set('battle.job_art_v2.resources', true);

        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->findOrFail(49);
        $character = $this->character(User::factory()->create(['role' => 'user']), '錬成橋渡し対象', $job, $now);
        $character->update(['hp_base' => 1_000_000_000, 'current_hp' => 1_000_000_000]);

        $art = new Skill([
            'name' => '大錬成爆装',
            'skill_type' => 'job_art',
            'job_id' => 49,
            'learn_rank' => 5,
            'art_cost' => 2,
            'activation_rate' => 100,
            'sp_cost_fixed' => 0,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'power' => 100,
            'hit_count' => 1,
        ]);
        $art->setAttribute('id', 4905);
        $art->setAttribute('slot_no', 1);
        $art->setAttribute('job_art_rate', 1.0);
        $art->setAttribute('job_art_origin', 'current');
        $art->setAttribute('job_art_activation_policy', 'aggressive');
        $art->setAttribute('job_art_slot_condition', 'opponent_ultimate_preparing');

        $this->app->instance(JobArtService::class, new class($art) extends JobArtService
        {
            public function __construct(private readonly Skill $art)
            {
                parent::__construct();
            }

            public function battleArtsFor(Character $character, string $context = 'pve'): Collection
            {
                return collect([$this->art]);
            }

            public function battleStrategy(Character $character, string $slotContext): array
            {
                return [
                    'mode' => 'auto',
                    'sp_policy' => 'aggressive',
                    'settings' => [],
                ];
            }
        });
        $this->app->bind(JobArtV2RandomSource::class, static fn () => new class extends JobArtV2RandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        });
        $this->app->bind(JobArtV2HitRandomSource::class, static fn () => new class extends JobArtV2HitRandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        });
        $this->app->forgetInstance(JobArtV2SelectionService::class);
        $this->app->forgetInstance(ActionResolver::class);
        $this->partialMock(DamageCalculator::class, function ($mock): void {
            $mock->shouldReceive('isHit')->andReturnTrue();
        });

        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $result = app(NationRaidTurnByTurnActionProfileBridge::class)->resolveProfile(
            $character->fresh(),
            new NationRaidBattleInput(
                stage: 13,
                cycleCurrentHp: NationRaidRules::BOSS_MAX_HP,
                cycleMaxHp: NationRaidRules::BOSS_MAX_HP,
                sourceCycleId: 'bridge-transmute-cycle',
                dominantLineage: 'counter',
                seed: 20260902,
                strategy: NationRaidRules::STRATEGY_INTERCEPT,
                player: new NationRaidPlayerSnapshot(
                    maxHp: (int) $stats['max_hp'],
                    defense: (int) $stats['def'],
                    spirit: (int) $stats['spr'],
                    maxSp: (int) $stats['max_mp'],
                    counterplayEnabled: true,
                    bossSetExactIdentities: ['49:5:大錬成爆装'],
                ),
            ),
        );

        $turn6 = $result->actions[5];
        $this->assertSame('49:5:大錬成爆装', $turn6['selected_counterplay_identity']);
        // 選択identityだけで合格にしない。適用前の再確認後も実際に戦技を発動する。
        $this->assertStringContainsString('大錬成爆装', implode("\n", $result->playerBattleLogs));
        $this->assertNotEmpty($turn6['damage_sources']);
        $this->assertTrue($result->telegraphClosedAfterSelection[6]);
        $this->assertSame(0, $result->bossIsolation['resource_slow_charges']);
        $this->assertTrue($result->bossIsolation['guard_state_absent']);
        $this->assertTrue($result->bossIsolation['counter_stance_absent']);
        $this->assertSame(0, $result->bossIsolation['timed_effect_count']);
        $this->assertSame(NationRaidRules::VIRTUAL_MAX_HP, $result->bossIsolation['virtual_max_hp']);
    }

    public function test_turn_by_turn_bridge_applies_assault_and_fortify_to_candidate_order_and_bridges_barrier(): void
    {
        config()->set('battle.job_art_v2.loadout_v2', true);
        config()->set('battle.job_art_v2.dynamic_single', true);
        config()->set('battle.job_art_v2.normalized_sp', true);
        config()->set('battle.job_art_v2.hit_resolution', true);
        config()->set('battle.job_art_v2.damage_application', true);
        config()->set('battle.job_art_v2.resources', true);

        $now = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tokyo');
        $job = JobClass::query()->findOrFail(1);
        $character = $this->character(User::factory()->create(['role' => 'user']), '作戦橋渡し対象', $job, $now);
        $character->update(['hp_base' => 1_000_000_000, 'current_hp' => 1_000_000_000]);

        $guard = new Skill([
            'name' => 'レイド試験結界',
            'skill_type' => 'job_art',
            'job_id' => 1,
            'learn_rank' => 1,
            'activation_rate' => 100,
            'sp_cost_fixed' => 0,
            'effect_template' => 'GUARD_BARRIER',
            'damage_reduction_percent' => 25,
        ]);
        $guard->setAttribute('id', 910001);
        $guard->setAttribute('slot_no', 1);
        $guard->setAttribute('job_art_rate', 1.0);
        $guard->setAttribute('job_art_origin', 'current');
        $guard->setAttribute('job_art_activation_policy', 'aggressive');
        $guard->setAttribute('job_art_slot_condition', 'always');

        $damage = new Skill([
            'name' => 'レイド試験斬撃',
            'skill_type' => 'job_art',
            'job_id' => 1,
            'learn_rank' => 1,
            'activation_rate' => 100,
            'sp_cost_fixed' => 0,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => 100,
            'hit_count' => 1,
        ]);
        $damage->setAttribute('id', 910002);
        $damage->setAttribute('slot_no', 2);
        $damage->setAttribute('job_art_rate', 1.0);
        $damage->setAttribute('job_art_origin', 'current');
        $damage->setAttribute('job_art_activation_policy', 'aggressive');
        $damage->setAttribute('job_art_slot_condition', 'always');

        $this->app->instance(JobArtService::class, new class($guard, $damage) extends JobArtService
        {
            public function __construct(private readonly Skill $guard, private readonly Skill $damage)
            {
                parent::__construct();
            }

            public function battleArtsFor(Character $character, string $context = 'pve'): Collection
            {
                return collect([$this->guard, $this->damage]);
            }

            public function battleStrategy(Character $character, string $slotContext): array
            {
                return ['mode' => 'auto', 'sp_policy' => 'aggressive', 'settings' => []];
            }
        });
        $this->app->bind(JobArtV2RandomSource::class, static fn () => new class extends JobArtV2RandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        });
        $this->app->bind(JobArtV2HitRandomSource::class, static fn () => new class extends JobArtV2HitRandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        });
        $this->app->forgetInstance(JobArtV2SelectionService::class);
        $this->app->forgetInstance(ActionResolver::class);

        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $input = fn (string $strategy): NationRaidBattleInput => new NationRaidBattleInput(
            stage: 13,
            cycleCurrentHp: NationRaidRules::BOSS_MAX_HP,
            cycleMaxHp: NationRaidRules::BOSS_MAX_HP,
            sourceCycleId: 'bridge-strategy-'.$strategy,
            dominantLineage: 'counter',
            seed: 20260902,
            strategy: $strategy,
            player: new NationRaidPlayerSnapshot(
                maxHp: (int) $stats['max_hp'],
                defense: (int) $stats['def'],
                spirit: (int) $stats['spr'],
                maxSp: (int) $stats['max_mp'],
                counterplayEnabled: true,
            ),
        );

        $bridge = app(NationRaidTurnByTurnActionProfileBridge::class);
        $assault = $bridge->resolveProfile($character->fresh(), $input(NationRaidRules::STRATEGY_ASSAULT));
        $fortify = $bridge->resolveProfile($character->fresh(), $input(NationRaidRules::STRATEGY_FORTIFY));
        // ボス戦セットではslot 1のガードを維持し、猛攻のように斬撃を先頭へ移さない。
        $bossSet = $bridge->resolveProfile($character->fresh(), $input(NationRaidRules::STRATEGY_BOSS_SET));

        $this->assertGreaterThan(0, array_sum(array_column($assault->actions[0]['damage_sources'], 'damage')));
        $this->assertSame(0.0, $assault->battleResult->turns[0]['enemy_damage']['playerDefense']['legacy_reduction_rate']);
        $this->assertSame([], $fortify->actions[0]['damage_sources']);
        $this->assertSame(0.25, $fortify->battleResult->turns[0]['enemy_damage']['playerDefense']['legacy_reduction_rate']);
        $this->assertSame([], $assault->knownGaps);
        $this->assertSame([], $fortify->knownGaps);
        $this->assertSame([], $bossSet->actions[0]['damage_sources']);
        $this->assertSame(0.25, $bossSet->battleResult->turns[0]['enemy_damage']['playerDefense']['legacy_reduction_rate']);
        $this->assertSame(NationRaidRules::STRATEGY_BOSS_SET, $bossSet->battleResult->strategy);
        $this->assertSame(array_fill_keys(range(1, $bossSet->battleResult->turnsCompleted), 1), $bossSet->selectionCallsByTurn);
        $this->assertSame([], $bossSet->knownGaps);
    }

    private function character(User $user, string $name, JobClass $job, CarbonImmutable $now): Character
    {
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'current_job_id' => $job->id,
            'last_battle_at' => $now->subMinutes(10),
            'hp_base' => 1_234,
            'mp_base' => 100,
            'attack_base' => 456,
            'defense_base' => 321,
            'magic_base' => 234,
            'spirit_base' => 210,
            'speed_base' => 111,
            'luck_base' => 77,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
        ]);
        $this->statusCacheCharacterIds[] = (int) $character->id;

        return $character;
    }

    private function battleLog(Character $character, CarbonImmutable $createdAt, int $damage): void
    {
        $areaId = (int) (DB::table('areas')->value('id') ?? DB::table('areas')->insertGetId([
            'name' => 'Phase2地域', 'slug' => 'phase2-test-area', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]));
        $enemyId = (int) (DB::table('enemies')->where('area_id', $areaId)->value('id') ?? DB::table('enemies')->insertGetId([
            'area_id' => $areaId, 'name' => 'Phase2敵', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]));
        BattleLog::query()->create([
            'character_id' => $character->id,
            'area_id' => $areaId,
            'enemy_id' => $enemyId,
            'battle_type' => 'normal',
            'result' => 'win',
            'exp_gained' => 0,
            'gold_gained' => 0,
            'job_exp_gained' => 0,
            'level_up_count' => 0,
            'log_text' => 'test',
            'turn_count' => 1,
            'damage_dealt' => $damage,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
