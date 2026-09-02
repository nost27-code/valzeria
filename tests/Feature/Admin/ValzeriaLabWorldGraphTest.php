<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ValzeriaLabWorld;
use App\Models\Area;
use App\Models\City;
use App\Models\Enemy;
use App\Models\EnemyDrop;
use App\Models\Item;
use App\Models\Material;
use App\Models\User;
use App\Services\Admin\ValzeriaLabWorldGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class ValzeriaLabWorldGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_contains_all_supported_master_types_and_only_labels_non_explicit_findings_as_candidates(): void
    {
        $graph = app(ValzeriaLabWorldGraphService::class)->build();

        $this->assertSame(
            array_keys(ValzeriaLabWorldGraphService::TYPE_LABELS),
            array_keys($graph['counts']['by_type']),
        );
        foreach (ValzeriaLabWorldGraphService::TYPE_LABELS as $type => $label) {
            $this->assertGreaterThan(0, $graph['counts']['by_type'][$type] ?? 0, $label.'のノードがありません。');
        }
        $this->assertNotEmpty($graph['edges']);
        $this->assertEmpty(collect($graph['edges'])->pluck('certainty')->diff(['confirmed', 'declared']));
        $this->assertEmpty(
            collect($graph['issues'])
                ->whereIn('type', ['no_acquisition_path', 'no_usage_path', 'unreachable_progression'])
                ->where('certainty', '!=', 'candidate'),
        );
    }

    public function test_explicit_relations_keep_their_direction_and_evidence(): void
    {
        [$area, $enemy, $item] = $this->connectedFixture();

        $service = app(ValzeriaLabWorldGraphService::class);
        $graph = $service->build();
        $areaKey = 'area:'.$area->id;
        $enemyKey = 'enemy:'.$enemy->id;
        $itemKey = 'equipment:'.$item->id;

        $areaEnemy = collect($graph['edges'])->first(fn (array $edge): bool => $edge['from'] === $areaKey
            && $edge['to'] === $enemyKey
            && $edge['relation'] === 'contains_enemy');
        $enemyItem = collect($graph['edges'])->first(fn (array $edge): bool => $edge['from'] === $enemyKey
            && $edge['to'] === $itemKey
            && $edge['relation'] === 'drops_item');

        $this->assertNotNull($areaEnemy);
        $this->assertSame('confirmed', $areaEnemy['certainty']);
        $this->assertSame('enemies.area_id', $areaEnemy['evidence']);
        $this->assertNotNull($enemyItem);
        $this->assertSame('confirmed', $enemyItem['certainty']);
        $this->assertSame('enemy_drops.enemy_id / item_id', $enemyItem['evidence']);

        $detail = $service->detail($graph, $enemyKey);
        $this->assertSame($enemy->name, $detail['node']['name']);
        $this->assertTrue(collect($detail['incoming'])->contains('from', $areaKey));
        $this->assertTrue(collect($detail['outgoing'])->contains('to', $itemKey));
    }

    public function test_ferdia_config_relations_resolve_by_declared_node_key(): void
    {
        [$origin] = $this->connectedFixture();
        $destination = Area::query()->create([
            'city_id' => $origin->city_id,
            'name' => 'Lab設定接続先',
            'slug' => 'lab-world-config-destination',
            'recommended_level_min' => 1,
            'recommended_level_max' => 5,
            'is_published' => true,
        ]);
        config(['ferdia_world_map' => [
            'entry_requirement' => ['area_id' => $origin->id],
            'nodes' => [
                ['key' => 'lab-origin', 'area_id' => $origin->id, 'city_id' => null, 'unlock' => ['type' => 'region_unlocked']],
                ['key' => 'lab-destination', 'area_id' => $destination->id, 'city_id' => null, 'unlock' => [
                    'type' => 'node_development',
                    'node_key' => 'lab-origin',
                    'required_point' => 5,
                ]],
            ],
            'routes' => [
                ['from' => 'lab-origin', 'to' => 'lab-destination', 'group' => 'main'],
            ],
        ]]);

        $graph = app(ValzeriaLabWorldGraphService::class)->build();
        $from = 'area:'.$origin->id;
        $to = 'area:'.$destination->id;

        $this->assertTrue(collect($graph['edges'])->contains(fn (array $edge): bool => $edge['from'] === $from
            && $edge['to'] === $to
            && $edge['relation'] === 'ferdia_unlock'
            && $edge['evidence'] === 'config/ferdia_world_map.php nodes.lab-destination.unlock'));
        $this->assertTrue(collect($graph['edges'])->contains(fn (array $edge): bool => $edge['from'] === $from
            && $edge['to'] === $to
            && $edge['relation'] === 'ferdia_route'
            && $edge['evidence'] === 'config/ferdia_world_map.php routes.0'));
        $this->assertEmpty(collect($graph['issues'])->filter(fn (array $issue): bool => str_contains(
            $issue['evidence'],
            'config/ferdia_world_map.php',
        )));
    }

    public function test_integrity_checks_separate_confirmed_missing_references_from_candidate_findings(): void
    {
        $material = Material::query()->create([
            'material_code' => 'LAB_WORLD_ORPHAN_MATERIAL',
            'name' => 'Lab経路未接続素材',
            'category' => '試験素材',
            'rarity' => 'N',
            'main_use' => null,
            'obtain_method' => null,
            'dungeon_id' => 9_999_999,
        ]);
        $city = City::query()->create([
            'name' => 'Lab孤立街',
            'description' => '世界グラフ試験用',
            'recommended_level_min' => 1,
            'recommended_level_max' => 1,
            'sort_order' => 99_999,
            'is_initial' => false,
        ]);
        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Lab孤立エリア',
            'slug' => 'lab-world-unreachable-area',
            'recommended_level_min' => 1,
            'recommended_level_max' => 1,
            'is_published' => true,
        ]);

        $graph = app(ValzeriaLabWorldGraphService::class)->build();
        $materialIssues = collect($graph['issues'])->where('node_key', 'material:'.$material->id);
        $areaIssues = collect($graph['issues'])->where('node_key', 'area:'.$area->id);

        $this->assertTrue($materialIssues->contains(fn (array $issue): bool => $issue['type'] === 'missing_reference'
            && $issue['certainty'] === 'confirmed'
            && $issue['evidence'] === 'materials.dungeon_id'));
        $this->assertTrue($materialIssues->contains(fn (array $issue): bool => $issue['type'] === 'no_acquisition_path'
            && $issue['certainty'] === 'candidate'));
        $this->assertTrue($materialIssues->contains(fn (array $issue): bool => $issue['type'] === 'no_usage_path'
            && $issue['certainty'] === 'candidate'));
        $this->assertTrue($areaIssues->contains(fn (array $issue): bool => $issue['type'] === 'unreachable_progression'
            && $issue['certainty'] === 'candidate'));

        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)
            ->test(ValzeriaLabWorld::class)
            ->call('selectNode', 'material:'.$material->id)
            ->assertSee('このマスタの整合性確認')
            ->assertSee('参照切れ / 明示参照の確認結果')
            ->assertDontSee('このマスタの確認候補');
    }

    public function test_build_executes_only_read_queries(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        app(ValzeriaLabWorldGraphService::class)->build();

        $this->assertNotEmpty($queries);
        $writes = collect($queries)->filter(
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create)\b/i', $sql) === 1,
        );
        $this->assertSame([], $writes->values()->all());

        $fullMasterReads = collect($queries)->filter(
            fn (string $sql): bool => preg_match('/select\s+\*\s+from\s+["`\[]?(cities|areas|enemies|items|materials|job_classes|titles|recipes)["`\]]?/i', $sql) === 1,
        );
        $this->assertSame([], $fullMasterReads->values()->all());
    }

    public function test_admin_can_search_filter_and_open_a_node_from_livewire(): void
    {
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        [$area, $enemy] = $this->connectedFixture();

        Livewire::actingAs($admin)
            ->test(ValzeriaLabWorld::class)
            ->set('type', 'enemy')
            ->set('search', $enemy->name)
            ->assertSee($enemy->name)
            ->assertDontSee($area->name)
            ->call('selectNode', 'enemy:'.$enemy->id)
            ->assertSet('selectedNodeKey', 'enemy:'.$enemy->id)
            ->assertSee('参照元')
            ->assertSee($area->name)
            ->assertSee('enemies.area_id')
            ->assertSee('Character、所持品、進行、報酬、ランキングは更新しません');
    }

    /** @return array{Area, Enemy, Item} */
    private function connectedFixture(): array
    {
        $city = City::query()->where('is_initial', true)->firstOrFail();
        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Lab接続エリア',
            'slug' => 'lab-world-connected-area-'.Area::query()->max('id'),
            'recommended_level_min' => 1,
            'recommended_level_max' => 5,
            'is_published' => true,
        ]);
        $enemy = Enemy::query()->create([
            'area_id' => $area->id,
            'name' => 'Lab接続敵',
            'level' => 1,
            'max_hp' => 10,
            'str' => 2,
            'def' => 1,
            'agi' => 1,
            'mag' => 1,
            'spr' => 1,
            'luk' => 1,
            'exp_reward' => 1,
            'job_exp_reward' => 1,
            'gold_reward' => 1,
            'appearance_weight' => 1,
            'is_boss' => false,
        ]);
        $item = Item::query()->create([
            'name' => 'Lab接続剣',
            'type' => 'weapon',
            'rarity' => 'N',
            'required_level' => 1,
            'is_shop_item' => false,
            'is_active' => true,
            'is_drop_enabled' => true,
        ]);
        EnemyDrop::query()->create([
            'enemy_id' => $enemy->id,
            'item_id' => $item->id,
            'drop_rate' => 10,
            'is_active' => true,
        ]);

        return [$area, $enemy, $item];
    }
}
