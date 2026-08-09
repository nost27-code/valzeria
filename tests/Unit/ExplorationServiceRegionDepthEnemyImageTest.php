<?php

namespace Tests\Unit;

use App\Models\Enemy;
use App\Services\ExplorationService;
use App\Services\RegionDepthDungeonService;
use ReflectionClass;
use Tests\TestCase;

class ExplorationServiceRegionDepthEnemyImageTest extends TestCase
{
    public function test_region_depth_enemy_keeps_base_portrait_and_receives_the_danger_prefix_once(): void
    {
        config()->set('enemy_images', [
            '黒鉱甲虫' => 'images/enemy/enemy_721.webp',
        ]);

        $baseEnemy = new Enemy();
        $baseEnemy->name = '黒鉱甲虫';

        $reflection = new ReflectionClass(ExplorationService::class);
        $explorationService = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('prepareRegionDepthEnemy');

        [$enemy, $imagePath] = $method->invoke(
            $explorationService,
            $baseEnemy,
            new RegionDepthDungeonService(),
            40,
            'granberg_black_furnace',
        );

        $this->assertSame('黒鉱甲虫', $baseEnemy->name);
        $this->assertSame('硬質化した黒鉱甲虫', $enemy->name);
        $this->assertSame('images/enemy/enemy_721.webp', $imagePath);
        $this->assertSame('granberg_black_furnace', $enemy->getAttribute('region_depth_dungeon_key'));
        $this->assertSame(40, $enemy->getAttribute('region_depth_danger_rate'));
    }

    public function test_explore_uses_the_prepared_image_path_without_an_inline_second_prefix(): void
    {
        $source = file_get_contents(app_path('Services/ExplorationService.php'));

        $this->assertIsString($source);
        $this->assertSame(2, substr_count($source, 'prepareRegionDepthEnemy('));
        $this->assertStringContainsString("'enemy_image_path' => \$enemyImagePath", $source);
        $this->assertStringNotContainsString('enemyPrefix($currentDanger)', $source);
    }
}
