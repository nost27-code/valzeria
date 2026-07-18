<?php

namespace Tests\Unit;

use Tests\TestCase;

class MainTabPanelCacheViewTest extends TestCase
{
    public function test_main_screen_shell_keeps_lazy_tab_panels_mounted(): void
    {
        $source = file_get_contents(resource_path('views/livewire/main-screen-shell.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('x-show="currentLocation === @js($location)"', $source);
        $this->assertStringContainsString("style=\"{{ \$currentLocation === \$location ? '' : 'display: none;' }}\"", $source);
        $this->assertStringContainsString(':fixed-location="$location"', $source);
        $this->assertStringContainsString('lazy="on-load"', $source);
        $this->assertStringContainsString("'main-tab-panel-'.\$location", $source);
        $this->assertStringContainsString('data-main-tab-utility', $source);
    }

    public function test_heavy_main_screen_no_longer_listens_for_every_tab_change(): void
    {
        $source = file_get_contents(app_path('Livewire/MainScreen.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("#[On('changeTab')]", $source);
        $this->assertStringContainsString('public bool $embedded = false;', $source);
    }
}
