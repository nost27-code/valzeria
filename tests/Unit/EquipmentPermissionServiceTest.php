<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Services\EquipmentPermissionService;
use Tests\TestCase;

class EquipmentPermissionServiceTest extends TestCase
{
    public function test_weapon_categories_have_japanese_display_labels(): void
    {
        $service = new EquipmentPermissionService();

        $this->assertSame('剣', $service->categoryLabel(new Item([
            'type' => 'weapon',
            'weapon_category' => 'sword',
        ])));
        $this->assertSame('銃', $service->categoryLabel(new Item([
            'type' => 'weapon',
            'weapon_category' => 'gun',
        ])));
    }
}
