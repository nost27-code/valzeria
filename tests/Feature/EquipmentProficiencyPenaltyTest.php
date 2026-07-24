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
        $this->assertEqualsWithDelta(0.17, $permissionService->effectiveKillerDamageRate($character, $weapon), 0.00001);
        $this->assertEqualsWithDelta(0.13, $permissionService->effectiveSpeciesDamageReductionRate($character, $armor), 0.00001);

        app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);

        $this->assertTrue((bool) $weapon->fresh()->is_equipped);
        $this->assertTrue((bool) $armor->fresh()->is_equipped);
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
