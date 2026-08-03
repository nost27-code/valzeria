<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\EquipmentEvolutionService;
use App\Services\HomeActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeActionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipped_candidates_match_the_full_candidate_result_without_scanning_every_recipe(): void
    {
        $recipe = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($recipe);

        $sourceItem = Item::query()
            ->where('type', 'weapon')
            ->where('external_item_id', $recipe->from_weapon_id)
            ->where('is_active', true)
            ->first();
        $this->assertNotNull($sourceItem);

        $character = $this->createCharacterWithEquippedItem($sourceItem);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $actual = app(EquipmentEvolutionService::class)->equippedCandidates($character);
        $scopedQueryCount = count($queries);
        $expected = collect(app(EquipmentEvolutionService::class)->candidates($character))
            ->filter(fn (array $candidate): bool => (bool) ($candidate['has_equipped_source'] ?? false))
            ->values()
            ->all();

        $this->assertSame($this->candidateIdentity($expected), $this->candidateIdentity($actual));
        $this->assertLessThan(50, $scopedQueryCount);
    }

    public function test_home_uses_the_scoped_candidates_and_does_not_build_unused_actions_twice(): void
    {
        $homeActionSource = file_get_contents(app_path('Services/HomeActionService.php'));
        $mainScreenSource = file_get_contents(app_path('Livewire/MainScreen.php'));

        $this->assertIsString($homeActionSource);
        $this->assertIsString($mainScreenSource);
        $this->assertStringContainsString('->equippedCandidates($character)', $homeActionSource);
        $this->assertStringNotContainsString("'homeActions' =>", $mainScreenSource);
        $this->assertStringNotContainsString('homeActionService->getActions(', $mainScreenSource);
        $this->assertStringNotContainsString('home_actions_', $mainScreenSource);
    }

    public function test_equipped_candidates_support_weapon_armor_and_accessory_sources(): void
    {
        $sources = [
            ['type' => 'weapon', 'table' => 'weapon_evolution_recipes', 'column' => 'from_weapon_id'],
            ['type' => 'armor', 'table' => 'armor_evolution_recipes', 'column' => 'source_armor_id'],
            ['type' => 'accessory', 'table' => 'accessory_evolution_recipes', 'column' => 'from_accessory_id'],
        ];

        foreach ($sources as $source) {
            $recipe = DB::table($source['table'])
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
            $this->assertNotNull($recipe, $source['type'].'の有効な進化レシピがありません。');

            $item = Item::query()
                ->where('type', $source['type'])
                ->where('external_item_id', $recipe->{$source['column']})
                ->where('is_active', true)
                ->first();
            $this->assertNotNull($item, $source['type'].'の進化元装備がありません。');

            $character = $this->createCharacterWithEquippedItem($item, $source['type']);
            $candidates = app(EquipmentEvolutionService::class)->equippedCandidates($character);

            $this->assertNotEmpty($candidates, $source['type'].'の装備中進化候補を取得できません。');
            $this->assertNotEmpty(
                collect($candidates)->where('equipment_type', $source['type']),
                $source['type'].'の候補種別が一致しません。'
            );
        }
    }

    public function test_home_actions_stay_below_the_full_recipe_scan_query_volume(): void
    {
        $recipe = DB::table('weapon_evolution_recipes')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($recipe);

        $sourceItem = Item::query()
            ->where('type', 'weapon')
            ->where('external_item_id', $recipe->from_weapon_id)
            ->where('is_active', true)
            ->first();
        $this->assertNotNull($sourceItem);

        $character = $this->createCharacterWithEquippedItem($sourceItem);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(HomeActionService::class)->getActions($character, 5);

        $this->assertLessThan(200, count($queries));
        $repeatMaterialDropQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "material_drops" inner join "materials"'));

        $this->assertLessThanOrEqual(1, $repeatMaterialDropQueries->count());
    }

    public function test_repeat_material_drop_weights_are_loaded_once_for_multiple_enemies(): void
    {
        $enemyIds = DB::table('material_drops')
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->where('drop_rate', '>', 0)
            ->distinct()
            ->limit(2)
            ->pluck('enemy_id');
        $this->assertCount(2, $enemyIds);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $service = app(EquipmentEvolutionService::class);
        $method = new \ReflectionMethod($service, 'sameKindMaterialDropWeight');
        foreach ($enemyIds as $enemyId) {
            $method->invoke($service, (int) $enemyId, 'generic', 'early');
        }

        $repeatMaterialDropQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "material_drops" inner join "materials"'));

        $this->assertCount(1, $repeatMaterialDropQueries);
    }

    private function createCharacterWithEquippedItem(Item $item, string $slot = 'weapon'): Character
    {
        $user = User::factory()->create();
        $cityId = DB::table('cities')->value('id');
        $jobId = DB::table('job_classes')->value('id');
        $characterId = DB::table('characters')->insertGetId([
            'user_id' => $user->id,
            'name' => 'ホーム性能計測用冒険者',
            'current_city_id' => $cityId,
            'highest_city_id' => $cityId,
            'current_job_id' => $jobId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CharacterItem::query()->create([
            'character_id' => $characterId,
            'item_id' => $item->id,
            'is_equipped' => true,
            'is_stored' => false,
            'equipped_slot' => $slot,
        ]);

        return Character::query()->findOrFail($characterId);
    }

    /** @param array<int, array<string, mixed>> $candidates
     *  @return array<int, array<string, mixed>> */
    private function candidateIdentity(array $candidates): array
    {
        return collect($candidates)
            ->map(fn (array $candidate): array => [
                'equipment_type' => $candidate['equipment_type'],
                'recipe_id' => $candidate['recipe_id'],
                'from_equipment_id' => $candidate['from_equipment_id'],
                'to_equipment_id' => $candidate['to_equipment_id'],
                'has_equipped_source' => $candidate['has_equipped_source'],
                'can_evolve' => $candidate['can_evolve'],
                'unavailable_reason' => $candidate['unavailable_reason'],
            ])
            ->all();
    }
}
