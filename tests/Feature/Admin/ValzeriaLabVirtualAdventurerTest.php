<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ValzeriaLabAdventurer;
use App\Models\Area;
use App\Models\City;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Models\User;
use App\Services\Admin\ValzeriaLabVirtualAdventurerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class ValzeriaLabVirtualAdventurerTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_profiles_execute_with_a_bounded_timeline_and_explicit_model_boundaries(): void
    {
        $this->ensureSimulationWorld();
        $service = app(ValzeriaLabVirtualAdventurerService::class);

        foreach (array_keys(ValzeriaLabVirtualAdventurerService::PROFILES) as $profile) {
            $result = $service->run($profile, 30, 20_260_902);

            $this->assertSame($profile, $result['profile']['key']);
            $this->assertGreaterThan(0, $result['executed_actions']);
            $this->assertLessThanOrEqual(30, $result['executed_actions']);
            $this->assertCount($result['executed_actions'], $result['timeline']);
            $this->assertFalse($result['persistence']);
            $this->assertNotEmpty($result['stop_reason_label']);
            $this->assertContains('戦闘: ValzeriaLabReplayService経由の現行BattleService', $result['boundaries']['exact']);
            $this->assertStringContainsString('行動選択', $result['boundaries']['simplified'][0]);
            $this->assertSame(range(1, $result['executed_actions']), array_column($result['timeline'], 'step'));
            $this->assertJson(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function test_same_profile_seed_and_limit_reproduce_the_same_timeline(): void
    {
        $this->ensureSimulationWorld();
        $service = app(ValzeriaLabVirtualAdventurerService::class);

        $first = $service->run('efficiency', 30, 771_204);
        $second = $service->run('efficiency', 30, 771_204);

        $this->assertSame($first, $second);
        $this->assertSame($first['timeline'], $second['timeline']);
        $this->assertSame($first['final'], $second['final']);
    }

    public function test_representative_profiles_cover_town_exploration_battle_inn_equipment_job_and_boss_decisions(): void
    {
        $this->ensureSimulationWorld();
        $service = app(ValzeriaLabVirtualAdventurerService::class);
        $beginner = $service->run('beginner', 30, 20_260_902);
        $efficiency = $service->run('efficiency', 30, 20_260_902);
        $types = collect($beginner['timeline'])
            ->merge($efficiency['timeline'])
            ->pluck('type')
            ->unique();

        foreach (['town', 'explore', 'battle', 'inn', 'equipment', 'job', 'boss'] as $type) {
            $this->assertContains($type, $types, "{$type} の代表行動がありません。");
        }
        $this->assertTrue(collect($efficiency['timeline'])->where('type', 'battle')->contains(
            fn (array $entry): bool => ($entry['battle']['battle_type'] ?? null) === 'boss',
        ));
        $this->assertGreaterThan($efficiency['initial']['level'], $efficiency['final']['level']);
    }

    public function test_simulation_executes_only_read_queries_and_preserves_player_owned_tables(): void
    {
        $this->ensureSimulationWorld();
        $before = $this->persistentTableCounts();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $result = app(ValzeriaLabVirtualAdventurerService::class)->run('collector', 30, 912_004);

        $this->assertFalse($result['persistence']);
        $this->assertSame($before, $this->persistentTableCounts());
        $writes = collect($queries)->filter(
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create)\b/i', $sql) === 1,
        );
        $this->assertSame([], $writes->values()->all());
    }

    public function test_action_limit_validation_rejects_values_outside_one_to_one_hundred(): void
    {
        $service = app(ValzeriaLabVirtualAdventurerService::class);

        foreach ([0, 101] as $limit) {
            try {
                $service->run('beginner', $limit, 1);
                $this->fail('Invalid action limit was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('行動数は1〜100にしてください。', $exception->getMessage());
            }
        }
    }

    public function test_admin_can_run_a_representative_virtual_scenario_from_livewire(): void
    {
        $this->ensureSimulationWorld();
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(ValzeriaLabAdventurer::class)
            ->set('profile', 'efficiency')
            ->set('actionLimit', 30)
            ->set('seed', 42_424)
            ->call('runSimulation')
            ->assertHasNoErrors()
            ->assertSet('result.profile.key', 'efficiency')
            ->assertSet('result.persistence', false)
            ->assertSee('行動タイムライン')
            ->assertSee('現行実装を再利用')
            ->assertSee('Lab簡略モデル')
            ->assertSee('Character・所持品・Gold・進行・戦績・ログは保存しません');
    }

    private function ensureSimulationWorld(): void
    {
        $city = City::query()->where('is_initial', true)->firstOrFail();
        $firstArea = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Lab仮想草原',
            'slug' => 'lab-virtual-field',
            'recommended_level_min' => 1,
            'recommended_level_max' => 2,
            'unlock_order' => 90_001,
            'sort_order' => 90_001,
            'clear_condition_type' => 'boss_defeated',
            'is_published' => true,
        ]);
        $secondArea = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Lab仮想洞窟',
            'slug' => 'lab-virtual-cave',
            'recommended_level_min' => 1,
            'recommended_level_max' => 2,
            'unlock_order' => 90_002,
            'sort_order' => 90_002,
            'unlock_required_area_id' => $firstArea->id,
            'clear_condition_type' => 'boss_defeated',
            'is_published' => true,
        ]);

        $firstNormal = $this->createEnemy($firstArea, 'Lab仮想スライム', false, 60);
        $this->createEnemy($firstArea, 'Lab仮想草原主', true, 80);
        $this->createEnemy($secondArea, 'Lab仮想コウモリ', false, 70);
        $this->createEnemy($secondArea, 'Lab仮想洞窟主', true, 90);

        $weapon = Item::query()->create([
            'name' => 'Lab仮想木剣',
            'type' => 'weapon',
            'rarity' => 'N',
            'price' => 100,
            'str_bonus' => 80,
            'required_level' => 1,
            'is_shop_item' => true,
            'is_active' => true,
            'is_drop_enabled' => true,
            'unlock_city_id' => $city->id,
        ]);
        Item::query()->create([
            'name' => 'Lab仮想革鎧',
            'type' => 'armor',
            'rarity' => 'N',
            'price' => 100,
            'hp_bonus' => 80,
            'def_bonus' => 64,
            'spr_bonus' => 32,
            'required_level' => 1,
            'is_shop_item' => true,
            'is_active' => true,
            'unlock_city_id' => $city->id,
        ]);
        EnemyDrop::query()->create([
            'enemy_id' => $firstNormal->id,
            'item_id' => $weapon->id,
            'drop_rate' => 10,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'material_code' => 'LAB_VIRTUAL_MATERIAL',
            'name' => 'Lab仮想素材',
            'category' => '試験素材',
            'rarity' => 'N',
        ]);
        MaterialDrop::query()->create([
            'enemy_id' => $firstNormal->id,
            'material_id' => $material->id,
            'drop_rate' => 10,
            'is_active' => true,
        ]);
    }

    private function createEnemy(Area $area, string $name, bool $boss, int $exp): Enemy
    {
        return Enemy::query()->create([
            'area_id' => $area->id,
            'name' => $name,
            'level' => $boss ? 2 : 1,
            'max_hp' => $boss ? 35 : 20,
            'str' => $boss ? 10 : 8,
            'def' => 2,
            'agi' => 3,
            'mag' => 2,
            'spr' => 2,
            'luk' => 1,
            'exp_reward' => $exp,
            'job_exp_reward' => 3,
            'gold_reward' => 10,
            'appearance_weight' => 10,
            'sort_order' => $boss ? 20 : 10,
            'is_boss' => $boss,
        ]);
    }

    /** @return array<string, int> */
    private function persistentTableCounts(): array
    {
        return collect([
            'characters',
            'character_items',
            'character_materials',
            'character_area_progresses',
            'battle_logs',
            'champ_battle_logs',
            'arena_logs',
            'arena_rankings',
            'gold_transactions',
            'job_art_v2_battle_telemetry_logs',
            'public_logs',
        ])
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}
