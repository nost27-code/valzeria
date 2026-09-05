<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidBattleResult;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidTrialBattleLogPresenter;
use Tests\TestCase;

final class NationRaidTrialBattleLogPresenterTest extends TestCase
{
    public function test_it_builds_a_chronological_log_from_finalized_raid_damage(): void
    {
        $result = app(NationRaidTrialBattleLogPresenter::class)->present(
            $this->battleResult(),
            [
                '【戦闘開始】試遊者 は 十系喰らいの黒天竜 ヴァルグレイド と遭遇した！',
                '<span class="text-emerald-700">有効打：竜特攻 +60%</span>',
                '<br><br>--- ターン 1 ---',
                '<span class="battle-log-special-title">《試験戦技》が発動！</span>',
                '十系喰らいの黒天竜 ヴァルグレイド に <span>9,999</span> のダメージ！',
                '試遊者 は剣冠の構えで攻撃を受け流した！',
                '<br><br>--- ターン 2 ---',
                '試遊者 の攻撃！ 十系喰らいの黒天竜 ヴァルグレイド に <span>8,888</span> のダメージ！',
                '試遊者 の王冠剣陣が反撃し、十系喰らいの黒天竜 ヴァルグレイド に 30 のダメージ！',
                '<br><span>双方が疲弊し、戦闘は終了した。</span>',
            ],
            '試遊者',
            '十系喰らいの黒天竜 ヴァルグレイド',
        );

        $this->assertCount(2, $result['opening_logs']);
        $this->assertCount(2, $result['turns']);
        $this->assertStringContainsString('《試験戦技》', $result['turns'][0]['player_logs'][0]);
        $this->assertStringNotContainsString('9,999', implode('', $result['turns'][0]['player_logs']));
        $this->assertStringNotContainsString('受け流した', implode('', $result['turns'][0]['player_logs']));
        $this->assertSame(800, $result['turns'][0]['player_action_damage']);
        $this->assertSame(240, $result['turns'][0]['enemy_damage']);
        $this->assertTrue($result['turns'][0]['enemy_critical']);
        $this->assertTrue($result['turns'][0]['damage_cap_hit']);
        $this->assertSame(['ガードが発動し、被害を20%軽減した！'], $result['turns'][0]['defense_messages']);
        $this->assertSame(['防御が10%低下した！（2行動）'], $result['turns'][0]['effect_messages']);
        $this->assertSame('ultimate', $result['turns'][0]['telegraph']['kind']);
        $this->assertStringContainsString('十系終焉・ヴァルグレイド', $result['turns'][0]['telegraph']['message']);
        $this->assertStringNotContainsString('8,888', implode('', $result['turns'][1]['player_logs']));
        $this->assertStringNotContainsString('王冠剣陣', implode('', $result['turns'][1]['player_logs']));
        $this->assertSame(50, $result['turns'][1]['player_action_damage']);
        $this->assertSame(30, $result['turns'][1]['counter_damage']);
        $this->assertSame(20, $result['turns'][1]['eclipse_backlash_damage']);
        $this->assertSame('試遊者は、20ターンを戦い抜いた！', $result['outcome_message']);
    }

    private function battleResult(): NationRaidBattleResult
    {
        return new NationRaidBattleResult(
            battleType: NationRaidRules::BATTLE_TYPE,
            stage: 20,
            form: NationRaidRules::FORM_EXPOSED_CORE,
            bossSpeciesKey: NationRaidRules::BOSS_SPECIES_KEY,
            seed: 1,
            rulesetHash: str_repeat('a', 64),
            strategy: NationRaidRules::STRATEGY_ASSAULT,
            bossSetExactIdentities: [null, null, null, null, null],
            turnsCompleted: 2,
            outcome: 'survived',
            playerRemainingHp: 760,
            bossVirtualRemainingHp: 99_100,
            calculatedBossDamage: 900,
            maxOneActionDamage: 800,
            t20StartingSp: 90,
            turns: [
                [
                    'turn' => 1,
                    'player_damage' => [
                        'sources' => [[
                            'kind' => NationRaidRules::DAMAGE_DIRECT,
                            'raw_damage' => 9999,
                            'applied_damage' => 800,
                            'hit_count' => 1,
                        ]],
                    ],
                    'selected_counterplay_identity' => null,
                    'counterplay' => null,
                    'pending_enemy_action_id' => null,
                    'pending_kind' => null,
                    'enemy_action_id' => 'black_sky_claw',
                    'enemy_damage' => [
                        'beforeCap' => 500,
                        'cap' => 300,
                        'afterCap' => 300,
                        'finalDamage' => 240,
                        'hits' => [[
                            'index' => 1,
                            'type' => 'physical',
                            'outcome' => 'hit',
                            'critical' => true,
                            'damage' => 500,
                        ]],
                        'appliedEffects' => ['defense_down_10_two_actions'],
                        'playerDefense' => [
                            'guard_consumed' => true,
                            'guard_rate' => 0.20,
                            'parry_succeeded' => false,
                            'guts_triggered' => false,
                        ],
                    ],
                    'player_self_damage' => 0,
                    'player_hp_after' => 760,
                    'player_sp_after' => 80,
                    'boss_sp_after' => 95,
                    'note' => null,
                ],
                [
                    'turn' => 2,
                    'player_damage' => [
                        'sources' => [
                            ['kind' => NationRaidRules::DAMAGE_DIRECT, 'applied_damage' => 50],
                            ['kind' => NationRaidRules::DAMAGE_COUNTER, 'applied_damage' => 30],
                            ['kind' => NationRaidRules::DAMAGE_ECLIPSE_BACKLASH, 'applied_damage' => 20],
                        ],
                    ],
                    'selected_counterplay_identity' => null,
                    'counterplay' => null,
                    'pending_enemy_action_id' => 'cycle:pending:20',
                    'pending_kind' => 'ultimate',
                    'enemy_action_id' => 'ten_lineage_end',
                    'enemy_damage' => [
                        'beforeCap' => 0,
                        'cap' => 400,
                        'afterCap' => 0,
                        'finalDamage' => 0,
                        'hits' => [[
                            'index' => 1,
                            'type' => 'physical',
                            'outcome' => 'miss',
                            'critical' => false,
                            'damage' => 0,
                        ]],
                        'appliedEffects' => [],
                        'playerDefense' => [],
                    ],
                    'player_self_damage' => 0,
                    'player_hp_after' => 760,
                    'player_sp_after' => 75,
                    'boss_sp_after' => 10,
                    'note' => null,
                ],
            ],
            spTrace: [],
            ultimateDenialReasons: [],
            reservationFailureCount: 0,
            preparationHistory: [],
        );
    }
}
