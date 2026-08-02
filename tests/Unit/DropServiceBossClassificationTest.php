<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\DropService;
use App\Services\MonsterMarkService;
use ReflectionMethod;
use Tests\TestCase;

class DropServiceBossClassificationTest extends TestCase
{
    public function test_non_boss_boss_candidate_is_eligible_for_the_mark_roll_in_the_boss_branch(): void
    {
        $enemy = new Enemy([
            'is_boss' => false,
            'role' => 'ボス候補',
        ]);

        $this->assertTrue($this->invokeBooleanMethod('isBossEnemy', $enemy));
        $this->assertTrue($this->invokeBooleanMethod('isNonBossBossCandidate', $enemy));
        $this->assertTrue($this->invokeBooleanMethod('isEligibleEnemy', $enemy, MonsterMarkService::class));
    }

    public function test_actual_boss_is_not_eligible_for_the_candidate_mark_roll(): void
    {
        $flaggedBoss = new Enemy([
            'is_boss' => true,
            'role' => 'ボス候補',
        ]);
        $roleBoss = new Enemy([
            'is_boss' => false,
            'role' => '通常ボス',
        ]);

        $this->assertTrue($this->invokeBooleanMethod('isBossEnemy', $flaggedBoss));
        $this->assertFalse($this->invokeBooleanMethod('isNonBossBossCandidate', $flaggedBoss));
        $this->assertFalse($this->invokeBooleanMethod('isEligibleEnemy', $flaggedBoss, MonsterMarkService::class));
        $this->assertTrue($this->invokeBooleanMethod('isBossEnemy', $roleBoss));
        $this->assertFalse($this->invokeBooleanMethod('isNonBossBossCandidate', $roleBoss));
    }

    private function invokeBooleanMethod(
        string $methodName,
        Enemy $enemy,
        string $serviceClass = DropService::class
    ): bool
    {
        $method = new ReflectionMethod($serviceClass, $methodName);
        $method->setAccessible(true);

        return $method->invoke(app($serviceClass), $enemy);
    }
}
