<?php

namespace Tests\Feature;

use App\Models\Enemy;
use Database\Seeders\AllDungeonsSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\EnemySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnemySpeciesAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_previously_standard_enemies_receive_supported_species_from_seeder(): void
    {
        $labels = config('enemy_species.labels');
        $assignments = config('enemy_species.assignments');

        $this->assertCount(12, $labels);
        $this->assertCount(108, $assignments);
        $this->assertSame(
            [],
            array_values(array_diff(array_column($assignments, 'species_key'), array_keys($labels)))
        );
        $this->assertCount(108, array_unique(array_column($assignments, 'name')));

        $this->seed(CitySeeder::class);
        $this->seed(AllDungeonsSeeder::class);
        $this->seed(EnemySeeder::class);

        foreach ($assignments as $enemyId => $assignment) {
            $enemy = Enemy::query()->findOrFail($enemyId);

            $this->assertSame($assignment['name'], $enemy->name, "enemy name mismatch: {$enemyId}");
            $this->assertSame($assignment['species_key'], $enemy->species_key, "species mismatch: {$enemyId}");
        }

        $this->assertSame('spirit', Enemy::query()->findOrFail(103)->species_key);
        $this->assertSame('flying', Enemy::query()->findOrFail(105)->species_key);
        $this->assertSame('spirit', Enemy::query()->findOrFail(107)->species_key);
    }

    public function test_data_migration_updates_only_matching_standard_enemy_rows_and_can_be_rolled_back(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(AllDungeonsSeeder::class);
        $this->seed(EnemySeeder::class);

        $assignments = config('enemy_species.assignments');
        $enemyIds = array_keys($assignments);
        $familyKeysBefore = Enemy::query()
            ->whereIn('id', $enemyIds)
            ->pluck('family_key', 'id')
            ->all();

        DB::table('enemies')->whereIn('id', $enemyIds)->update(['species_key' => 'standard']);
        DB::table('enemies')->where('id', 105)->update([
            'name' => '照合対象外の敵名',
            'species_key' => 'standard',
        ]);

        $migration = require database_path('migrations/2026_07_31_120000_assign_standard_enemies_to_species.php');
        $migration->up();

        $this->assertDatabaseHas('enemies', [
            'id' => 103,
            'name' => '中層世界樹精',
            'family_key' => $familyKeysBefore[103],
            'species_key' => 'spirit',
        ]);
        $this->assertDatabaseHas('enemies', [
            'id' => 105,
            'name' => '照合対象外の敵名',
            'species_key' => 'standard',
        ]);
        $this->assertSame(
            $familyKeysBefore,
            Enemy::query()->whereIn('id', $enemyIds)->pluck('family_key', 'id')->all()
        );

        $migration->down();

        $this->assertDatabaseHas('enemies', [
            'id' => 103,
            'family_key' => $familyKeysBefore[103],
            'species_key' => 'standard',
        ]);
    }

    public function test_battle_result_uses_species_key_and_never_labels_standard_as_a_species(): void
    {
        $template = file_get_contents(resource_path('views/battle/result.blade.php'));

        $this->assertStringContainsString("config('enemy_species.labels', [])", $template);
        $this->assertStringContainsString("species_key ?? ''", $template);
        $this->assertStringContainsString("'種族不明'", $template);
        $this->assertStringNotContainsString("'standard' => '通常'", $template);
    }
}
