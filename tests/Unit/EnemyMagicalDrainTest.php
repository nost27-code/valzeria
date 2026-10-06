<?php

namespace Tests\Unit;

use App\Models\EnemyAction;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\BattleService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class EnemyMagicalDrainTest extends TestCase
{
    public static function recoveryCases(): array
    {
        return [
            'overkill uses actual loss' => [100, 400, false, 0.0, 435],
            'barrier absorbs all damage' => [0, 400, false, 0.0, 400],
            'dead caster cannot revive' => [100, 0, false, 0.0, 0],
            'lineage suppresses recovery' => [100, 400, true, 0.0, 400],
            'maximum HP cap' => [100, 990, false, 0.0, 1000],
            'recovery block' => [100, 400, false, 0.5, 417],
        ];
    }

    #[DataProvider('recoveryCases')]
    public function test_recovery_uses_actual_loss_and_existing_healing_rules(int $loss, int $casterHp, bool $suppressed, float $blocked, int $expected): void
    {
        $caster = new BattleActor('魂葬王', false, ['hp' => $casterHp, 'max_hp' => 1000]);
        $target = new BattleActor('冒険者', true, ['hp' => 100, 'max_hp' => 100]);
        if ($blocked > 0) {
            $caster->conditions['recovery_block'] = ['turns' => 3, 'rate' => $blocked];
        }
        $state = new BattleState($target, $caster);
        $state->enemyTelegraphContext = ['lineage_suppressed' => $suppressed];
        $service = Mockery::mock(BattleService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('executeMagicalAttack')->once()->with($caster, $target, $state, 100)
            ->andReturn(new DamageApplicationResult(1000, 100, 100 - $loss, $loss, 900, $loss === 100,
                DamageSourceType::OTHER, null, null, 1, 1));
        $this->drain($service, $caster, $target, $state);
        $this->assertSame($expected, $caster->hp);
        $this->assertSame($expected - $casterHp, $caster->totalHpHealed);
        $this->assertSame($expected > $casterHp, str_contains(implode('', $state->logs), '魂を吸収'));
    }

    public function test_missed_magic_does_not_heal(): void
    {
        $caster = new BattleActor('魂葬王', false, ['hp' => 400, 'max_hp' => 1000]);
        $target = new BattleActor('冒険者', true, ['max_hp' => 100]);
        $state = new BattleState($target, $caster);
        $service = Mockery::mock(BattleService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('executeMagicalAttack')->once()->andReturn(null);
        $this->drain($service, $caster, $target, $state);
        $this->assertSame(400, $caster->hp);
        $this->assertStringNotContainsString('魂を吸収', implode('', $state->logs));
    }

    public function test_real_magic_returns_actual_hp_loss_after_overkill(): void
    {
        $caster = new BattleActor('魂葬王', false, ['mag' => 100000, 'max_hp' => 1000]);
        $target = new BattleActor('冒険者', true, ['hp' => 100, 'max_hp' => 100, 'spr' => 1]);
        $state = new BattleState($target, $caster);
        $result = (new ReflectionMethod(BattleService::class, 'executeMagicalAttack'))
            ->invoke(app(BattleService::class), $caster, $target, $state, 100, null, true);
        $this->assertInstanceOf(DamageApplicationResult::class, $result);
        $this->assertSame(100, $result->actualHpLoss);
        $this->assertGreaterThan(100, $result->requestedDamage);
        $this->assertSame(0, $target->hp);
    }

    public function test_real_counter_killing_the_caster_prevents_drain_revival(): void
    {
        $caster = new BattleActor('魂葬王', false, ['hp' => 1, 'max_hp' => 1000, 'mag' => 20000]);
        $target = new BattleActor('冒険者', true, ['max_hp' => 100000, 'spr' => 1]);
        $target->namelessRelicsEnabled = true;
        $target->namelessRelicEffects = ['counter' => 1.0];
        $state = new BattleState($target, $caster);
        $calculator = Mockery::mock(DamageCalculator::class)->makePartial();
        $calculator->shouldReceive('isHit')->once()->andReturn(true);
        $service = app(BattleService::class);
        (new ReflectionProperty(BattleService::class, 'damageCalculator'))->setValue($service, $calculator);
        $this->drain($service, $caster, $target, $state);
        $this->assertSame(0, $caster->hp);
        $this->assertSame(0, $caster->totalHpHealed);
        $this->assertLessThan(100000, $target->hp);
        $this->assertStringContainsString('【返刃】', implode('', $state->logs));
        $this->assertStringNotContainsString('魂を吸収', implode('', $state->logs));
    }

    private function drain(BattleService $service, BattleActor $caster, BattleActor $target, BattleState $state): void
    {
        $action = new EnemyAction(['action_type' => 'magical_drain', 'power_percent' => 100, 'effect_percent' => 35]);
        (new ReflectionMethod(BattleService::class, 'executeEnemyActionEffect'))->invoke($service, $caster, $target, $state, $action);
    }
}
