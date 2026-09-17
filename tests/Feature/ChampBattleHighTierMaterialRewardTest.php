<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\Material;
use App\Models\User;
use App\Services\ChampBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class ChampBattleHighTierMaterialRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_tier_accessory_evolution_materials_are_not_champ_rewards(): void
    {
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '高位素材確認者',
            'hp_base' => 100,
            'current_hp' => 100,
        ]);

        $service = app(ChampBattleService::class);
        $method = new ReflectionMethod(ChampBattleService::class, 'champRewardMaterialCandidates');

        foreach ([
            'A' => ['ACC0003', true],
            'S' => ['ACC0004', false],
            'SS' => ['MAT_BR_ACC_PRIMORDIAL_ORNAMENT_CRYSTAL', false],
            'SSS' => ['ACC0005', false],
        ] as $rank => [$materialCode, $shouldReward]) {
            $recipe = DB::table('accessory_evolution_recipes')
                ->where('from_rank', $rank)
                ->where('is_active', true)
                ->orderBy('recipe_id')
                ->first();

            $this->assertNotNull($recipe, "{$rank}からの進化レシピが必要です。");
            $item = Item::query()->where('external_item_id', $recipe->from_accessory_id)->firstOrFail();
            $equipped = CharacterItem::create([
                'character_id' => $character->id,
                'item_id' => $item->id,
                'is_equipped' => true,
                'is_stored' => false,
                'is_locked' => false,
            ]);

            $codes = array_column($method->invoke($service, $character), 'material_code');
            $this->assertContains('MAT_COMMON_MONSTER_CORE', $codes);
            if ($shouldReward) {
                $this->assertContains($materialCode, $codes, "{$rank}帯の通常素材は残します。");
            } else {
                $this->assertNotContains($materialCode, $codes, "{$rank}帯の高位素材は除外します。");
            }

            $equipped->delete();
        }
    }

    public function test_high_tier_materials_are_excluded_by_master_rank_regardless_of_name_or_type(): void
    {
        $service = app(ChampBattleService::class);
        $method = new ReflectionMethod(ChampBattleService::class, 'isExcludedChampRewardMaterial');

        $highTier = new Material([
            'material_code' => 'TEST_HIGH_TIER',
            'name' => '新しい高位素材',
            'material_type' => 'equipment_common',
            'rank_tier' => 4,
        ]);
        $ordinary = new Material([
            'material_code' => 'TEST_ORDINARY',
            'name' => '通常の進化素材',
            'material_type' => 'equipment_common',
            'rank_tier' => 3,
        ]);

        $this->assertTrue($method->invoke($service, $highTier));
        $this->assertFalse($method->invoke($service, $ordinary));
    }
}
