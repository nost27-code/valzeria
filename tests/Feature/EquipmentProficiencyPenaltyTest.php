<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\EquipmentAutoUnequipService;
use App\Services\EquipmentPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentProficiencyPenaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'equipment_proficiency.non_proficient.enabled' => false,
            'equipment_proficiency.non_proficient.effect_rate' => 0.65,
        ]);
    }

    public function test_default_config_keeps_the_penalty_system_disabled(): void
    {
        $config = require base_path('config/equipment_proficiency.php');

        $this->assertFalse($config['non_proficient']['enabled']);
        $this->assertSame(0.65, $config['non_proficient']['effect_rate']);
        $this->assertSame(0.85, $config['non_proficient']['weapon_effect_rates']['fist']);
        $this->assertSame(0.85, $config['non_proficient']['weapon_effect_rates']['katana']);
        $this->assertSame(0.75, $config['non_proficient']['weapon_effect_rates']['bow']);
        $this->assertSame(0.70, $config['non_proficient']['weapon_effect_rates']['spear']);
        $this->assertSame(0.65, $config['non_proficient']['weapon_effect_rates']['sword']);
    }

    public function test_off_mode_preserves_strict_restrictions_and_full_equipment_effects(): void
    {
        [$character, $weapon, $armor] = $this->nonProficientLoadout();
        $permissionService = app(EquipmentPermissionService::class);

        $this->assertFalse($permissionService->canEquip($character, $weapon->item));
        $this->assertFalse($permissionService->canEquip($character, $armor->item));
        $this->assertSame(1.0, $permissionService->performanceRate($character, $weapon->item));
        $this->assertSame(
            ['hp', 'mp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'],
            array_keys(app(CharacterStatusService::class)->equipmentStatsFor($character, $weapon)),
        );
        $this->assertSame(['str' => 1120, 'mag' => 0], app(CharacterStatusService::class)->weaponOffenseFor($character, $weapon));
        $this->assertSame(['def' => 1120, 'spr' => 0], app(CharacterStatusService::class)->armorDefenseFor($character, $armor));

        app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);

        $this->assertFalse((bool) $weapon->fresh()->is_equipped);
        $this->assertFalse((bool) $armor->fresh()->is_equipped);
    }

    public function test_on_mode_uses_the_weapon_category_rate_and_keeps_armor_at_the_shared_rate(): void
    {
        [$character, $weapon, $armor] = $this->nonProficientLoadout();
        config(['equipment_proficiency.non_proficient.enabled' => true]);

        $permissionService = app(EquipmentPermissionService::class);
        $statusService = app(CharacterStatusService::class);

        $this->assertTrue($permissionService->canEquip($character, $weapon->item));
        $this->assertTrue($permissionService->canEquip($character, $armor->item));
        $this->assertSame(0.85, $permissionService->performanceRate($character, $weapon->item));
        $this->assertSame(0.65, $permissionService->performanceRate($character, $armor->item));
        $this->assertTrue($permissionService->hasPerformancePenalty($character, $weapon->item));
        $this->assertSame(['str' => 952, 'mag' => 0], $statusService->weaponOffenseFor($character, $weapon));
        $this->assertSame(['def' => 728, 'spr' => 0], $statusService->armorDefenseFor($character, $armor));
        $this->assertSame(
            952,
            $statusService->equipmentStatsForItem(
                $character,
                $weapon->item,
                5,
                ['str' => 200],
            )['str'],
        );
        $this->assertEqualsWithDelta(0.17, $permissionService->effectiveKillerDamageRate($character, $weapon), 0.00001);
        $this->assertEqualsWithDelta(0.13, $permissionService->effectiveSpeciesDamageReductionRate($character, $armor), 0.00001);

        app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);

        $this->assertTrue((bool) $weapon->fresh()->is_equipped);
        $this->assertTrue((bool) $armor->fresh()->is_equipped);
    }

    public function test_native_weapon_category_labels_are_returned_in_display_order(): void
    {
        $job = JobClass::query()->create([
            'key' => 'merchant_display_test',
            'name' => '商人',
            'rank' => 'normal',
            'is_active' => true,
        ]);
        DB::table('job_weapon_permissions')->insert([
            [
                'job_id' => $job->id,
                'weapon_category' => 'staff',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'job_id' => $job->id,
                'weapon_category' => 'dagger',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'job_id' => $job->id,
                'weapon_category' => 'gun',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(
            ['短剣', '杖', '銃'],
            app(EquipmentPermissionService::class)->nativeWeaponCategoryLabels((int) $job->id),
        );
    }

    public function test_shop_swap_preview_matches_the_actual_final_stats_after_equipping(): void
    {
        [$character, $currentWeapon, $currentArmor] = $this->nonProficientLoadout();
        config(['equipment_proficiency.non_proficient.enabled' => true]);
        $statusService = app(CharacterStatusService::class);
        $finalKeys = [
            'hp' => 'max_hp',
            'mp' => 'max_mp',
            'str' => 'str',
            'def' => 'def',
            'agi' => 'agi',
            'mag' => 'mag',
            'spr' => 'spr',
            'luk' => 'luk',
        ];

        $candidateWeaponItem = Item::query()->create([
            'name' => '交換候補の刀',
            'type' => 'weapon',
            'weapon_category' => 'katana',
            'weapon_rank' => 'EPIC',
            'hp_bonus' => 40,
            'str_bonus' => 600,
            'def_bonus' => 120,
            'agi_bonus' => -30,
            'mag_bonus' => 200,
            'spr_bonus' => 80,
            'is_active' => true,
        ]);
        $weaponPreview = $statusService->equipmentSwapPreviewForItem(
            $character,
            $candidateWeaponItem,
            $currentWeapon,
        );
        $currentWeapon->update(['is_equipped' => false, 'equipped_slot' => null]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $candidateWeaponItem->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
        ]);
        CharacterStatusService::clearRequestCache($character->id);
        $actualAfterWeapon = $statusService->getFinalStats($character);

        foreach ($finalKeys as $key => $finalKey) {
            $this->assertSame($actualAfterWeapon[$finalKey], $weaponPreview['after_stats'][$key], $key);
        }

        $candidateArmorItem = Item::query()->create([
            'name' => '交換候補の重鎧',
            'type' => 'armor',
            'armor_category' => 'heavy_armor',
            'armor_rank' => 'EPIC',
            'mp_bonus' => 50,
            'str_bonus' => 90,
            'def_bonus' => 700,
            'agi_bonus' => -20,
            'mag_bonus' => 70,
            'spr_bonus' => 300,
            'is_active' => true,
        ]);
        $armorPreview = $statusService->equipmentSwapPreviewForItem(
            $character,
            $candidateArmorItem,
            $currentArmor,
        );
        $currentArmor->update(['is_equipped' => false, 'equipped_slot' => null]);
        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $candidateArmorItem->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
        ]);
        CharacterStatusService::clearRequestCache($character->id);
        $actualAfterArmor = $statusService->getFinalStats($character);

        foreach ($finalKeys as $key => $finalKey) {
            $this->assertSame($actualAfterArmor[$finalKey], $armorPreview['after_stats'][$key], $key);
        }
    }

    /** @return array{Character, CharacterItem, CharacterItem} */
    private function nonProficientLoadout(): array
    {
        $job = JobClass::query()->create([
            'key' => 'test_proficiency_job',
            'name' => '適性試験職',
            'rank' => 'G',
            'is_active' => true,
        ]);
        DB::table('job_weapon_permissions')->insert([
            'job_id' => $job->id,
            'weapon_category' => 'sword',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('job_armor_permissions')->insert([
            'job_id' => $job->id,
            'armor_category' => 'robe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '適性試験冒険者',
            'current_job_id' => $job->id,
            'explore_stamina' => 0,
            'hp_base' => 1000,
            'mp_base' => 0,
            'attack_base' => 1000,
            'defense_base' => 1000,
            'speed_base' => 0,
            'magic_base' => 0,
            'spirit_base' => 0,
            'luck_base' => 0,
        ]);
        $weaponItem = Item::query()->create([
            'name' => '適性外拳甲',
            'type' => 'weapon',
            'weapon_category' => 'fist',
            'weapon_rank' => 'EPIC',
            'str_bonus' => 800,
            'is_active' => true,
        ]);
        $armorItem = Item::query()->create([
            'name' => '適性外重鎧',
            'type' => 'armor',
            'armor_category' => 'heavy_armor',
            'armor_rank' => 'EPIC',
            'def_bonus' => 800,
            'is_active' => true,
        ]);
        $weapon = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $weaponItem->id,
            'is_equipped' => true,
            'equipped_slot' => 'weapon',
            'enhance_level' => 5,
            'affix_str_bonus' => 200,
            'killer_species_key' => 'dragon',
            'killer_damage_rate' => 0.20,
        ]);
        $armor = CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $armorItem->id,
            'is_equipped' => true,
            'equipped_slot' => 'armor',
            'enhance_level' => 5,
            'affix_def_bonus' => 200,
            'resist_species_key' => 'dragon',
            'species_damage_reduction_rate' => 0.20,
        ]);
        $weapon->load('item');
        $armor->load('item');

        CharacterStatusService::clearRequestCache($character->id);

        return [$character, $weapon, $armor];
    }
}
