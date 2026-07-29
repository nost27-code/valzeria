<?php

namespace Tests\Unit;

use Tests\TestCase;

class ShopEquipmentMobileFilterTest extends TestCase
{
    public function test_subtype_filter_does_not_depend_on_the_initial_dom_content_loaded_event(): void
    {
        $view = file_get_contents(resource_path('views/shop/list.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("selectedSubtype: 'all'", $view);
        $this->assertStringContainsString(
            'x-on:click="selectedSubtype = $el.dataset.filter"',
            $view,
        );
        $this->assertStringContainsString(
            "x-show=\"selectedSubtype === 'all' || selectedSubtype === \$el.dataset.subtype\"",
            $view,
        );
        $this->assertStringNotContainsString(
            "document.addEventListener('DOMContentLoaded'",
            $view,
        );
    }
}
