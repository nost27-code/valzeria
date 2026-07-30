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

    public function test_picker_reuses_equipment_search_filters_and_sorting(): void
    {
        $viewPath = resource_path('views/smith/traits.blade.php');

        $this->assertFileExists($viewPath);

        $source = file_get_contents($viewPath);

        $this->assertIsString($source);
        $this->assertStringContainsString('x-model.debounce.150ms="pickerQuery"', $source);
        $this->assertStringContainsString("pickerType: 'all'", $source);
        $this->assertStringContainsString("pickerStatus: 'all'", $source);
        $this->assertStringContainsString("pickerQuality: 'all'", $source);
        $this->assertStringContainsString("pickerTrait: 'all'", $source);
        $this->assertStringContainsString("pickerSort: 'default'", $source);
        $this->assertStringContainsString('matchesPickerItem(item)', $source);
        $this->assertStringContainsString('sortPickerItems(items)', $source);
        $this->assertStringContainsString('装備名・装備種・ランク・銘・特攻を入力', $source);
        $this->assertStringContainsString('条件に合う装備がありません。', $source);
    }
}
