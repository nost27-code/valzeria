<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\ChampBattleService;
use App\Services\JobArtV2TimedEffectState;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ChampBattleInitiativeTest extends TestCase
{
    public function test_higher_effective_speed_acts_first_and_ties_favor_the_challenger(): void
    {
        $this->assertTrue($this->challengerActsFirst($this->actor('挑戦者', 200), $this->actor('チャンプ', 100)));
        $this->assertFalse($this->challengerActsFirst($this->actor('挑戦者', 100), $this->actor('チャンプ', 200)));
        $this->assertTrue($this->challengerActsFirst($this->actor('挑戦者', 150), $this->actor('チャンプ', 150)));
    }

    public function test_speed_effects_are_reflected_when_the_next_round_order_is_resolved(): void
    {
        $challenger = $this->actor('挑戦者', 100);
        $champ = $this->actor('チャンプ', 130);

        $this->assertFalse($this->challengerActsFirst($challenger, $champ));

        $challenger->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'initiative_test_speed_up',
            statModifiers: ['agi' => 0.40],
            appliedRound: 1,
            remainingRounds: 2,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: true,
            strength: 40,
        ));

        $this->assertSame(140, $challenger->effectiveAgi());
        $this->assertTrue($this->challengerActsFirst($challenger, $champ));
    }

    public function test_speed_comparison_is_inside_the_round_loop_and_job_art_reroll_is_preserved(): void
    {
        $source = $this->methodSource('runBattle');
        $loopPosition = strpos($source, 'for ($turn = 1; $turn <= self::MAX_TURNS; $turn++)');
        $speedPosition = strpos($source, '$this->challengerActsFirstBySpeed($attacker, $defender)');
        $adjustPosition = strpos($source, '$this->jobArtBattleSupport->adjustInitiative(');

        $this->assertNotFalse($loopPosition);
        $this->assertNotFalse($speedPosition);
        $this->assertNotFalse($adjustPosition);
        $this->assertGreaterThan($loopPosition, $speedPosition);
        $this->assertGreaterThan($speedPosition, $adjustPosition);
        $this->assertStringContainsString(
            'static fn (): bool => (bool) random_int(0, 1)',
            $source,
            '後攻時の再抽選を持つ既存戦技は、敏捷比較後も50%の再抽選を行う。',
        );
        $this->assertStringNotContainsString('$challengerFirst = (bool) random_int(0, 1);', $source);
    }

    private function actor(string $name, int $speed): BattleActor
    {
        return new BattleActor($name, true, [
            'max_hp' => 1_000,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => $speed,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }

    private function challengerActsFirst(BattleActor $challenger, BattleActor $champ): bool
    {
        $service = (new ReflectionClass(ChampBattleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ChampBattleService::class, 'challengerActsFirstBySpeed');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $challenger, $champ);
    }

    private function methodSource(string $methodName): string
    {
        $method = new ReflectionMethod(ChampBattleService::class, $methodName);
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
