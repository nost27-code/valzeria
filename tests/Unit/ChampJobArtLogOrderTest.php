<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\LevelService;
use App\Services\ResourceChangeResult;
use ReflectionMethod;
use Tests\TestCase;

class ChampJobArtLogOrderTest extends TestCase
{
    public function test_prioritized_ultimate_miss_precedes_the_normal_attack_on_consecutive_champ_actions(): void
    {
        $calculator = $this->createMock(DamageCalculator::class);
        $calculator->method('isHit')->willReturn(true);
        $calculator->method('isDuelCritical')->willReturn(false);
        $calculator->method('calculateDuelDamage')->willReturn(40);

        $attempt = 0;
        $support = $this->createMock(JobArtBattleSupportService::class);
        $support->method('selectForTurn')->willReturnCallback(
            static function (BattleActor $actor, BattleState $state) use (&$attempt): null {
                $attempt++;
                $state->addLog("優先奥義{$attempt}回目は発動しなかった！（発動率60%）");

                return null;
            },
        );
        $support->method('fieldAccuracyDelta')->willReturn(0.0);
        $support->method('modifyFieldDamage')->willReturnCallback(
            static fn (BattleActor $actor, BattleState $state, int $damage): int => $damage,
        );
        $support->method('recordNormalAttackResolution')->willReturn(ResourceChangeResult::unchanged());

        $service = new ChampBattleService(
            $this->createMock(CharacterStatusService::class),
            $calculator,
            $this->createMock(LevelService::class),
            $support,
        );
        $attacker = $this->actor('挑戦者', true);
        $defender = $this->actor('チャンプ', false);
        $state = new BattleState($attacker, $defender, 'champ');

        $first = $this->invoke($service, 'champAction', [$attacker, $defender, 100, 100, $state]);
        $second = $this->invoke($service, 'champAction', [$attacker, $defender, 100, 100, $state]);

        foreach ([$first, $second] as $index => $result) {
            $attemptNumber = $index + 1;
            $otherAttemptNumber = $attemptNumber === 1 ? 2 : 1;
            $log = (string) $result['log'];
            $missPosition = strpos($log, "優先奥義{$attemptNumber}回目は発動しなかった");
            $attackPosition = strpos($log, '挑戦者 の攻撃！');

            $this->assertNotFalse($missPosition);
            $this->assertNotFalse($attackPosition);
            $this->assertLessThan($attackPosition, $missPosition);
            $this->assertStringNotContainsString("優先奥義{$otherAttemptNumber}回目", $log);
        }
        $this->assertSame([], $state->pullLogs());
    }

    private function actor(string $name, bool $isPlayer): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'normal_attack_type' => 'physical',
        ]);
    }

    /** @param array<int, mixed> $arguments */
    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
