<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\Enemy\EnemyStatGenerationService;
use App\Services\Enemy\EnemyStatPreviewService;
use ReflectionClass;
use Tests\TestCase;

class EnemyStatGenerationServiceTest extends TestCase
{
    public function test_base_stats_follow_configured_curve(): void
    {
        $service = app(EnemyStatGenerationService::class);

        $this->assertSame([
            'hp' => 31,
            'attack' => 11,
            'defense' => 5,
            'magic' => 4,
            'spirit' => 4,
            'speed' => 7,
            'luck' => 3,
        ], $service->baseStats(1));
    }

    public function test_unknown_keys_fall_back_to_defaults(): void
    {
        $service = app(EnemyStatGenerationService::class);
        $generated = $service->generate(1, 'missing_family', 'missing_variant', 'missing_role');

        $this->assertSame('standard', $generated['family_key']);
        $this->assertSame('none', $generated['variant_key']);
        $this->assertSame('normal', $generated['role_key']);
    }

    public function test_golden_enemy_is_fast_and_fragile(): void
    {
        $service = app(EnemyStatGenerationService::class);
        $normal = $service->generate(30, 'goblin', 'none', 'normal')['stats'];
        $golden = $service->generate(30, 'goblin', 'none', 'golden')['stats'];

        $this->assertLessThan($normal['hp'], $golden['hp']);
        $this->assertGreaterThan($normal['speed'], $golden['speed']);
        $this->assertGreaterThan($normal['luck'], $golden['luck']);
    }

    public function test_magical_families_have_meaningful_magic_offense(): void
    {
        $service = app(EnemyStatGenerationService::class);
        $mageBoss = $service->generate(65, 'mage', 'ice', 'boss')['stats'];
        $standardBoss = $service->generate(65, 'standard', 'ice', 'boss')['stats'];

        $this->assertGreaterThan($mageBoss['attack'], $mageBoss['magic']);
        $this->assertGreaterThan($standardBoss['magic'], $mageBoss['magic']);
    }

    public function test_dragon_breath_has_meaningful_magic_offense(): void
    {
        $service = app(EnemyStatGenerationService::class);
        $dragon = $service->generate(69, 'dragon', 'ice', 'deep_candidate')['stats'];

        $this->assertGreaterThanOrEqual((int) round($dragon['attack'] * 0.9), $dragon['magic']);
    }

    public function test_magic_type_floor_can_lift_non_magical_families(): void
    {
        $service = app(EnemyStatPreviewService::class);
        $method = (new ReflectionClass($service))->getMethod('applyHybridOffenseFloor');
        $method->setAccessible(true);

        $stats = $method->invoke($service, new Enemy(['type_name' => '魔法型']), [
            'max_hp' => 1000,
            'str' => 120,
            'def' => 80,
            'agi' => 60,
            'mag' => 48,
            'spr' => 70,
            'luk' => 10,
        ]);

        $this->assertSame(132, $stats['mag']);
        $this->assertSame(120, $stats['str']);
    }
}
