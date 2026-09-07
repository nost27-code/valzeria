<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\EquipmentAffixPrefix;
use App\Models\Item;
use App\Models\Material;
use App\Models\PublicLog;
use App\Models\User;
use App\Services\EquipmentEnhancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentEnhancementQualityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_excellent_public_log_uses_the_name_from_before_the_quality_upgrade(): void
    {
        config()->set('equipment_affix.forge_quality_upgrade_rates_bps.excellent', 10_000);
        config()->set('equipment_affix.forge_quality_upgrade_rates_bps.good', 0);

        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '強化テスト冒険者',
            'money' => 300,
            'explore_stamina' => 0,
        ]);
        $fragment = Material::query()->where('material_code', 'MAT_ENHANCE_FRAGMENT')->firstOrFail();
        CharacterMaterial::query()->create([
            'character_id' => $character->id,
            'material_id' => $fragment->id,
            'quantity' => 5,
        ]);
        $item = Item::query()->create([
            'name' => '強化品質テスト剣',
            'type' => 'weapon',
            'rarity' => 'G',
            'weapon_category' => 'sword',
            'weapon_rank' => 'G',
            'str_bonus' => 10,
            'is_active' => true,
        ]);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'affix_prefix_id' => $prefix->id,
            'affix_prefix_level' => 1,
            'affix_quality' => 'normal',
            'enhance_level' => 0,
            'is_equipped' => false,
            'acquired_from' => 'drop',
        ]);
        $characterItem->load(['item', 'affixPrefix', 'affixSuffix']);
        $beforeQuality = clone $characterItem;
        $beforeQuality->enhance_level = 1;
        $beforeQualityDisplayName = $beforeQuality->displayName();

        app(EquipmentEnhancementService::class)->enhance($character, $characterItem);

        $characterItem->refresh()->load(['item', 'affixPrefix', 'affixSuffix']);
        $this->assertSame('excellent', $characterItem->affix_quality);
        $this->assertSame(
            "【逸品】{$character->name}さんが鍛冶で「{$beforeQualityDisplayName}」を逸品に仕上げました！",
            PublicLog::query()->value('message'),
        );
    }
}
