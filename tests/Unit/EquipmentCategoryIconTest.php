<?php

namespace Tests\Unit;

use App\Models\Item;
use Tests\TestCase;

class EquipmentCategoryIconTest extends TestCase
{
    public function test_club_uses_its_own_icon_without_changing_axe_proficiency_category(): void
    {
        $shopClub = new Item([
            'type' => 'weapon',
            'weapon_category' => 'axe',
            'sub_type' => '棍棒',
        ]);
        $branchClub = new Item([
            'type' => 'weapon',
            'weapon_category' => 'axe',
            'weapon_family_id' => 'CLUB_HOLY',
        ]);
        $legacyClub = new Item([
            'name' => '小鬼の棍棒',
            'type' => 'weapon',
        ]);
        $axe = new Item([
            'type' => 'weapon',
            'weapon_category' => 'axe',
            'weapon_family_id' => 'AXE',
            'sub_type' => '斧',
        ]);

        $this->assertSame('axe', $shopClub->weapon_category);
        $this->assertSame('images/icon/icon_307.webp', $shopClub->iconImagePath());
        $this->assertSame('images/icon/icon_307.webp', $branchClub->iconImagePath());
        $this->assertSame('images/icon/icon_307.webp', $legacyClub->iconImagePath());
        $this->assertSame('images/icon/icon_228.webp', $axe->iconImagePath());
    }

    public function test_katana_uses_its_own_icon(): void
    {
        $katana = new Item([
            'type' => 'weapon',
            'weapon_category' => 'katana',
        ]);

        $this->assertSame('images/icon/icon_308.webp', $katana->iconImagePath());
    }

    public function test_new_weapon_category_icons_exist_as_webp_images(): void
    {
        foreach (['images/icon/icon_307.webp', 'images/icon/icon_308.webp'] as $path) {
            $absolutePath = public_path($path);

            $this->assertFileExists($absolutePath);
            $this->assertSame('image/webp', mime_content_type($absolutePath));
            $this->assertSame([160, 160], array_slice(getimagesize($absolutePath), 0, 2));
        }
    }
}
