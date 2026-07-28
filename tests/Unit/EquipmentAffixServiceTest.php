<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\Item;
use App\Services\EquipmentAffixService;
use Tests\TestCase;

class EquipmentAffixServiceTest extends TestCase
{
    public function test_forge_quality_roll_uses_the_configured_good_and_excellent_rates(): void
    {
        $service = app(EquipmentAffixService::class);

        $this->assertSame('excellent', $service->qualityAfterForgeRoll('normal', 1));
        $this->assertSame('good', $service->qualityAfterForgeRoll('normal', 11));
        $this->assertSame('normal', $service->qualityAfterForgeRoll('normal', 111));
    }

    public function test_forge_quality_roll_never_downgrades_and_can_promote_good_to_excellent(): void
    {
        $service = app(EquipmentAffixService::class);

        $this->assertSame('excellent', $service->qualityAfterForgeRoll('good', 1));
        $this->assertSame('good', $service->qualityAfterForgeRoll('good', 11));
        $this->assertSame('excellent', $service->qualityAfterForgeRoll('excellent', 10000));
    }

    public function test_quality_upgrade_excludes_plain_weapons_and_affixed_armor(): void
    {
        $service = app(EquipmentAffixService::class);

        $plainWeapon = new CharacterItem(['affix_quality' => 'normal']);
        $plainWeapon->setRelation('item', new Item(['type' => 'weapon']));
        $this->assertNull($service->upgradeQualityAfterWeaponForge($plainWeapon));

        $affixedArmor = new CharacterItem([
            'affix_prefix_id' => 10,
            'affix_quality' => 'normal',
        ]);
        $affixedArmor->setRelation('item', new Item(['type' => 'armor']));
        $affixedArmor->setRelation('affixPrefix', null);
        $affixedArmor->setRelation('affixSuffix', null);
        $this->assertNull($service->upgradeQualityAfterWeaponForge($affixedArmor));
    }
}
