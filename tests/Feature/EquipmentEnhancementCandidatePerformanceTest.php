<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\EquipmentEnhancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentEnhancementCandidatePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_equipment_preloads_name_relations(): void
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '鍛冶屋軽量化試験',
        ]);
        $valmonMaster = ValmonMaster::create([
            'valmon_key' => 'blacksmith-performance',
            'name' => '軽量化試験モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);
        $item = Item::create([
            'name' => '軽量化試験剣',
            'type' => 'weapon',
            'weapon_rank' => 'G',
            'is_active' => true,
        ]);

        foreach (range(1, 25) as $index) {
            CharacterItem::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'enhance_level' => 10,
                'is_equipped' => false,
                'is_locked' => false,
            ]);
        }

        $service = app(EquipmentEnhancementService::class);
        $candidates = $service->candidatesForType($character, 'weapon', 'recommended', 20);

        $this->assertSame(['weapon' => 25, 'armor' => 0, 'accessory' => 0], $service->candidateCounts($character));
        $this->assertCount(20, $candidates);
        foreach ($candidates as $candidate) {
            $characterItem = $candidate['character_item'];
            $this->assertTrue($characterItem->relationLoaded('item'));
            $this->assertTrue($characterItem->relationLoaded('affixPrefix'));
            $this->assertTrue($characterItem->relationLoaded('affixSuffix'));
        }

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('blacksmith.index'))
            ->assertOk()
            ->assertViewHas('enhancementCandidates', fn (array $rows): bool => count($rows) === 20)
            ->assertViewHas('hasMoreEnhancementCandidates', true);
    }

    public function test_search_and_quality_state_filters_include_instances_beyond_first_page(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '強化絞り込み試験']);
        $targetItem = Item::create(['name' => '検索対象ABC剣', 'type' => 'weapon', 'weapon_rank' => 'G', 'is_active' => true]);
        $noiseItem = Item::create(['name' => 'その他の剣', 'type' => 'weapon', 'weapon_rank' => 'G', 'is_active' => true]);
        $target = CharacterItem::create(['character_id' => $character->id, 'item_id' => $targetItem->id, 'affix_quality' => 'excellent', 'is_equipped' => false, 'is_locked' => true]);
        foreach (range(1, 24) as $index) {
            CharacterItem::create(['character_id' => $character->id, 'item_id' => $noiseItem->id, 'is_equipped' => false, 'is_locked' => false]);
        }
        $service = app(EquipmentEnhancementService::class);
        $this->assertNotContains($target->id, array_column($service->candidatesForType($character, 'weapon', 'recommended', 20), 'character_item_id'));
        $filters = ['q' => '検索対象ＡＢＣ', 'quality' => 'excellent', 'status' => 'locked'];
        $rows = $service->candidatesForType($character, 'weapon', 'recommended', 20, $filters);
        $this->assertSame(1, $service->browseCandidateCount($character, 'weapon', $filters));
        $this->assertCount(1, $rows);
        $this->assertSame($target->id, $rows[0]['character_item']->id);
        $this->assertSame(0, $service->browseCandidateCount($character, 'weapon', array_merge($filters, ['status' => 'ready'])));
        $this->assertSame($target->id, $service->candidatesForType($character, 'weapon', 'quality_desc', 20)[0]['character_item']->id);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware()
            ->get(route('blacksmith.index', array_merge($filters, ['type' => 'weapon'])))
            ->assertOk()->assertViewHas('matchingEnhancementCount', 1)->assertViewHas('hasMoreEnhancementCandidates', false)
            ->assertSee('検索対象ABC剣')->assertSee('品質：逸品')->assertDontSee('その他の剣');
    }

    public function test_browse_counts_and_rows_share_one_name_search_and_keep_sort_and_limit(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '検索共通化試験']);
        $item = Item::create(['name' => '共通ABC剣', 'type' => 'weapon', 'weapon_rank' => 'G', 'is_active' => true]);
        foreach (range(1, 24) as $index) {
            CharacterItem::create(['character_id' => $character->id, 'item_id' => $item->id, 'enhance_level' => $index]);
        }
        $service = app(EquipmentEnhancementService::class);
        $filters = ['q' => '共通ＡＢＣ', 'status' => 'ready'];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $service->browseCandidates($character, 'weapon', 'enhance_desc', 20, $filters);
        $searchQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'select "character_items".*')
            && !str_contains($query['query'], '"character_items"."id" in'));
        DB::disableQueryLog();
        $this->assertCount(1, $searchQueries, 'Names must be scanned once, not once for count and again for display.');
        $this->assertSame(24, $result['count']);
        $this->assertCount(20, $result['candidates']);
        $this->assertSame(range(24, 5), array_map(fn ($row) => (int) $row['character_item']->enhance_level, $result['candidates']));
        $empty = $service->browseCandidates($character, 'weapon', 'recommended', 20, ['q' => '存在しない名前']);
        $this->assertSame(['count' => 0, 'candidates' => []], $empty);
        $this->assertSame(24, $service->browseCandidates($character, 'weapon', 'name_asc', 100)['count']);
    }

    public function test_repeated_material_resolution_uses_the_request_cache(): void
    {
        Material::create([
            'material_code' => 'TEST_ENHANCE_CACHE',
            'name' => '強化素材キャッシュ試験',
            'category' => '強化素材',
            'material_type' => 'enhancement',
            'rarity' => 'N',
        ]);
        $service = app(EquipmentEnhancementService::class);
        $resolveMaterial = new \ReflectionMethod($service, 'resolveMaterial');
        $resolveMaterial->setAccessible(true);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolveMaterial->invoke($service, 'TEST_ENHANCE_CACHE', '強化素材キャッシュ試験');
        $resolveMaterial->invoke($service, 'TEST_ENHANCE_CACHE', '強化素材キャッシュ試験');
        $materialSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "materials"'));
        DB::disableQueryLog();

        $this->assertCount(1, $materialSelects);
    }
}
