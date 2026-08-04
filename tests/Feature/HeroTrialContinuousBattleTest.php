<?php

namespace Tests\Feature;

use App\Models\Enemy;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleResult;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use App\Services\HeroTrialService;
use ReflectionMethod;
use Tests\TestCase;

class HeroTrialContinuousBattleTest extends TestCase
{
    public function test_second_phase_display_logs_continue_turns_without_a_second_encounter(): void
    {
        $result = new BattleResult;
        $result->result = 'victory';
        $result->logs = [
            '【戦闘開始】かんりにん は 双極天騎アウローラ と遭遇した！',
            '<br><br>--- ターン 1 ---',
            '第二形態の戦闘ログ',
        ];

        $method = new ReflectionMethod(HeroTrialService::class, 'continuousDisplayLogs');
        $logs = $method->invoke(
            app(HeroTrialService::class),
            $result,
            1,
            12,
            '双極天騎アウローラ',
            false,
        );

        $this->assertSame([
            '<br><br>--- ターン 13 ---',
            '第二形態の戦闘ログ',
        ], $logs);
    }

    public function test_intermediate_victory_log_is_replaced_by_the_transformation_transition(): void
    {
        $result = new BattleResult;
        $result->result = 'victory';
        $result->logs = [
            '<br><br>--- ターン 12 ---',
            '<br><span>かんりにんは、双極天騎アウローラを倒した！</span>',
        ];

        $method = new ReflectionMethod(HeroTrialService::class, 'continuousDisplayLogs');
        $logs = $method->invoke(
            app(HeroTrialService::class),
            $result,
            0,
            0,
            '双極天騎アウローラ',
            true,
        );

        $this->assertSame(['<br><br>--- ターン 12 ---'], $logs);
    }

    public function test_spell_form_normal_action_always_uses_magical_damage(): void
    {
        $enemy = new Enemy([
            'name' => '双極天騎アウローラ',
            'type_name' => '魔法型',
            'is_boss' => false,
        ]);
        $enemy->setRelation('actions', collect());
        $attacker = new BattleActor('双極天騎アウローラ', false, [
            'max_hp' => 70_000,
            'str' => 3_000,
            'def' => 4_500,
            'agi' => 5_000,
            'mag' => 7_250,
            'spr' => 5_750,
            'luk' => 4_500,
            'normal_attack_type' => 'magical',
        ], $enemy);
        $defender = new BattleActor('かんりにん', true, [
            'max_hp' => 1_000_000,
            'str' => 1_000,
            'def' => 5_000,
            'agi' => 1_000,
            'mag' => 1_000,
            'spr' => 4_000,
            'luk' => 1_000,
        ]);
        $state = new BattleState($defender, $attacker, 'boss');

        $method = new ReflectionMethod(BattleService::class, 'executeEnemyAction');
        $method->invoke(app(BattleService::class), $attacker, $defender, $state);

        $log = implode("\n", $state->logs);
        $this->assertStringContainsString('魔法攻撃', $log);
        $this->assertStringNotContainsString('双極天騎アウローラ の攻撃！', $log);
    }
}
