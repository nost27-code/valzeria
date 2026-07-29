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

    public function test_shared_proficiency_categories_have_distinct_weapon_role_labels(): void
    {
        $service = new EquipmentPermissionService();

        $cases = [
            ['axe', '斧', '重量武器', '一撃型'],
            ['axe', '棍棒', '重量武器', '堅守型'],
            ['gun', '銃', '銃器', '先手型'],
            ['gun', '機工銃', '銃器', '物魔両用型'],
        ];

        foreach ($cases as [$category, $subType, $groupLabel, $roleLabel]) {
            $item = new Item([
                'type' => 'weapon',
                'weapon_category' => $category,
                'sub_type' => $subType,
            ]);

            $this->assertSame($groupLabel, $service->proficiencyGroupLabel($item));
            $this->assertSame($roleLabel, $service->weaponRoleLabel($item));
        }
    }
}
