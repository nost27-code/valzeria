<?php

namespace Tests\Unit;

use Tests\TestCase;

class MainTabPanelCacheViewTest extends TestCase
{
    public function test_main_screen_shell_loads_selected_tab_panels_and_keeps_them_mounted(): void
    {
        $source = file_get_contents(resource_path('views/livewire/main-screen-shell.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('x-show="currentLocation === @js($location)"', $source);
        $this->assertStringContainsString("style=\"{{ \$currentLocation === \$location ? '' : 'display: none;' }}\"", $source);
        $this->assertStringContainsString(':fixed-location="$location"', $source);
        $this->assertStringContainsString('in_array($location, $loadedTabLocations, true)', $source);
        $this->assertStringNotContainsString('lazy="on-load"', $source);
        $this->assertStringNotContainsString('preloadCachedTab', $source);
        $this->assertStringContainsString("'main-tab-panel-'.\$location", $source);
        $this->assertStringContainsString('data-main-tab-utility', $source);
        $this->assertStringContainsString("['placeholderLocation' => \$location]", $source);
    }

    public function test_main_tab_placeholder_offers_a_retry_instead_of_spinning_forever(): void
    {
        $source = file_get_contents(resource_path('views/livewire/main-screen-placeholder.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('読み込みに時間がかかっています', $source);
        $this->assertStringContainsString("\$dispatch('changeTab'", $source);
        $this->assertStringContainsString('もう一度読み込む', $source);
    }

    public function test_heavy_main_screen_no_longer_listens_for_every_tab_change(): void
    {
        $source = file_get_contents(app_path('Livewire/MainScreen.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("#[On('changeTab')]", $source);
        $this->assertStringContainsString('public bool $embedded = false;', $source);
    }

    public function test_nation_tab_hides_shared_competition_panels(): void
    {
        $source = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('data-shared-competition-panels', $source);
        $this->assertStringContainsString("x-show=\"currentLocation !== 'nation'\"", $source);
        $this->assertStringContainsString("style=\"{{ \$currentLocation !== 'nation' ? '' : 'display: none;' }}\"", $source);
        $this->assertMatchesRegularExpression(
            '/data-shared-competition-panels>.*<livewire:champ-card \/>.*<livewire:star-tree-tower-ranking-widget \/>.*<\/div>/s',
            $source,
        );
    }

    public function test_bottom_navigation_places_nation_between_market_and_colosseum(): void
    {
        $source = file_get_contents(resource_path('views/livewire/nav-menu.blade.php'));

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/'guild'.*'nation'.*'colosseum'/s",
            $source,
        );
        $this->assertStringContainsString("'label' => '国家'", $source);
        $this->assertStringContainsString("'image' => 'icon/icon_305.webp'", $source);
        $this->assertStringContainsString("'image_class' => 'p-2'", $source);
        $this->assertStringContainsString("{{ \$nav['image_class'] ?? '' }}", $source);
        $this->assertStringContainsString("'grid-cols-6'", $source);
    }
}
