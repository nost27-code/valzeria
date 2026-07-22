<?php

namespace Tests\Unit;

use App\Models\Material;
use App\Models\MaterialDrop;
use App\Services\DropService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class DropServiceMapMaterialWeightTest extends TestCase
{
    public function test_map_common_material_weight_is_halved_without_changing_other_candidates(): void
    {
        $drops = collect([
            $this->drop('MAT_COMMON_MONSTER_FRAGMENT', 20),
            $this->drop('MAT_COMMON_OLD_BADGE', 15),
            $this->drop('MAT_REGION_BLACK_IRON_PART', 18),
        ]);
        $method = new ReflectionMethod(DropService::class, 'withCommonMaterialWeightMultiplier');
        $method->setAccessible(true);

        /** @var Collection<int, MaterialDrop> $adjusted */
        $adjusted = $method->invoke(app(DropService::class), $drops, 0.5);

        $this->assertSame(10.0, (float) $adjusted[0]->drop_rate);
        $this->assertSame(7.5, (float) $adjusted[1]->drop_rate);
        $this->assertSame(18.0, (float) $adjusted[2]->drop_rate);
        $this->assertSame(20.0, (float) $drops[0]->drop_rate);
    }

    public function test_map_rate_bonuses_raise_only_the_specified_drop_slots(): void
    {
        $method = new ReflectionMethod(DropService::class, 'withRateBonuses');
        $method->setAccessible(true);

        $rates = $method->invoke(app(DropService::class), [
            'material' => 50.0,
            'weapon' => 0.75,
            'armor' => 0.75,
            'accessory' => 0.25,
        ], ['material' => 5, 'weapon' => 0.10, 'armor' => 0.10, 'accessory' => 0.03]);

        $this->assertSame(55.0, $rates['material']);
        $this->assertSame(0.85, $rates['weapon']);
        $this->assertSame(0.85, $rates['armor']);
        $this->assertSame(0.28, $rates['accessory']);
    }

    private function drop(string $materialCode, float $rate): MaterialDrop
    {
        $drop = new MaterialDrop(['drop_rate' => $rate]);
        $drop->setRelation('material', new Material(['material_code' => $materialCode]));

        return $drop;
    }
}
