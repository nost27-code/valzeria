<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\User;
use App\Services\GoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FerdiaSurplusMaterialSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_drop_rates_and_sale_prices_receive_only_the_approved_adjustment(): void
    {
        $herb = Material::where('material_code', 'MAT_FERDIA_BLUE_LIFE_LEAF')->firstOrFail();
        $city = Material::where('material_code', 'WEV0023')->firstOrFail();
        $unlockKey = Material::where('material_code', 'WEV0034')->firstOrFail();
        $enemyId = DB::table('enemies')->where('area_id', 1001)->where('type_name', '人型')->where('is_boss', false)->value('id');
        $fragmentId = Material::where('material_code', 'MAT_BR_ARM_TRAVELER_ANCIENT')->value('id');
        $giantEnemyId = DB::table('enemies')->where('area_id', 1011)->where('type_name', '巨人')->where('is_boss', false)->value('id');
        $giantFragmentId = Material::where('material_code', 'MAT_BR_WPN_GALE_ANCIENT')->value('id');

        DB::table('materials')->whereIn('id', [$herb->id, $city->id])->update(['npc_sale_price' => 0]);
        DB::table('material_drops')->where('enemy_id', $enemyId)->where('material_id', $fragmentId)->update(['drop_rate' => 0.76]);
        DB::table('material_drops')->where('enemy_id', $giantEnemyId)->where('material_id', $giantFragmentId)->update(['drop_rate' => 0.60]);

        $migration = require database_path('migrations/2026_09_20_010000_adjust_ferdia_ancient_drops_and_surplus_sales.php');
        $migration->up();
        $migration->up();

        $this->assertSame(10, (int) $herb->fresh()->npc_sale_price);
        $this->assertSame(10, (int) $city->fresh()->npc_sale_price);
        $this->assertSame(0, (int) $unlockKey->fresh()->npc_sale_price);
        $this->assertEqualsWithDelta(0.86, (float) DB::table('material_drops')
            ->where('enemy_id', $enemyId)->where('material_id', $fragmentId)->value('drop_rate'), 0.001);
        $this->assertEqualsWithDelta(0.68, (float) DB::table('material_drops')
            ->where('enemy_id', $giantEnemyId)->where('material_id', $giantFragmentId)->value('drop_rate'), 0.001);

        $migration->down();

        $this->assertSame(0, (int) $herb->fresh()->npc_sale_price);
        $this->assertSame(0, (int) $city->fresh()->npc_sale_price);
        $this->assertEqualsWithDelta(0.76, (float) DB::table('material_drops')
            ->where('enemy_id', $enemyId)->where('material_id', $fragmentId)->value('drop_rate'), 0.001);
        $this->assertEqualsWithDelta(0.60, (float) DB::table('material_drops')
            ->where('enemy_id', $giantEnemyId)->where('material_id', $giantFragmentId)->value('drop_rate'), 0.001);
    }

    public function test_approved_surplus_materials_sell_for_their_listed_price(): void
    {
        foreach ([
            'MAT_FERDIA_BLUE_LIFE_LEAF' => 10,
            'MAT_FERDIA_CLEARSTREAM_DROP' => 10,
            'MAT_FERDIA_HEMOSTATIC_MOSS' => 10,
            'MAT_FERDIA_GUARDTREE_RESIN' => 20,
            'MAT_FERDIA_DETOX_GALL' => 20,
            'MAT_FERDIA_LIFEROOT' => 30,
            'WEV0023' => 10,
            'WEV0024' => 10,
            'WEV0025' => 10,
            'WEV0026' => 10,
            'WEV0027' => 10,
            'WEV0028' => 10,
            'WEV0031' => 10,
            'WEV0032' => 10,
        ] as $code => $price) {
            $this->assertSame($price, (int) Material::where('material_code', $code)->value('npc_sale_price'), $code);
        }

        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '余剰素材売却確認者']);
        $material = Material::where('material_code', 'WEV0023')->firstOrFail();
        $owned = CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 3]);
        $beforeGold = (int) $character->money;

        $result = app(GoldService::class)->sellMaterial($character, $owned, 2);

        $this->assertSame(10, $result['unit_price']);
        $this->assertSame(20, $result['amount']);
        $this->assertSame(1, (int) $owned->fresh()->quantity);
        $this->assertSame($beforeGold + 20, (int) $character->fresh()->money);
    }
}
