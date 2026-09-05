<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidBattleEngine;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidCounterplayResolver;
use App\Services\Nation\Raid\NationRaidDamageResolver;
use App\Services\Nation\Raid\NationRaidEnemyDamageResult;
use App\Services\Nation\Raid\NationRaidIncomingDamageApplication;
use App\Services\Nation\Raid\NationRaidIncomingDamageApplier;
use App\Services\Nation\Raid\NationRaidPlayerActionSnapshot;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidPlayerTurnState;
use App\Services\Nation\Raid\NationRaidRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationRaidBattleEngineTest extends TestCase
{
    private NationRaidBattleEngine $engine;

    protected function setUp(): void
    {
        $rules = new NationRaidRules;
        $counterplay = new NationRaidCounterplayResolver($rules);
        $this->engine = new NationRaidBattleEngine(
            $rules,
            new NationRaidDamageResolver($rules),
            $counterplay,
        );
    }

    public function test_same_snapshot_and_seed_return_the_same_result_dto_without_external_state(): void
    {
        $input = $this->input(stage: 1, dominantLineage: null);
        $first = $this->engine->resolve($input);
        $second = $this->engine->resolve($input);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame('raid', $first->battleType);
        $this->assertSame('dragon', $first->bossSpeciesKey);
        $this->assertSame(20, $first->turnsCompleted);
        $this->assertSame('survived', $first->outcome);
        $this->assertCount(20, $first->turns);
        $this->assertSame('lineage_observation', $first->turns[5]['enemy_action_id']);
        $this->assertSame('stage_not_unlocked', $first->turns[5]['observation_reason']);
        $this->assertStringContainsString('観測枠', $first->turns[5]['note']);
    }

    public function test_unavailable_dominant_lineage_has_a_distinct_observation_reason(): void
    {
        $result = $this->engine->resolve($this->input(stage: 13, dominantLineage: null));

        $this->assertSame('lineage_observation', $result->turns[5]['enemy_action_id']);
        $this->assertSame('dominant_lineage_unavailable', $result->turns[5]['observation_reason']);
        $this->assertStringContainsString('最多編成系譜がない', $result->turns[5]['note']);
    }

    #[DataProvider('spPathProvider')]
    public function test_sp_paths_are_exact(
        array $actions,
        int $expectedT20Sp,
        bool $ultimateExpected,
        ?string $denialReason,
    ): void {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: $actions,
        ));

        $this->assertSame($expectedT20Sp, $result->t20StartingSp);
        $this->assertSame(0, $result->reservationFailureCount);
        if ($ultimateExpected) {
            $this->assertSame('ten_lineage_end', $result->turns[19]['enemy_action_id']);
            $this->assertSame([], $result->ultimateDenialReasons);
        } else {
            $this->assertContains($result->turns[19]['enemy_action_id'], ['black_sky_claw', 'void_corrosion_orb']);
            $this->assertContains($denialReason, $result->ultimateDenialReasons);
            $this->assertContains('insufficient_sp', $result->ultimateDenialReasons);
        }
    }

    /** @return array<string, array{list<NationRaidPlayerActionSnapshot>,int,bool,?string}> */
    public static function spPathProvider(): array
    {
        return [
            'normal 90' => [[], 90, true, null],
            'aim 87' => [[self::counterplayAction(20, '4:5:狙い撃ち')], 87, false, 'aim_sp_pressure'],
            'transmute 88' => [[self::counterplayAction(18, '49:5:大錬成爆装')], 88, false, 'transmute_resource_slow'],
            'turn 18 delay 85' => [[self::counterplayAction(18, '48:5:王戦の号令')], 85, false, 'turn_18_delay'],
        ];
    }

    public function test_delayed_reserved_action_keeps_pending_id_and_recovers_only_when_executed(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: [self::counterplayAction(18, '48:5:王戦の号令')],
        ));

        $turn18 = $result->turns[17];
        $turn19 = $result->turns[18];
        $this->assertSame($turn18['pending_enemy_action_id'], $turn19['pending_enemy_action_id']);
        $this->assertNull($turn18['enemy_action_id']);
        $this->assertSame('silent_black_field', $turn19['enemy_action_id']);

        $turn18Recovery = array_values(array_filter(
            $result->spTrace,
            static fn (array $entry): bool => $entry['turn'] === 18 && $entry['event'] === 'action_recovery',
        ));
        $turn19Recovery = array_values(array_filter(
            $result->spTrace,
            static fn (array $entry): bool => $entry['turn'] === 19 && $entry['event'] === 'action_recovery',
        ));
        $this->assertCount(0, $turn18Recovery);
        $this->assertCount(1, $turn19Recovery);
    }

    public function test_t20_delay_never_creates_turn_21_and_uses_same_turn_replacement(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: [self::counterplayAction(20, '48:5:王戦の号令')],
        ));

        $this->assertSame(20, $result->turnsCompleted);
        $this->assertCount(20, $result->turns);
        $this->assertSame(90, $result->t20StartingSp);
        $this->assertContains($result->turns[19]['enemy_action_id'], ['black_sky_claw', 'void_corrosion_orb']);
        $this->assertSame(['turn_20_delay'], $result->ultimateDenialReasons);
        $this->assertStringContainsString('代替行動', $result->turns[19]['note']);
    }

    public function test_rasetsu_destroys_raid_preparation_but_counter_action_base_damage_remains(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'aim',
            actions: [new NationRaidPlayerActionSnapshot(
                turn: 6,
                selectedCounterplayIdentity: '33:5:羅刹連撃',
                counterplayHit: true,
                breakMarkCount: 1,
            )],
            enemyHitChancePercent: 100,
        ));

        $turn6 = $result->turns[5];
        $this->assertSame('black_mirror_counter', $turn6['enemy_action_id']);
        $this->assertGreaterThan(0, $turn6['enemy_damage']['finalDamage']);
        $this->assertSame([], $turn6['enemy_damage']['appliedEffects']);
        $this->assertTrue($result->preparationHistory[0]['destroyed']);
        $this->assertSame('executed_after_destroy', $result->preparationHistory[0]['cleared_reason']);
    }

    public function test_star_light_suppresses_unique_effect_and_cleans_preparation_after_execution(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'aim',
            actions: [self::counterplayAction(6, '53:5:星詠みの光')],
            enemyHitChancePercent: 100,
        ));

        $turn6 = $result->turns[5];
        $this->assertGreaterThan(0, $turn6['enemy_damage']['finalDamage']);
        $this->assertSame([], $turn6['enemy_damage']['appliedEffects']);
        $this->assertFalse($result->preparationHistory[0]['destroyed']);
        $this->assertSame('suppressed', $result->preparationHistory[0]['cleared_reason']);
    }

    public function test_reverse_transmutation_cleanses_debuffs_and_guards_next_two_player_actions(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'break',
            actions: [
                new NationRaidPlayerActionSnapshot(
                    turn: 6,
                    bossDebuffKeysApplied: ['attack_down', 'magic_down', 'defense_down', 'spirit_down'],
                ),
                new NationRaidPlayerActionSnapshot(
                    turn: 7,
                    damageSources: [['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000]],
                ),
                new NationRaidPlayerActionSnapshot(
                    turn: 8,
                    damageSources: [['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000]],
                ),
                new NationRaidPlayerActionSnapshot(
                    turn: 9,
                    damageSources: [['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000]],
                ),
            ],
            enemyHitChancePercent: 100,
        ));

        $this->assertSame(['cleanse_and_guard_per_debuff'], $result->turns[5]['enemy_damage']['appliedEffects']);
        $this->assertSame(0.20, $result->turns[6]['player_damage']['incoming_reduction']);
        $this->assertSame(800, $result->turns[6]['player_damage']['total_damage']);
        $this->assertSame(800, $result->turns[7]['player_damage']['total_damage']);
        $this->assertSame(950, $result->turns[8]['player_damage']['total_damage']);
    }

    public function test_max_one_action_excludes_dot_counter_and_dark_sword_followup(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: [
                new NationRaidPlayerActionSnapshot(turn: 1, damageSources: [
                    ['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 2_000],
                    ['kind' => NationRaidRules::DAMAGE_DOT, 'damage' => 900],
                    ['kind' => NationRaidRules::DAMAGE_COUNTER, 'damage' => 700],
                ]),
                new NationRaidPlayerActionSnapshot(
                    turn: 6,
                    damageSources: [['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000]],
                    selectedCounterplayIdentity: '30:5:暗黒剣',
                ),
            ],
        ));

        $this->assertSame(2_000, $result->maxOneActionDamage);
        $this->assertGreaterThan(2_000 + 900 + 700 + 5_000, $result->calculatedBossDamage);
        $turn6Sources = $result->turns[5]['player_damage']['sources'];
        $this->assertContains(NationRaidRules::DAMAGE_ECLIPSE_BACKLASH, array_column($turn6Sources, 'kind'));
    }

    public function test_counterplay_disabled_snapshot_never_activates_equipped_art(): void
    {
        $result = $this->engine->resolve($this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: [self::counterplayAction(20, '4:5:狙い撃ち')],
            counterplayEnabled: false,
        ));

        $this->assertSame(90, $result->t20StartingSp);
        $this->assertSame(['4:5:狙い撃ち'], $result->bossSetExactIdentities);
        $this->assertNull($result->turns[19]['selected_counterplay_identity']);
        $this->assertSame('ten_lineage_end', $result->turns[19]['enemy_action_id']);
    }

    public function test_engine_uses_the_profile_identity_paired_with_damage_without_reselecting_by_strategy(): void
    {
        $action = new NationRaidPlayerActionSnapshot(
            turn: 6,
            damageSources: [['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000]],
            selectedCounterplayIdentity: '30:5:暗黒剣',
            counterplayHit: true,
        );
        $input = $this->input(
            stage: 13,
            dominantLineage: 'counter',
            actions: [$action],
            bossSet: ['32:5:ドラゴンダイブ', '30:5:暗黒剣'],
            strategy: NationRaidRules::STRATEGY_ASSAULT,
        );

        $result = $this->engine->resolve($input);
        $turn6 = $result->turns[5];

        $this->assertSame('30:5:暗黒剣', $turn6['selected_counterplay_identity']);
        $this->assertSame('eclipse_backlash', $turn6['counterplay']['effect']);
        $this->assertSame(1_000, $turn6['player_damage']['sources'][0]['raw_damage']);
        $this->assertContains(NationRaidRules::DAMAGE_ECLIPSE_BACKLASH, array_column($turn6['player_damage']['sources'], 'kind'));
    }

    public function test_turn_session_exposes_prompt_before_action_and_accepts_live_hp_sp_state(): void
    {
        $session = $this->engine->startSession($this->input(stage: 13, dominantLineage: 'counter'));

        foreach (range(1, 5) as $turn) {
            $prompt = $session->beginTurn();
            $this->assertSame($turn, $prompt->turn);
            $this->assertSame(NationRaidRules::VIRTUAL_MAX_HP, $prompt->bossVirtualHp);
            $this->assertFalse($prompt->hasResponseWindow());
            $session->resolveTurn(new NationRaidPlayerActionSnapshot($turn));
        }

        $prompt = $session->beginTurn();
        $this->assertSame(6, $prompt->turn);
        $this->assertTrue($prompt->hasResponseWindow());
        $this->assertIsInt($prompt->selectionPendingActionId());
        $this->assertSame('counter', $prompt->pendingKind);
        $this->assertSame([
            'raid_selection_only' => true,
            'raid_pending_enemy_action_key' => 'cycle-1:pending:6',
            'raid_pending_kind' => 'counter',
            'raid_strategy' => NationRaidRules::STRATEGY_INTERCEPT,
            'can_be_guarded' => true,
            'raid_preparation_destroyable' => false,
            'raid_boss_sp_available' => true,
            'raid_boss_resource_slow_available' => true,
        ], $prompt->selectionContext(NationRaidRules::STRATEGY_INTERCEPT));

        $resolution = $session->resolveTurn(
            new NationRaidPlayerActionSnapshot(6),
            new NationRaidPlayerTurnState(
                maxHp: 10_000,
                currentHp: 9_000,
                defense: 1_000,
                spirit: 1_000,
                maxSp: 100,
                currentSp: 37,
                enemyHitChancePercent: 0,
                enemyEvadeChancePercent: 0,
                enemyCriticalChancePercent: 0,
                finalDamageReductionRate: 0,
            ),
        );

        $this->assertSame(9_000, $resolution->playerHp);
        $this->assertSame(37, $resolution->playerSp);
        $this->assertSame(9_000, $resolution->turnRecord['player_hp_after']);
        $this->assertSame(37, $resolution->turnRecord['player_sp_after']);
    }

    public function test_virtual_hp_is_per_sortie_and_does_not_inherit_shared_hp_within_the_same_form(): void
    {
        foreach ([3_500_000, 2_100_000] as $cycleCurrentHp) {
            $session = $this->engine->startSession($this->input(
                stage: 1,
                dominantLineage: null,
                cycleCurrentHp: $cycleCurrentHp,
            ));

            $this->assertSame(NationRaidRules::VIRTUAL_MAX_HP, $session->beginTurn()->bossVirtualHp);
        }

        $session = $this->engine->startSession($this->input(
            stage: 1,
            dominantLineage: null,
            cycleCurrentHp: 3_500_000,
        ));
        $session->resolveTurn(new NationRaidPlayerActionSnapshot(
            turn: 1,
            damageSources: [[
                'kind' => NationRaidRules::DAMAGE_DIRECT,
                'damage' => 1_000_000_000,
            ]],
        ));

        $this->assertSame(0, $session->beginTurn()->bossVirtualHp);
    }

    public function test_live_player_defense_counter_is_added_without_becoming_max_one_action_damage(): void
    {
        $counter = new class implements NationRaidIncomingDamageApplier
        {
            public function apply(
                NationRaidEnemyDamageResult $damage,
                string $enemyActionId,
                int $playerHpBeforeDamage,
                int $playerSpBeforeDamage,
            ): NationRaidIncomingDamageApplication {
                return new NationRaidIncomingDamageApplication(
                    damage: $damage,
                    playerHp: $playerHpBeforeDamage,
                    playerSp: $playerSpBeforeDamage,
                    counterDamage: 1_000,
                    defenseTrace: ['counter_damage' => 1_000],
                );
            }
        };
        $session = $this->engine->startSession($this->input(
            stage: 13,
            dominantLineage: 'counter',
            enemyHitChancePercent: 100,
        ));
        $hp = 1_000_000_000;
        $sp = 100;

        while (! $session->finished()) {
            $prompt = $session->beginTurn();
            $resolution = $session->resolveTurn(
                new NationRaidPlayerActionSnapshot($prompt->turn),
                new NationRaidPlayerTurnState(
                    maxHp: 1_000_000_000,
                    currentHp: $hp,
                    defense: 1_000,
                    spirit: 1_000,
                    maxSp: 100,
                    currentSp: $sp,
                    enemyHitChancePercent: 100,
                    enemyEvadeChancePercent: 0,
                    enemyCriticalChancePercent: 0,
                    finalDamageReductionRate: 0,
                    incomingDamageApplier: $counter,
                ),
            );
            $hp = $resolution->playerHp;
            $sp = $resolution->playerSp;
        }

        $result = $session->result();
        $counterSources = [];
        foreach ($result->turns as $turn) {
            foreach ($turn['player_damage']['sources'] as $source) {
                if ($source['kind'] === NationRaidRules::DAMAGE_COUNTER) {
                    $counterSources[] = $source;
                }
            }
        }

        $this->assertNotEmpty($counterSources);
        $this->assertSame(array_sum(array_column($counterSources, 'applied_damage')), $result->calculatedBossDamage);
        $this->assertSame(0, $result->maxOneActionDamage);
    }

    private function input(
        int $stage,
        ?string $dominantLineage,
        array $actions = [],
        float $enemyHitChancePercent = 0,
        bool $counterplayEnabled = true,
        ?array $bossSet = null,
        string $strategy = NationRaidRules::STRATEGY_INTERCEPT,
        int $cycleCurrentHp = NationRaidRules::BOSS_MAX_HP,
    ): NationRaidBattleInput {
        if ($bossSet === null) {
            $bossSet = [];
            foreach ($actions as $action) {
                if ($action->selectedCounterplayIdentity !== null) {
                    $bossSet[] = $action->selectedCounterplayIdentity;
                }
            }
        }

        return new NationRaidBattleInput(
            stage: $stage,
            cycleCurrentHp: $cycleCurrentHp,
            cycleMaxHp: NationRaidRules::BOSS_MAX_HP,
            sourceCycleId: 'cycle-1',
            dominantLineage: $dominantLineage,
            seed: 20260901,
            strategy: $strategy,
            player: new NationRaidPlayerSnapshot(
                maxHp: 1_000_000_000,
                defense: 1_000,
                spirit: 1_000,
                enemyHitChancePercent: $enemyHitChancePercent,
                enemyCriticalChancePercent: 0,
                counterplayEnabled: $counterplayEnabled,
                bossSetExactIdentities: array_values(array_unique($bossSet)),
                actions: $actions,
            ),
        );
    }

    private static function counterplayAction(int $turn, string $identity): NationRaidPlayerActionSnapshot
    {
        return new NationRaidPlayerActionSnapshot(
            turn: $turn,
            selectedCounterplayIdentity: $identity,
            counterplayHit: true,
            huntingMarkCount: 1,
            breakMarkCount: 1,
        );
    }
}
