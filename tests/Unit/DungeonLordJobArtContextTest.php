<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\BattleService;
use ReflectionMethod;
use Tests\TestCase;

class DungeonLordJobArtContextTest extends TestCase
{
    public function test_dungeon_lord_uses_the_boss_job_art_set_without_becoming_a_boss(): void
    {
        $enemy = new Enemy([
            'is_boss' => false,
            'role' => 'ダンジョン主',
        ]);

        $this->assertSame('boss', $this->jobArtBattleContext($enemy));
        $this->assertFalse((bool) $enemy->is_boss);
    }

    public function test_normal_enemy_keeps_the_normal_job_art_set(): void
    {
        $enemy = new Enemy([
            'is_boss' => false,
            'role' => '通常',
        ]);

        $this->assertSame('pve', $this->jobArtBattleContext($enemy));
    }

    private function jobArtBattleContext(Enemy $enemy): string
    {
        $method = new ReflectionMethod(BattleService::class, 'jobArtBattleContext');
        $method->setAccessible(true);

        return $method->invoke(app(BattleService::class), $enemy);
    }
}
