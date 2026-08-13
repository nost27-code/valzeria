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
