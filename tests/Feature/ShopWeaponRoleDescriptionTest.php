<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopWeaponRoleDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_descriptions_are_defined_for_each_shared_weapon_family(): void
    {
        $masterPath = database_path('data/shop_equipment_master.json');
        $rows = json_decode((string) file_get_contents($masterPath), true, flags: JSON_THROW_ON_ERROR);
        $expectedPhrases = [
            '斧' => '攻撃に特化した重い一撃型',
            '棍棒' => '攻撃と防御を両立し、敏捷の低下も抑えた堅守型',
            '銃' => '攻撃に加えて敏捷と運も伸ばす、先手を取りやすい銃器',
            '機工銃' => '攻撃と魔力を同時に伸ばす、物理・魔法の両方に対応した機工武器',
        ];

        $targetRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['sub_type'] ?? null, array_keys($expectedPhrases), true),
        ));

        $this->assertCount(24, $targetRows);

        foreach ($expectedPhrases as $subType => $phrase) {
            $familyRows = array_values(array_filter(
                $targetRows,
                static fn (array $row): bool => ($row['sub_type'] ?? null) === $subType,
            ));

            $this->assertCount(6, $familyRows, "{$subType}の店売り武器が6件ではありません。");

            foreach ($familyRows as $row) {
                $this->assertStringContainsString($phrase, (string) $row['description']);
            }
        }
    }

    public function test_role_descriptions_are_reflected_in_the_items_table(): void
    {
        $this->assertStringContainsString(
            '攻撃に特化した重い一撃型',
            (string) Item::query()->where('external_item_id', 'SHOP_WPN_SAN_AXE')->value('description'),
        );
        $this->assertStringContainsString(
            '攻撃と防御を両立し、敏捷の低下も抑えた堅守型',
            (string) Item::query()->where('external_item_id', 'SHOP_WPN_SAN_CLUB')->value('description'),
        );
        $this->assertStringContainsString(
            '攻撃に加えて敏捷と運も伸ばす、先手を取りやすい銃器',
            (string) Item::query()->where('external_item_id', 'SHOP_WPN_SAN_GUN')->value('description'),
        );
        $this->assertStringContainsString(
            '攻撃と魔力を同時に伸ばす、物理・魔法の両方に対応した機工武器',
            (string) Item::query()->where('external_item_id', 'SHOP_WPN_SAN_MECHGUN')->value('description'),
        );
    }
}
