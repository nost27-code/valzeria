<?php

namespace Tests\Unit;

use App\Models\Character;
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

    public function test_image_path_for_can_be_shared_by_equipment_cards(): void
    {
        $item = new Item([
            'name' => '月影の刃',
            'type' => 'weapon',
            'weapon_category' => 'dagger',
            'weapon_rank' => 'A',
            'external_item_id' => 'WPN_0026',
        ]);

        $this->assertSame('images/weapon/weapon_026.webp', (new FavoriteWeaponService())->imagePathFor($item));
    }

    public function test_image_path_for_returns_null_when_a_dedicated_image_cannot_be_selected(): void
    {
        $item = new Item([
            'name' => '未対応武器',
            'type' => 'weapon',
            'weapon_category' => 'unknown',
            'weapon_rank' => 'A',
        ]);

        $this->assertNull((new FavoriteWeaponService())->imagePathFor($item));
    }

    public function test_display_background_matches_the_profile_quality_presentation(): void
    {
        $service = new FavoriteWeaponService();
        $item = new Item(['type' => 'weapon', 'weapon_rank' => 'A']);

        $this->assertNull($service->displayBackgroundFor($item, 'normal'));
        $this->assertStringContainsString('#c8d9ec', $service->displayBackgroundFor($item, 'good'));
        $this->assertStringContainsString('#e2bd67', $service->displayBackgroundFor($item, 'excellent'));
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

    public function test_special_weapon_uses_compact_rank_and_elfia_background(): void
    {
        $display = (new FavoriteWeaponService())->toDisplayArray(
            $this->weapon('staff', 'SPECIAL', 'TOWER_STAR_TREE_F90_STAFF', '星樹の聖杖')
        );

        $this->assertSame('SP', $display['rank']);
        $this->assertSame('#13795b', $display['rank_color']);
        $this->assertTrue($display['is_special']);
        $this->assertStringContainsString('#d9efad', $display['display_background']);
        $this->assertStringContainsString('#174b38', $display['display_background']);
    }

    public function test_stale_saved_weapon_ids_are_excluded_and_cleaned_on_save(): void
    {
        $character = new Character();
        $character->profile_favorite_weapon_ids = [101, 102];
        $service = $this->serviceWithAvailableWeaponIds([102]);

        $this->assertSame([102], $service->selectedIds($character));

        $service->saveSelection($character, [101, 102]);

        $this->assertSame([102], $character->profile_favorite_weapon_ids);
    }

    public function test_new_unowned_weapon_id_is_still_rejected(): void
    {
        $character = new Character();
        $character->profile_favorite_weapon_ids = [102];
        $service = $this->serviceWithAvailableWeaponIds([102]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('所持していない武器はお気に入りに設定できません。');

        $service->saveSelection($character, [102, 999]);
    }

    private function serviceWithAvailableWeaponIds(array $ids): FavoriteWeaponService
    {
        return new class($ids) extends FavoriteWeaponService
        {
            public function __construct(private readonly array $ids)
            {
            }

            protected function ownedWeaponIds(Character $character): array
            {
                return $this->ids;
            }
        };
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
