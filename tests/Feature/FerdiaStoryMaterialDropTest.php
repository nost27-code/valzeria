<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\User;
use App\Services\DropService;
use App\Services\ExplorationService;
use Database\Seeders\AllDungeonsSeeder;
use Database\Seeders\FerdiaStoryMaterialDropSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class FerdiaStoryMaterialDropTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVED_DROPS = [
        [1025, 'スライム', 'MAT_FERDIA_GUARDTREE_RESIN', 33.00],
        [1025, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 32.11],
        [1025, '人型', 'MAT_BR_ARM_ARCANE_ANCIENT', 0.86],
        [1025, '巨人', 'MAT_BR_ARM_ARCANE_ANCIENT', 0.68],
        [1025, '巨人', 'MAT_FERDIA_LIFEROOT', 8.03],
        [1025, '飛行', 'MAT_FERDIA_LIFEROOT', 12.04],
        [1026, 'スライム', 'MAT_FERDIA_CLEARSTREAM_DROP', 33.00],
        [1026, '獣', 'MAT_FERDIA_HEMOSTATIC_MOSS', 32.11],
        [1026, '人型', 'MAT_BR_WPN_HOLY_ANCIENT', 0.86],
        [1026, '巨人', 'MAT_BR_WPN_HOLY_ANCIENT', 0.68],
        [1026, '水棲', 'MAT_FERDIA_CLEARSTREAM_DROP', 33.00],
        [1027, 'スライム', 'MAT_FERDIA_BLUE_LIFE_LEAF', 32.11],
        [1027, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 33.00],
        [1027, '人型', 'MAT_BR_ARM_HEAVY_ANCIENT', 0.86],
        [1027, '巨人', 'MAT_BR_ARM_HEAVY_ANCIENT', 0.68],
        [1027, '機械', 'MAT_FERDIA_HEMOSTATIC_MOSS', 24.09],
        [1028, 'スライム', 'MAT_FERDIA_CLEARSTREAM_DROP', 33.00],
        [1028, '獣', 'MAT_FERDIA_DETOX_GALL', 20.07],
        [1028, '人型', 'MAT_BR_ARM_TRAVELER_ANCIENT', 0.86],
        [1028, '巨人', 'MAT_BR_ARM_TRAVELER_ANCIENT', 0.68],
        [1028, '飛行', 'MAT_FERDIA_CLEARSTREAM_DROP', 33.00],
        [1028, '飛行', 'MAT_FERDIA_DETOX_GALL', 12.04],
        [1029, 'スライム', 'MAT_FERDIA_HEMOSTATIC_MOSS', 32.11],
        [1029, '獣', 'MAT_FERDIA_BLUE_LIFE_LEAF', 24.09],
        [1029, '人型', 'MAT_BR_WPN_DARK_ANCIENT', 0.86],
        [1029, '巨人', 'MAT_BR_WPN_DARK_ANCIENT', 0.68],
        [1029, '巨人', 'MAT_FERDIA_LIFEROOT', 10.04],
        [1029, '悪魔', 'MAT_FERDIA_LIFEROOT', 12.04],
    ];

    public function test_new_install_has_exactly_the_approved_twenty_eight_drop_links(): void
    {
        $this->assertApprovedDrops();
    }

    private function assertApprovedDrops(): void
    {
        $actual = DB::table('material_drops as drop')
            ->join('enemies as enemy', 'enemy.id', '=', 'drop.enemy_id')
            ->join('materials as material', 'material.id', '=', 'drop.material_id')
            ->whereIn('enemy.area_id', FerdiaStoryMaterialDropSeeder::AREA_IDS)
            ->get(['enemy.area_id', 'enemy.type_name', 'material.material_code', 'drop.*']);

        $this->assertCount(28, $actual);
        foreach (self::APPROVED_DROPS as [$area, $type, $code, $rate]) {
            $matches = $actual->where('area_id', $area)->where('type_name', $type)->where('material_code', $code);
            $this->assertCount(1, $matches, "{$area}/{$type}/{$code}");
            $row = $matches->first();
            $this->assertEqualsWithDelta($rate, (float) $row->drop_rate, 0.0001);
            $this->assertTrue((bool) $row->is_active);
            $this->assertFalse((bool) $row->drop_first_clear_only);
            $this->assertNull($row->drop_timing);
        }
    }

    public function test_target_only_migration_preserves_existing_rates_rows_and_owned_materials(): void
    {
        DB::table('material_drops')->whereIn('enemy_id', $this->targetEnemyIds())->delete();
        foreach (['人型' => 0.86, '巨人' => 0.68] as $type => $rate) {
            $enemyId = DB::table('enemies')->where('area_id', 1001)->where('type_name', $type)->value('id');
            DB::table('material_drops')->where('enemy_id', $enemyId)->update(['drop_rate' => $rate]);
        }
        $character = $this->character();
        DB::table('character_materials')->insert([
            'character_id' => $character->id,
            'material_id' => DB::table('materials')->where('material_code', 'MAT_FERDIA_BLUE_LIFE_LEAF')->value('id'),
            'quantity' => 17,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $existingDrops = $this->dropSnapshot();
        $materials = DB::table('materials')->orderBy('id')->get()->toJson();
        $owned = DB::table('character_materials')->orderBy('id')->get()->toJson();
        $migration = $this->migration();

        $migration->up();
        $this->assertSame($existingDrops, $this->dropSnapshot(false));
        $this->assertSame($materials, DB::table('materials')->orderBy('id')->get()->toJson());
        $this->assertSame($owned, DB::table('character_materials')->orderBy('id')->get()->toJson());
        $this->assertApprovedDrops();

        $created = DB::table('material_drops')->whereIn('enemy_id', $this->targetEnemyIds())->orderBy('id')->pluck('created_at', 'id')->all();
        $this->travel(1)->days();
        $migration->up();
        $this->assertSame($existingDrops, $this->dropSnapshot(false));
        $this->assertSame($created, DB::table('material_drops')->whereIn('enemy_id', $this->targetEnemyIds())->orderBy('id')->pluck('created_at', 'id')->all());
        $this->assertSame($owned, DB::table('character_materials')->orderBy('id')->get()->toJson());
        $after = $this->dropSnapshot();
        $migration->down();
        $this->assertSame($after, $this->dropSnapshot());
        $this->assertSame($owned, DB::table('character_materials')->orderBy('id')->get()->toJson());
    }

    public function test_missing_material_stops_without_writing_any_drop_rows(): void
    {
        $materialId = DB::table('materials')->where('material_code', 'MAT_BR_WPN_DARK_ANCIENT')->value('id');
        DB::table('material_drops')->where('material_id', $materialId)->delete();
        DB::table('materials')->where('id', $materialId)->delete();
        $before = $this->dropSnapshot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('素材マスタが不足');
        try {
            $this->migration()->up();
        } finally {
            $this->assertSame($before, $this->dropSnapshot());
        }
    }

    public function test_missing_enemy_stops_without_partially_seeding_other_areas(): void
    {
        $enemyId = DB::table('enemies')->where('area_id', 1029)->where('type_name', '悪魔')->value('id');
        DB::table('material_drops')->where('enemy_id', $enemyId)->delete();
        DB::table('enemies')->where('id', $enemyId)->delete();
        $before = $this->dropSnapshot();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('敵マスタを確認');
        try {
            $this->migration()->up();
        } finally {
            $this->assertSame($before, $this->dropSnapshot());
        }
    }

    public function test_existing_ferdia_runtime_grants_and_records_each_new_drop(): void
    {
        // Force successful rolls only in this isolated test; master rates are checked above.
        DB::table('material_drops')->whereIn('enemy_id', $this->targetEnemyIds())->update(['drop_rate' => 100]);
        $character = $this->character();
        $total = 0;
        foreach (Enemy::query()->whereIn('area_id', FerdiaStoryMaterialDropSeeder::AREA_IDS)->where('is_boss', false)->get() as $enemy) {
            $expectedIds = DB::table('material_drops')->where('enemy_id', $enemy->id)->pluck('material_id')->all();
            $drops = app(DropService::class)->rollBattleDrops($character, $enemy, rollMonsterMark: false);
            $actualIds = array_column($drops['materials'], 'material_id');
            sort($expectedIds);
            sort($actualIds);
            $this->assertSame($expectedIds, $actualIds);
            $this->assertSame($drops['materials'], $drops['by_slot']['material']);
            foreach ($actualIds as $materialId) {
                $this->assertDatabaseHas('exploration_loot_logs', [
                    'character_id' => $character->id, 'area_id' => $enemy->area_id,
                    'material_id' => $materialId, 'quantity' => 1,
                ]);
            }
            $total += count($actualIds);
        }
        $this->assertSame(28, $total);
        $this->assertSame(28, (int) DB::table('character_materials')->where('character_id', $character->id)->sum('quantity'));
        $this->assertSame(28, (int) DB::table('exploration_loot_logs')->where('character_id', $character->id)->sum('quantity'));
    }

    public function test_treasure_can_select_normal_materials_in_all_five_areas(): void
    {
        $method = new ReflectionMethod(ExplorationService::class, 'treasureRewardMaterials');
        foreach (FerdiaStoryMaterialDropSeeder::AREA_IDS as $areaId) {
            $enemy = Enemy::query()->where('area_id', $areaId)->where('is_boss', false)->firstOrFail();
            $materials = $method->invoke(app(ExplorationService::class), $enemy->area, $enemy, 4);
            $this->assertCount(4, $materials);
            foreach ($materials as $material) {
                $this->assertStringStartsWith('MAT_FERDIA_', $material->material_code);
            }
        }
    }

    public function test_validator_detects_a_missing_drop_source_for_one_story_enemy(): void
    {
        $this->seed(AllDungeonsSeeder::class);
        $this->artisan('dungeon:validate')->assertSuccessful();
        $enemyId = DB::table('enemies')->where('area_id', 1028)->where('type_name', '飛行')->value('id');
        DB::table('material_drops')->where('enemy_id', $enemyId)->delete();

        $this->artisan('dungeon:validate')->expectsOutputToContain('ferdia material_drops enemy')->assertFailed();

        app(FerdiaStoryMaterialDropSeeder::class)->run();
        $this->artisan('dungeon:validate')->assertSuccessful();
    }

    public function test_weighted_expected_rewards_match_the_approved_proposal(): void
    {
        foreach ([1025 => 10.4087, 1026 => 11.7643, 1027 => 11.0337, 1028 => 11.1623, 1029 => 9.3130] as $areaId => $perFifty) {
            $rows = DB::table('material_drops as drop')
                ->join('enemies as enemy', 'enemy.id', '=', 'drop.enemy_id')
                ->join('materials as material', 'material.id', '=', 'drop.material_id')
                ->where('enemy.area_id', $areaId)
                ->get(['enemy.appearance_weight', 'drop.drop_rate', 'material.material_code']);
            $weight = DB::table('enemies')->where('area_id', $areaId)->where('is_boss', false)->sum('appearance_weight');
            $normal = $rows->filter(fn ($row): bool => str_starts_with($row->material_code, 'MAT_FERDIA_'));
            $ancient = $rows->reject(fn ($row): bool => str_starts_with($row->material_code, 'MAT_FERDIA_'));
            $expected = fn ($drops): float => $drops->sum(fn ($row): float => $row->appearance_weight * $row->drop_rate) / $weight / 100 * 50;
            $this->assertEqualsWithDelta($perFifty, $expected($normal), 0.0001);
            $this->assertEqualsWithDelta(0.1132, $expected($ancient), 0.0001);
        }
    }

    private function character(): Character
    {
        return Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '分岐素材検証']);
    }

    private function targetEnemyIds()
    {
        return DB::table('enemies')->whereIn('area_id', FerdiaStoryMaterialDropSeeder::AREA_IDS)->where('is_boss', false)->pluck('id');
    }

    private function dropSnapshot(bool $includeTargets = true): string
    {
        return DB::table('material_drops')
            ->when(! $includeTargets, fn ($query) => $query->whereNotIn('enemy_id', $this->targetEnemyIds()))
            ->orderBy('id')->get()->toJson();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_140000_add_ferdia_story_material_drops.php');
    }
}
