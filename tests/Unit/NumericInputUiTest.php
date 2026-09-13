<?php

namespace Tests\Unit;

use Tests\TestCase;

class NumericInputUiTest extends TestCase
{
    public function test_valmon_material_feed_allows_the_current_value_to_be_replaced_before_clamping(): void
    {
        $view = (string) file_get_contents(resource_path('views/valmons/index.blade.php'));

        $this->assertStringContainsString('data-valmon-material-feed-quantity', $view);
        $this->assertStringContainsString('@focus="$event.target.select()"', $view);
        $this->assertStringContainsString('@blur="normalizeQty()"', $view);
        $this->assertStringNotContainsString(
            '@input="qty = Math.min(max, Math.max(1, Number(qty) || 1))"',
            $view,
        );
    }

    public function test_inventory_material_sale_shows_and_accepts_the_selected_quantity_directly(): void
    {
        $view = (string) file_get_contents(resource_path('views/inventory/index.blade.php'));

        $this->assertStringContainsString('data-inventory-sale-quantity', $view);
        $this->assertStringContainsString('売却数', $view);
        $this->assertStringContainsString('x-text="saleQty || 0"', $view);
        $this->assertStringContainsString('inputmode="numeric"', $view);
        $this->assertStringContainsString('売却数をすばやく選ぶ', $view);
    }

    public function test_exploration_count_input_is_exposed_without_a_mode_toggle_on_home_and_result_screens(): void
    {
        foreach ([
            'views/livewire/main-screen.blade.php',
            'views/battle/result.blade.php',
        ] as $path) {
            $view = (string) file_get_contents(resource_path($path));

            $this->assertStringContainsString('data-exploration-repeat-count', $view, $path);
            $this->assertStringContainsString('直接入力（2〜50回）', $view, $path);
            $this->assertStringContainsString('@focus="selectCustomCount(); $event.target.select()"', $view, $path);
        }
    }
}
