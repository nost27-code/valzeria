<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\City;
use App\Models\Item;
use App\Models\Material;
use App\Models\RegionDepthDungeon;
use App\Models\User;
use App\Services\AdventureSupportService;
use App\Services\ApothecaryService;
use App\Services\RegionDepthDungeonService;
use App\Services\StorageCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AdditionalFacilityBankPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_storage_gold_expansion_uses_bank_only_after_confirmation(): void
    {
        $character = $this->character('銀行倉庫拡張試験', 100, 100_000);
        $character->refresh();
        $storedBefore = (int) $character->material_storage_limit;
        $displayBefore = app(StorageCapacityService::class)->materialLimit($character);
        $service = app(AdventureSupportService::class);

        try {
            $service->purchase($character, 'material_storage_gold_expand');
            $this->fail('銀行利用の確認なしで倉庫が拡張されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertSame($storedBefore, (int) $character->fresh()->material_storage_limit);
        $result = $service->purchase($character, 'material_storage_gold_expand', true);

        $this->assertTrue($result['success']);
        $this->assertSame(100, (int) $result['payment']['hand_gold_used']);
        $this->assertSame(99_900, (int) $result['payment']['bank_gold_used']);
        $this->assertSame($displayBefore + 50, app(StorageCapacityService::class)->materialLimit($character->fresh()));
        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertSame(100, (int) $character->fresh()->bank_gold);
    }

    public function test_apothecary_fee_uses_bank_only_after_confirmation_and_preserves_materials(): void
    {
        $character = $this->character('銀行調合試験', 10, 100);
        $fragment = $this->material('BANK_APOTHECARY_FRAGMENT', '魔物の欠片', 100);
        $fang = $this->material('BANK_APOTHECARY_FANG', '獣牙', 100);
        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $fragment->id, 'quantity' => 2]);
        CharacterMaterial::query()->create(['character_id' => $character->id, 'material_id' => $fang->id, 'quantity' => 3]);
        Item::query()->firstOrCreate(
            ['name' => '誘魔香〈獣〉', 'type' => 'consumable'],
            ['rarity' => 'N', 'is_active' => true],
        );
        $service = app(ApothecaryService::class);

        try {
            $service->craft($character, 'lure_beast', 1);
            $this->fail('銀行利用の確認なしで調合されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertSame(2, (int) CharacterMaterial::query()->where('character_id', $character->id)->where('material_id', $fragment->id)->value('quantity'));
        $this->assertSame(3, (int) CharacterMaterial::query()->where('character_id', $character->id)->where('material_id', $fang->id)->value('quantity'));

        $result = $service->craft($character, 'lure_beast', 1, true);

        $this->assertSame(40, (int) $result['fee']);
        $this->assertSame(10, (int) $result['payment']['hand_gold_used']);
        $this->assertSame(30, (int) $result['payment']['bank_gold_used']);
        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertSame(70, (int) $character->fresh()->bank_gold);
    }

    public function test_region_depth_entry_fee_uses_bank_only_after_confirmation(): void
    {
        $city = City::query()->findOrFail(1);
        $area = Area::query()->create([
            'name' => '銀行入場料試験地',
            'slug' => 'bank-region-depth-entry-test',
            'city_id' => $city->id,
            'recommended_level_min' => 1,
            'recommended_level_max' => 10,
        ]);
        RegionDepthDungeon::query()->create([
            'key' => 'bank-region-depth-entry-test',
            'name' => '銀行入場料試験坑',
            'city_id' => $city->id,
            'area_id' => $area->id,
            'source_area_id' => $area->id,
            'is_enabled' => true,
            'entry_gold' => 500,
            'entry_materials' => [],
            'base_stat_multipliers' => [],
        ]);
        $character = $this->character('銀行追加ダンジョン試験', 100, 1000, $city->id);
        $service = app(RegionDepthDungeonService::class);

        try {
            $service->enter($character, 'bank-region-depth-entry-test');
            $this->fail('銀行利用の確認なしで入場されました。');
        } catch (RuntimeException $e) {
            $this->assertSame('銀行預金を使う確認が必要です。', $e->getMessage());
        }

        $this->assertDatabaseMissing('character_region_dungeon_runs', ['character_id' => $character->id]);
        $run = $service->enter($character, 'bank-region-depth-entry-test', true);

        $this->assertSame('active', $run->status);
        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertSame(600, (int) $character->fresh()->bank_gold);
    }

    private function character(string $name, int $money, int $bankGold, int $highestCityId = 1): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'money' => $money,
            'bank_gold' => $bankGold,
            'highest_city_id' => $highestCityId,
            'explore_stamina' => 0,
        ]);
    }

    private function material(string $code, string $name, int $salePrice): Material
    {
        $material = Material::query()->firstOrNew(['name' => $name]);
        $material->forceFill([
            'material_code' => $material->material_code ?: $code,
            'category' => $material->category ?: 'テスト',
            'rarity' => $material->rarity ?: 'N',
            'npc_sale_price' => $salePrice,
            'is_tradable' => false,
        ])->save();

        return $material;
    }
}
