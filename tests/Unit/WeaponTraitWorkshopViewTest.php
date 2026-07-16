<?php

namespace Tests\Unit;

use Tests\TestCase;

class WeaponTraitWorkshopViewTest extends TestCase
{
    public function test_forge_preview_uses_only_the_base_weapon_rank_cap(): void
    {
        $viewPath = resource_path('views/smith/traits.blade.php');

        $this->assertFileExists($viewPath);

        $source = file_get_contents($viewPath);

        $this->assertIsString($source);
        $this->assertStringContainsString('canHoldLevel(base, level)', $source);
        $this->assertStringContainsString('return level <= base.maximum_level;', $source);
        $this->assertStringNotContainsString('level <= material.maximum_level', $source);
        $this->assertStringNotContainsString('canHoldLevel(base, material', $source);
    }
}
