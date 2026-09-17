<?php

namespace Tests\Feature;

use App\Services\EquipmentEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AccessoryStarMaterialSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_star_material_source_does_not_advertise_unavailable_decomposition_or_drops(): void
    {
        $method = new ReflectionMethod(EquipmentEvolutionService::class, 'materialSources');
        $sources = $method->invoke(app(EquipmentEvolutionService::class), 'ACC0005');
        $labels = array_column($sources, 'label');

        $this->assertContains('新たな入手方法は準備中です。', $labels);
        $this->assertNotContains('装飾品分解・敵ドロップ', $labels);
    }
}
