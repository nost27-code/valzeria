<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\FavoriteWeaponService;
use Tests\TestCase;

class FavoriteWeaponServiceTest extends TestCase
{
    public function test_weapon_images_follow_the_category_rank_and_branch_catalog(): void
    {
        $service = new FavoriteWeaponService();

        $this->assertStringEndsWith('/images/weapon/weapon_001.webp', $service->toDisplayArray($this->weapon('sword', 'G', 'WPN_0001'))['image']);
        $this->assertStringEndsWith('/images/weapon/weapon_014.webp', $service->toDisplayArray($this->weapon('sword', 'SSS', 'WPN_BR_SWORD_DARK_SSS'))['image']);
        $this->assertStringEndsWith('/images/weapon/weapon_190.webp', $service->toDisplayArray($this->weapon('gun', 'EPIC', 'WPN_BR_GUN_WIND_EPIC'))['image']);
        $this->assertStringEndsWith('/images/weapon/weapon_064.webp', $service->toDisplayArray($this->weapon('axe', 'A', 'WPN_0040', '竜断ちの斧'))['image']);
        $this->assertStringEndsWith('/images/weapon/weapon_083.webp', $service->toDisplayArray($this->weapon('axe', 'A', 'WPN_0051', '星砕きの棍棒'))['image']);
    }

    public function test_display_separates_weapon_name_from_engraving_and_killer(): void
    {
        $weapon = $this->weapon('fist', 'A', 'WPN_0090', '武神の拳');
        $weapon->setRelation('affixPrefix', new \App\Models\EquipmentAffixPrefix(['name' => '生命の']));
        $weapon->setRelation('affixSuffix', new \App\Models\EquipmentAffixSuffix(['name' => '屍祓']));
        $weapon->affix_prefix_id = 1;
        $weapon->affix_suffix_id = 1;
        $weapon->affix_prefix_level = 2;
        $weapon->affix_suffix_level = 3;
        $weapon->affix_quality = 'excellent';
        $weapon->enhance_level = 20;

        $display = (new FavoriteWeaponService())->toDisplayArray($weapon);

        $this->assertSame('武神の拳', $display['name']);
        $this->assertSame('生命Ⅱ', $display['engraving']['label']);
        $this->assertSame(2, $display['engraving']['level']);
        $this->assertSame('#2563eb', $display['engraving']['color']);
        $this->assertSame('屍祓Ⅲ', $display['killer']['label']);
        $this->assertSame(3, $display['killer']['level']);
        $this->assertSame('#7c3aed', $display['killer']['color']);
        $this->assertSame('逸品', $display['quality']['label']);
        $this->assertSame('#c99a35', $display['quality']['border_color']);
        $this->assertStringContainsString('#e2bd67', $display['quality']['display_background']);
        $this->assertSame('#c2410c', $display['enhance_style']['color']);
        $this->assertSame('0.9rem', $display['enhance_style']['font_size']);
    }

    private function weapon(string $category, string $rank, string $externalId, string $name = '確認用武器'): CharacterItem
    {
        $item = new Item([
            'name' => $name,
            'type' => 'weapon',
            'weapon_category' => $category,
            'weapon_rank' => $rank,
            'external_item_id' => $externalId,
        ]);
        $weapon = new CharacterItem(['enhance_level' => 12]);
        $weapon->setRelation('item', $item);

        return $weapon;
    }
}
