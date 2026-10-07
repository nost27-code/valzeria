<?php

namespace Tests\Unit;

use App\Models\PlayerNamelessEquipment;
use Tests\TestCase;

class NamelessWeaponAppearanceTest extends TestCase
{
    public function test_all_weapon_types_switch_at_the_configured_boundaries(): void
    {
        foreach (config('nameless_equipment_images.weapon') as $type => $base) {
            $stages = config('nameless_equipment_images.weapon_forge_stages.'.$type);
            $this->assertSame([20, 40, 60, 80, 99], array_keys($stages));
            foreach ([-1 => $base, 0 => $base, 19 => $base, 20 => $stages[20], 39 => $stages[20],
                40 => $stages[40], 59 => $stages[40], 60 => $stages[60], 79 => $stages[60],
                80 => $stages[80], 98 => $stages[80], 99 => $stages[99], 100 => $stages[99]] as $level => $expected) {
                $body = new PlayerNamelessEquipment(['kind' => 'weapon', 'equipment_type' => $type,
                    'forge_level' => $level, 'custom_name' => '自分だけの名前']);
                $before = $body->getAttributes();
                $this->assertSame($expected, $body->imagePath(), $type.' +'.$level);
                $this->assertSame($before, $body->getAttributes());
                $this->assertSame('自分だけの名前', $body->displayName());
            }
        }
    }

    public function test_armor_and_accessories_keep_their_original_images_at_every_level(): void
    {
        foreach (['armor', 'accessory'] as $kind) {
            foreach (config('nameless_equipment_images.'.$kind) as $type => $path) {
                foreach ([0, 20, 40, 60, 80, 99] as $level) {
                    $this->assertSame($path, (new PlayerNamelessEquipment([
                        'kind' => $kind, 'equipment_type' => $type, 'forge_level' => $level,
                    ]))->imagePath());
                }
            }
        }
    }

    public function test_missing_stage_configuration_falls_back_without_using_the_custom_name(): void
    {
        config(['nameless_equipment_images.weapon_forge_stages.剣' => []]);
        $body = new PlayerNamelessEquipment(['kind' => 'weapon', 'equipment_type' => '剣',
            'custom_name' => '名もなき斧', 'forge_level' => 99]);
        $this->assertSame(config('nameless_equipment_images.weapon.剣'), $body->imagePath());
        $body->equipment_type = '未定義の武器';
        $this->assertNull($body->imagePath());
    }

    public function test_all_stage_assets_are_existing_square_webp_images(): void
    {
        foreach (config('nameless_equipment_images.weapon_forge_stages') as $stages) {
            foreach ($stages as $path) {
                $absolute = public_path($path);
                $this->assertFileExists($absolute);
                $image = getimagesize($absolute);
                $this->assertSame([300, 300], [$image[0], $image[1]]);
                $this->assertSame('image/webp', $image['mime']);
            }
        }
    }
}
