<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Enemy;
use App\Services\ExplorationService;
use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class FerdiaMaterialDropMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_main_ferdia_areas_have_normal_and_ancient_material_drops(): void
    {
        $this->assertSame(65, DB::table('enemies')
            ->whereBetween('area_id', [1001, 1013])
            ->where('is_boss', false)
            ->count());
        $this->assertSame(69, $this->ferdiaDropQuery()->count());

        foreach (range(1001, 1013) as $areaId) {
            $this->assertTrue(
                (clone $this->ferdiaDropQuery())
                    ->where('enemy.area_id', $areaId)
                    ->where('material.name', 'not like', '%古代片%')
                    ->exists(),
                "Area {$areaId} is missing a normal Ferdia material drop."
            );
            $this->assertTrue(
                (clone $this->ferdiaDropQuery())
                    ->where('enemy.area_id', $areaId)
                    ->where('material.name', 'like', '%古代片%')
                    ->exists(),
                "Area {$areaId} is missing an ancient material drop."
            );
        }

        $this->assertEqualsWithDelta(
            33.0,
            $this->dropRate(1001, 'スライム', 'MAT_FERDIA_HEMOSTATIC_MOSS'),
            0.001
        );
        $this->assertEqualsWithDelta(
            0.76,
            $this->dropRate(1001, '人型', 'MAT_BR_ARM_TRAVELER_ANCIENT'),
            0.001
        );
        $this->assertEqualsWithDelta(
            8.03,
            $this->dropRate(1011, '巨人', 'MAT_FERDIA_LIFEROOT'),
            0.001
        );
        $this->assertEqualsWithDelta(
            0.60,
            $this->dropRate(1011, '巨人', 'MAT_BR_WPN_GALE_ANCIENT'),
            0.001
        );
        $this->assertEqualsWithDelta(
            12.04,
            $this->dropRate(1005, '昆虫', 'MAT_FERDIA_DETOX_GALL'),
            0.001
        );
        $this->assertEqualsWithDelta(
            33.0,
            $this->dropRate(1008, '昆虫', 'MAT_FERDIA_GUARDTREE_RESIN'),
            0.001
        );
        $this->assertFalse($this->hasDrop(1008, '昆虫', 'MAT_FERDIA_BLUE_LIFE_LEAF'));
    }

    public function test_ferdia_treasure_keeps_one_slot_for_the_area_representative_material(): void
    {
        $area = Area::query()->findOrFail(1005);
        $baseEnemy = Enemy::query()
            ->where('area_id', $area->id)
            ->where('is_boss', false)
            ->firstOrFail();
        $method = new ReflectionMethod(ExplorationService::class, 'treasureRewardMaterials');
        $method->setAccessible(true);

        $materials = $method->invoke(app(ExplorationService::class), $area, $baseEnemy, 4);

        $this->assertCount(4, $materials);
        $this->assertSame('止血苔', $materials[0]->name);
        $this->assertFalse(collect($materials)->contains(
            fn ($material): bool => str_contains((string) $material->name, '古代片')
        ));
    }

    public function test_material_drop_repair_is_idempotent(): void
    {
        $before = $this->ferdiaDropQuery()->count();

        app(FerdiaRegionSeeder::class)->seedMaterialDrops();
        app(FerdiaRegionSeeder::class)->seedMaterialDrops();

        $this->assertSame($before, $this->ferdiaDropQuery()->count());
    }

    public function test_material_drop_repair_recreates_missing_apothecary_material_masters(): void
    {
        $materialCodes = [
            'MAT_FERDIA_BLUE_LIFE_LEAF',
            'MAT_FERDIA_CLEARSTREAM_DROP',
            'MAT_FERDIA_GUARDTREE_RESIN',
            'MAT_FERDIA_HEMOSTATIC_MOSS',
            'MAT_FERDIA_DETOX_GALL',
            'MAT_FERDIA_LIFEROOT',
        ];
        $materialIds = DB::table('materials')->whereIn('material_code', $materialCodes)->pluck('id');
        DB::table('material_drops')->whereIn('material_id', $materialIds)->delete();
        DB::table('materials')->whereIn('id', $materialIds)->delete();

        app(FerdiaRegionSeeder::class)->seedMaterialDrops();

        $this->assertSame(6, DB::table('materials')->whereIn('material_code', $materialCodes)->count());
        $this->assertSame(69, $this->ferdiaDropQuery()->count());
    }

    public function test_dungeon_validation_detects_missing_ferdia_material_drops(): void
    {
        $enemyIds = DB::table('enemies')
            ->whereBetween('area_id', [1001, 1013])
            ->where('is_boss', false)
            ->pluck('id');
        DB::table('material_drops')->whereIn('enemy_id', $enemyIds)->delete();

        $this->artisan('dungeon:validate')->assertFailed();

        app(FerdiaRegionSeeder::class)->seedMaterialDrops();

        $this->artisan('dungeon:validate')->assertSuccessful();
    }

    private function ferdiaDropQuery()
    {
        return DB::table('material_drops as material_drop')
            ->join('enemies as enemy', 'enemy.id', '=', 'material_drop.enemy_id')
            ->join('materials as material', 'material.id', '=', 'material_drop.material_id')
            ->whereBetween('enemy.area_id', [1001, 1013])
            ->where('enemy.is_boss', false)
            ->where('material_drop.is_active', true)
            ->where('material_drop.drop_first_clear_only', false)
            ->where('material_drop.drop_rate', '>', 0);
    }

    private function dropRate(int $areaId, string $enemyType, string $materialCode): float
    {
        return (float) (clone $this->ferdiaDropQuery())
            ->where('enemy.area_id', $areaId)
            ->where('enemy.type_name', $enemyType)
            ->where('material.material_code', $materialCode)
            ->value('material_drop.drop_rate');
    }

    private function hasDrop(int $areaId, string $enemyType, string $materialCode): bool
    {
        return (clone $this->ferdiaDropQuery())
            ->where('enemy.area_id', $areaId)
            ->where('enemy.type_name', $enemyType)
            ->where('material.material_code', $materialCode)
            ->exists();
    }
}
