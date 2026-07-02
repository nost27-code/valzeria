<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\JobClass;
use App\Services\CharacterJobChangeService;
use App\Services\PublicLogService;
use PHPUnit\Framework\TestCase;

class CharacterJobChangeServiceTest extends TestCase
{
    public function test_advanced_or_lower_job_change_inherits_half_base_stats(): void
    {
        $service = new CharacterJobChangeService(new PublicLogService());

        $stats = $service->calculateInheritedStats($this->character(), new JobClass(['rank' => 'advanced']));

        $this->assertSame(150, $stats['hp_base']);
        $this->assertSame(45, $stats['mp_base']);
        $this->assertSame(30, $stats['attack_base']);
        $this->assertSame(27, $stats['defense_base']);
        $this->assertSame(24, $stats['speed_base']);
        $this->assertSame(21, $stats['magic_base']);
        $this->assertSame(18, $stats['spirit_base']);
        $this->assertSame(15, $stats['luck_base']);
    }

    public function test_super_or_higher_job_change_inherits_third_base_stats(): void
    {
        $service = new CharacterJobChangeService(new PublicLogService());

        foreach (['super', 'crown', 'hero', 'legend', 'myth'] as $rank) {
            $stats = $service->calculateInheritedStats($this->character(), new JobClass(['rank' => $rank]));

            $this->assertSame(100, $stats['hp_base'], $rank);
            $this->assertSame(30, $stats['mp_base'], $rank);
            $this->assertSame(20, $stats['attack_base'], $rank);
            $this->assertSame(18, $stats['defense_base'], $rank);
            $this->assertSame(16, $stats['speed_base'], $rank);
            $this->assertSame(14, $stats['magic_base'], $rank);
            $this->assertSame(12, $stats['spirit_base'], $rank);
            $this->assertSame(10, $stats['luck_base'], $rank);
        }
    }

    private function character(): Character
    {
        return new Character([
            'hp_base' => 300,
            'mp_base' => 90,
            'attack_base' => 60,
            'defense_base' => 54,
            'speed_base' => 48,
            'magic_base' => 42,
            'spirit_base' => 36,
            'luck_base' => 30,
        ]);
    }
}
