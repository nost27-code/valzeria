<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\GameSetting;
use App\Models\Material;
use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationWar;
use App\Models\NationWarFacility;
use App\Models\NationWarParticipant;
use App\Models\NationWarSide;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\Battle\BattleActor;
use App\Services\Nation\NationFacilityService;
use App\Services\Nation\NationResourceService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationWarHpCalculator;
use App\Services\Nation\NationWarCannonService;
use App\Services\Nation\NationWarRebuildService;
use App\Services\Nation\NationWarRepairService;
use App\Services\Nation\NationWarService;
use App\Services\Nation\NationWarBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;

final class NationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_founding_creates_king_membership_and_all_five_level_one_facilities(): void
    {
        $character = $this->character('建国者');
        $nation = app(NationService::class)->create($character, 'テスト王国', '説明');

        $this->assertSame(1, $nation->memberships()->count());
        $this->assertSame('king', $nation->memberships()->first()->role);
        $this->assertEqualsCanonicalizing(NationFacility::TYPES, $nation->facilities()->pluck('facility_type')->all());
        $this->assertTrue($nation->facilities()->get()->every(fn (NationFacility $facility) => $facility->level === 1 && $facility->condition_bps === 10000));
    }

    public function test_nation_screen_can_be_previewed_locally_while_declaration_and_upgrades_remain_off(): void
    {
        config()->set('features.nation_war_enabled', true);
        $character = $this->character('画面建国者');
        $this->actingAs($character->user);

        Livewire::test(\App\Livewire\NationScreen::class)
            ->assertSee('国を興す')
            ->set('nationName', '画面試験国')
            ->call('createNation')
            ->assertSee('新たな国が興った！')
            ->assertSee('施設のレベル上げは現在停止中です。')
            ->assertSee('宣戦布告は停止中です。');
    }

    public function test_admin_can_edit_nation_settings_without_enabling_global_player_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $setting = GameSetting::where('setting_key', 'nation_war.sorties_per_day')->firstOrFail();

        Livewire::test(\App\Livewire\Admin\NationWarSettingsManager::class)
            ->set('values.'.$setting->id, '12')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('12', GameSetting::whereKey($setting->id)->value('value'));
        $this->assertFalse((bool) config('features.nation_war_enabled', false));
    }

    public function test_donation_decrements_inventory_and_records_balanced_ledger_atomically(): void
    {
        $character = $this->character('納品者');
        $nation = app(NationService::class)->create($character, '納品国');
        $material = Material::create(['material_code' => 'TEST_NATION_MAT', 'name' => '試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 3, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);

        $transaction = app(NationResourceService::class)->donate($character, $material->id, 4, 'nation-test-donation');

        $this->assertSame(6, CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame(12, (int) $nation->fresh()->treasury_points);
        $this->assertSame(12, (int) $transaction->points_delta);
        $this->assertSame(12, (int) $transaction->balance_after);
        $this->assertSame($transaction->id, app(NationResourceService::class)->donate($character, $material->id, 4, 'nation-test-donation')->id);
        $this->assertSame(6, CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
    }

    public function test_facility_upgrade_and_war_declaration_are_closed_by_default(): void
    {
        $attacker = $this->character('攻撃国王');
        $defender = $this->character('防衛国王');
        $nation = app(NationService::class)->create($attacker, '攻撃国');
        $defenderNation = app(NationService::class)->create($defender, '防衛国');
        $membership = NationMembership::where('character_id', $attacker->id)->firstOrFail();

        try { app(NationFacilityService::class)->upgrade($membership, $nation->facilities()->first()); $this->fail('upgrade should be disabled'); }
        catch (\DomainException $exception) { $this->assertStringContainsString('停止中', $exception->getMessage()); }
        try { app(NationWarService::class)->declare($membership, $defenderNation); $this->fail('declaration should be disabled'); }
        catch (\DomainException $exception) { $this->assertStringContainsString('準備中', $exception->getMessage()); }
    }

    public function test_uncalibrated_reference_damage_blocks_declaration_even_if_switch_is_on(): void
    {
        config()->set('features.nation_war_enabled', true);
        $attacker = $this->character('未校正攻撃国王');
        $defender = $this->character('未校正防衛国王');
        $nation = app(NationService::class)->create($attacker, '未校正攻撃国');
        $defenderNation = app(NationService::class)->create($defender, '未校正防衛国');
        $nation->update(['founded_at' => now()->subDays(8)]); $defenderNation->update(['founded_at' => now()->subDays(8)]);
        app(GameSettingService::class)->set('nation_war.declaration_enabled', '1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('基準Dが未校正');
        app(NationWarService::class)->declare(NationMembership::where('character_id', $attacker->id)->firstOrFail(), $defenderNation);
    }

    public function test_calibrated_declaration_freezes_active_members_and_allows_one_next_war_reservation(): void
    {
        config()->set('features.nation_war_enabled', true);
        app(GameSettingService::class)->set('nation_war.declaration_enabled', '1');
        app(GameSettingService::class)->set('nation_war.reference_damage', '1000');
        $attacker = $this->character('予約攻撃国王');
        $defender = $this->character('予約防衛国王');
        $nextDefender = $this->character('次戦防衛国王');
        $attackerNation = app(NationService::class)->create($attacker, '予約攻撃国');
        $defenderNation = app(NationService::class)->create($defender, '予約防衛国');
        $nextNation = app(NationService::class)->create($nextDefender, '次戦防衛国');
        foreach ([$attackerNation, $defenderNation, $nextNation] as $nation) $nation->update(['founded_at' => now()->subDays(8)]);
        $membership = NationMembership::where('character_id', $attacker->id)->firstOrFail();

        $war = app(NationWarService::class)->declare($membership, $defenderNation);
        $this->assertSame('preparing', $war->status);
        $this->assertCount(2, $war->participants);
        $this->assertCount(10, $war->facilities);
        $war->update(['status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(4)]);
        $reservation = app(NationWarService::class)->declare($membership, $nextNation);
        $this->assertSame('reserved', $reservation->status);
        $this->assertCount(2, $reservation->participants);
        $this->assertCount(0, $reservation->facilities);

        try { app(NationWarService::class)->declare($membership, $nextNation); $this->fail('second reservation should fail'); }
        catch (\DomainException $exception) { $this->assertStringContainsString('予約', $exception->getMessage()); }
    }

    public function test_facility_hp_uses_d_level_ratio_active_members_and_persistent_condition(): void
    {
        app(GameSettingService::class)->set('nation_war.reference_damage', '1000');
        $nation = app(NationService::class)->create($this->character('HP国王'), 'HP国');
        $wall = $nation->facilities()->where('facility_type', 'wall')->firstOrFail();
        $wall->update(['level' => 1, 'condition_bps' => 7500]);

        $calculator = app(NationWarHpCalculator::class);
        $this->assertSame(30000, $calculator->maxHp('wall', 1, 50));
        $this->assertSame(22500, $calculator->startingHp($wall->fresh(), 50));
    }

    public function test_logistics_repairs_and_arsenal_starts_rebuild_using_war_pool(): void
    {
        $nation = app(NationService::class)->create($this->character('補修国王'), '補修国');
        $enemy = app(NationService::class)->create($this->character('相手国王'), '相手国');
        $war = NationWar::create(['declaring_nation_id' => $nation->id, 'defending_nation_id' => $enemy->id, 'status' => 'active', 'declared_at' => now(), 'preparation_starts_at' => now(), 'starts_at' => now(), 'ends_at' => now()->addDays(5)]);
        $side = NationWarSide::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'side' => 'attacker', 'active_member_count' => 1, 'resource_pool_points' => 1000000]);
        $logistics = $this->warFacility($war, $nation, 'logistics', 120000, 120000);
        $arsenal = $this->warFacility($war, $nation, 'arsenal', 150000, 150000);
        $wall = $this->warFacility($war, $nation, 'wall', 300000, 200000);
        $cannon = $this->warFacility($war, $nation, 'magic_cannon', 90000, 0, 'destroyed');

        $this->assertSame(10000, app(NationWarRepairService::class)->repair($side, $wall, 10000));
        $this->assertGreaterThan(0, $side->fresh()->resource_spent_points);
        $rebuilt = app(NationWarRebuildService::class)->start($side->fresh(), $cannon);
        $this->assertSame('rebuilding', $rebuilt->status);
        $this->assertNotNull($rebuilt->rebuild_completes_at);
    }

    public function test_sortie_consumes_independent_stamina_and_does_not_mutate_normal_hp_sp(): void
    {
        config()->set('features.nation_war_enabled', true);
        $character = $this->character('出撃者');
        $enemyCharacter = $this->character('敵国王');
        $nation = app(NationService::class)->create($character, '出撃国');
        $enemy = app(NationService::class)->create($enemyCharacter, '出撃敵国');
        $war = NationWar::create(['declaring_nation_id' => $nation->id, 'defending_nation_id' => $enemy->id, 'status' => 'active', 'declared_at' => now()->subDays(4), 'preparation_starts_at' => now()->subDays(4), 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(4)]);
        NationWarParticipant::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'character_id' => $character->id, 'frozen_at' => now()->subDays(4)]);
        $target = $this->warFacility($war, $enemy, 'wall', 1000000, 1000000);
        $character->refresh();
        $normalHp = (int) $character->current_hp; $normalSp = (int) $character->current_mp;

        $result = app(NationWarBattleService::class)->sortie($character, $war, $target->id, 0);

        $this->assertSame(235, (int) $character->fresh()->explore_stamina);
        $this->assertSame($normalHp, (int) $character->fresh()->current_hp);
        $this->assertSame($normalSp, (int) $character->fresh()->current_mp);
        $this->assertSame(1, (int) \App\Models\NationWarDailySortie::where('nation_war_id', $war->id)->where('character_id', $character->id)->value('sortie_count'));
        $this->assertGreaterThan(0, $result['damage_applied']);
        $this->assertSame(30, $result['battle']->turnCount);
    }

    public function test_cannon_uses_configured_turns_current_damage_formula_and_no_normal_critical(): void
    {
        app(GameSettingService::class)->set('nation_war.cannon_direct_hit_rate', '0');
        $target = new BattleActor('砲撃対象', true, [
            'hp' => 10000, 'max_hp' => 10000, 'mp' => 100, 'max_mp' => 100,
            'str' => 100, 'def' => 300, 'agi' => 100, 'mag' => 100, 'spr' => 450, 'luk' => 100,
        ]);
        $service = app(NationWarCannonService::class);
        $shot = $service->fire($target, 1);

        $this->assertTrue($service->firesOnTurn(1, 3));
        $this->assertFalse($service->firesOnTurn(1, 4));
        $this->assertFalse($shot['direct_hit']);
        $this->assertSame('magical', $shot['attack_type']);
        $this->assertGreaterThanOrEqual(1275, $shot['damage']);
        $this->assertLessThanOrEqual(1725, $shot['damage']);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();
        return Character::create(['user_id' => $user->id, 'name' => $name, 'last_battle_at' => now(), 'explore_stamina' => 250, 'explore_stamina_max' => 250]);
    }

    private function warFacility(NationWar $war, Nation $nation, string $type, int $maxHp, int $currentHp, string $status = 'active'): NationWarFacility
    {
        return NationWarFacility::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'facility_type' => $type, 'level' => 1, 'opening_max_hp' => $maxHp, 'max_hp' => $maxHp, 'current_hp' => $currentHp, 'min_hp' => $currentHp, 'status' => $status, 'destroyed_at' => $currentHp === 0 ? now() : null]);
    }
}
