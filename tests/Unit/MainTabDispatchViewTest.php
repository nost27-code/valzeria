<?php

namespace Tests\Unit;

use Tests\TestCase;

class MainTabDispatchViewTest extends TestCase
{
    public function test_main_tab_links_dispatch_from_the_browser_without_a_livewire_relay_action(): void
    {
        $viewPaths = [
            resource_path('views/livewire/nav-menu.blade.php'),
            resource_path('views/livewire/home-action-panel.blade.php'),
            resource_path('views/livewire/city-header.blade.php'),
            resource_path('views/livewire/main-screen.blade.php'),
        ];

        $dispatchCount = 0;

        foreach ($viewPaths as $viewPath) {
            $this->assertFileExists($viewPath);

            $source = file_get_contents($viewPath);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('wire:click="$dispatch(\'changeTab\'', $source);
            $dispatchCount += substr_count($source, '$dispatch(\'changeTab\'');
        }

        $this->assertGreaterThanOrEqual(8, $dispatchCount);
    }
}
