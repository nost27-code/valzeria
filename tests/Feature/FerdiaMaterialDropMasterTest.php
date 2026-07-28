<?php

namespace Tests\Feature;

use Database\Seeders\FerdiaRegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(68, $this->ferdiaDropQuery()->count());

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
    }

    public function test_material_drop_repair_is_idempotent(): void
    {
        $before = $this->ferdiaDropQuery()->count();

        app(FerdiaRegionSeeder::class)->seedMaterialDrops();
        app(FerdiaRegionSeeder::class)->seedMaterialDrops();

        $this->assertSame($before, $this->ferdiaDropQuery()->count());
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
}
