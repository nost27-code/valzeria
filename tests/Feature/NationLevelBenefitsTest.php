<?php

namespace Tests\Feature;

use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\Material;
use App\Models\NationAchievement;
use App\Models\NationActivityLog;
use App\Models\NationFacility;
use App\Models\NationGoal;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationResourceTransaction;
use App\Models\NationWantedMaterial;
use App\Models\NationWar;
use App\Models\NationWarHistory;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\Nation\NationAchievementBackfillService;
use App\Services\Nation\NationAchievementService;
use App\Services\Nation\NationCommunitySettingsService;
use App\Services\Nation\NationDecorationService;
use App\Services\Nation\NationFacilityService;
use App\Services\Nation\NationGoalService;
use App\Services\Nation\NationJoinApplicationService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationTimelineService;
use App\Services\Nation\NationWantedMaterialService;
use App\Services\Nation\NationWarPreparationPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class NationLevelBenefitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_member_capacity_is_the_smaller_of_level_capacity_and_emergency_cap(): void
    {
        $nation = app(NationService::class)->create($this->character('定員国王'), '定員確認国');
        $settings = app(NationCommunitySettingsService::class);
        app(GameSettingService::class)->set('nation.max_members', '100');

        $this->assertSame(20, $settings->maxMembersFor($nation));

        $nation->update(['development_exp' => 5_000]);
        $this->assertSame(22, $settings->maxMembersFor($nation->fresh()));

        app(GameSettingService::class)->set('nation.max_members', '21');
        $this->assertSame(21, $settings->maxMembersFor($nation->fresh()));
    }

    public function test_capacity_progression_stays_active_while_other_level_benefits_are_hidden(): void
    {
        config()->set('features.nation_development_enabled', true);
        config()->set('features.nation_level_benefits_enabled', false);
        app(GameSettingService::class)->set('nation.max_members', '100');
        $ruler = $this->character('段階公開国王');
        $nation = app(NationService::class)->create($ruler, '段階公開国');
        $nation->update(['development_exp' => 612_500]);

        $this->assertSame(40, app(NationCommunitySettingsService::class)->maxMembersFor($nation->fresh()));

        $this->actingAs($ruler->user);
        Livewire::test(NationScreen::class)
            ->assertSee('国家資材')
            ->assertSeeHtml('data-nation-menu-open')
            ->call('openNationMenuModal')
            ->assertSeeHtml('data-nation-menu-modal')
            ->assertSee('国家資材管理')
            ->assertDontSee('国家Lv特典')
            ->assertDontSee('共同目標')
            ->call('showDevelopmentBenefits')
            ->assertSet('page', 'home')
            ->assertHasErrors('nationAction');

        $membership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('準備中');
        app(NationGoalService::class)->create($membership, [
            'title' => '非公開中の目標',
            'metric_type' => 'manual',
        ]);
    }

    public function test_anniversary_scheduler_does_not_write_while_level_benefits_are_hidden(): void
    {
        config()->set('features.nation_level_benefits_enabled', false);
        $nation = app(NationService::class)->create($this->character('周年非公開国王'), '周年非公開国');
        $nation->update(['founded_at' => now()->subYears(2)]);

        $this->artisan('nation:unlock-anniversary-achievements')
            ->expectsOutput('国家Lv特典が非公開のため、建国一周年実績の付与をスキップしました。')
            ->assertSuccessful();

        $this->assertDatabaseMissing('nation_achievements', [
            'nation_id' => $nation->id,
            'achievement_key' => 'first_anniversary',
        ]);
    }

    public function test_backfill_commands_require_an_explicit_override_while_level_benefits_are_hidden(): void
    {
        config()->set('features.nation_level_benefits_enabled', false);
        app(NationService::class)->create($this->character('復元非公開国王'), '復元非公開国');
        $message = '国家Lv特典が非公開のため実行しません。意図的に先行復元する場合だけ --force を指定してください。';

        $this->artisan('nation:backfill-achievements')
            ->expectsOutput($message)
            ->assertFailed();
        $this->artisan('nation:backfill-timeline')
            ->expectsOutput($message)
            ->assertFailed();

        $this->assertDatabaseCount('nation_achievements', 0);
        $this->assertDatabaseMissing('nation_activity_logs', [
            'event_type' => 'development_level_reached',
        ]);
    }

    public function test_join_submission_stops_at_level_capacity_even_when_emergency_cap_is_higher(): void
    {
        app(GameSettingService::class)->set('nation.max_members', '100');
        $ruler = $this->character('満員国王');
        $nation = app(NationService::class)->create($ruler, '満員確認国');

        foreach (range(1, 19) as $index) {
            NationMembership::create([
                'nation_id' => $nation->id,
                'character_id' => $this->character("満員国民{$index}")->id,
                'role' => 'citizen',
                'joined_at' => now(),
            ]);
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('定員');
        app(NationJoinApplicationService::class)->submit($this->character('申請者'), $nation);
    }

    public function test_facility_upgrade_rechecks_the_nation_level_cap_inside_the_transaction(): void
    {
        config()->set('features.nation_war_enabled', true);
        app(GameSettingService::class)->set('nation.facility_upgrades_enabled', '1');
        $ruler = $this->character('施設国王');
        $nation = app(NationService::class)->create($ruler, '施設確認国');
        $nation->update(['treasury_points' => 100_000]);
        $membership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $facility = NationFacility::where('nation_id', $nation->id)->where('facility_type', 'wall')->firstOrFail();
        $facility->update(['level' => 5]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('国家Lv');
        app(NationFacilityService::class)->upgrade($membership, $facility->fresh());
    }

    public function test_goal_progress_counts_only_donation_transactions(): void
    {
        $ruler = $this->character('目標国王');
        $nation = app(NationService::class)->create($ruler, '目標確認国');
        $membership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $goal = app(NationGoalService::class)->create($membership, [
            'title' => '国家資材100pt',
            'metric_type' => 'donation_points',
            'target_value' => 100,
        ]);
        NationResourceTransaction::create([
            'nation_id' => $nation->id,
            'transaction_type' => 'war_pool_refund',
            'points_delta' => 100,
            'balance_after' => 100,
        ]);
        NationResourceTransaction::create([
            'nation_id' => $nation->id,
            'character_id' => $ruler->id,
            'transaction_type' => 'donation',
            'quantity' => 40,
            'points_delta' => 40,
            'development_exp_delta' => 40,
            'balance_after' => 140,
        ]);

        $this->assertSame(40, app(NationGoalService::class)->currentValue($goal));
        app(NationGoalService::class)->sync($nation);
        $this->assertSame(NationGoal::STATUS_ACTIVE, $goal->fresh()->status);
    }

    public function test_wanted_materials_obey_slots_and_new_management_permissions(): void
    {
        $ruler = $this->character('募集国王');
        $nation = app(NationService::class)->create($ruler, '募集確認国');
        $officer = $this->character('募集兵站官');
        $membership = NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $officer->id,
            'role' => 'logistics_officer',
            'joined_at' => now(),
        ]);
        $material = Material::create(['material_code' => 'TEST_WANTED_MAT', 'name' => '募集試験素材', 'category' => 'city', 'rarity' => 'N']);
        NationMaterialConversionRate::create([
            'material_id' => $material->id,
            'points_per_unit' => 1,
            'development_exp_per_unit' => 1,
            'is_active' => true,
        ]);

        $rows = app(NationWantedMaterialService::class)->replace($membership, [[
            'material_id' => $material->id,
            'purpose_note' => '共同で集めます',
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame('共同で集めます', $rows->first()->purpose_note);
    }

    public function test_achievement_showcase_and_decorations_are_permanent_and_do_not_grant_points(): void
    {
        $ruler = $this->character('装飾国王');
        $nation = app(NationService::class)->create($ruler, '装飾確認国');
        $nation->update(['development_exp' => 495_000, 'treasury_points' => 321]);
        $membership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $achievement = app(NationAchievementService::class)
            ->unlock($nation, 'nation_level_10');
        app(NationAchievementService::class)
            ->setShowcase($membership, [$achievement->achievement_key]);
        app(NationDecorationService::class)->save($membership, [
            'outer_frame' => 'outer_frame_gold',
            'name_plate' => 'name_plate_royal',
            'header_ornament' => 'header_ornament_gold',
            'emblem_frame' => 'emblem_frame_special',
        ]);

        $this->assertSame(321, (int) $nation->fresh()->treasury_points);
        $this->assertSame(1, NationAchievement::where('nation_id', $nation->id)->whereNotNull('display_position')->count());
        $this->assertSame('outer_frame_gold', $nation->fresh()->decoration_settings['outer_frame']);
    }

    public function test_saving_war_preset_never_spends_treasury_points(): void
    {
        config()->set('features.nation_war_enabled', true);
        $ruler = $this->character('プリセット国王');
        $nation = app(NationService::class)->create($ruler, 'プリセット確認国');
        $nation->update(['development_exp' => 95_000, 'treasury_points' => 500]);
        $membership = NationMembership::where('character_id', $ruler->id)->firstOrFail();

        $preset = app(NationWarPreparationPresetService::class)->save($membership, [
            'name' => '守備重視',
            'pool_contribution_points' => 300,
            'facility_upgrade_limit_points' => 150,
            'facility_priority' => NationFacility::TYPES,
            'repair_reserve_warning_points' => 100,
        ]);

        $this->assertSame(500, (int) $nation->fresh()->treasury_points);
        $this->assertSame(300, (int) $preset->pool_contribution_points);
        $this->assertSame(0, NationResourceTransaction::where('nation_id', $nation->id)->count());
    }

    public function test_level_benefit_pages_render_and_accept_authorized_livewire_updates(): void
    {
        config()->set('features.nation_development_enabled', true);
        config()->set('features.nation_war_enabled', false);
        $ruler = $this->character('特典画面国王');
        $nation = app(NationService::class)->create($ruler, '特典画面国');
        $nation->update(['development_exp' => 297_500]);
        $material = Material::create([
            'material_code' => 'TEST_BENEFIT_UI_MAT',
            'name' => '特典画面素材',
            'category' => 'city',
            'rarity' => 'N',
        ]);
        NationMaterialConversionRate::create([
            'material_id' => $material->id,
            'points_per_unit' => 1,
            'development_exp_per_unit' => 1,
            'is_active' => true,
        ]);
        $this->actingAs($ruler->user);

        $screen = Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-menu-open')
            ->call('openNationMenuModal')
            ->assertSeeHtml('data-nation-menu-modal')
            ->assertSee('国家Lv特典')
            ->call('showDevelopmentBenefits')
            ->assertSet('page', 'benefits')
            ->assertSeeHtml('data-nation-benefits')
            ->assertSee('国民定員')
            ->call('showNationGoals')
            ->set('goalTitle', '画面から設定する掲示目標')
            ->set('goalMetricType', 'manual')
            ->call('createNationGoal')
            ->assertHasNoErrors()
            ->assertSee('画面から設定する掲示目標')
            ->call('showWantedMaterials')
            ->set('wantedMaterialIds.0', $material->id)
            ->set('wantedMaterialNotes.0', '画面確認用に集めます')
            ->call('saveWantedMaterials')
            ->assertHasNoErrors()
            ->assertSee('募集素材を更新しました。')
            ->call('showNationDecorations')
            ->set('decorationSettings.outer_frame', 'outer_frame_gold')
            ->call('saveNationDecorations')
            ->assertHasNoErrors()
            ->call('showDonationAnalytics')
            ->assertSet('page', 'analytics')
            ->assertSeeHtml('data-nation-analytics')
            ->assertSee('過去7日')
            ->call('showNationTimeline')
            ->assertSet('page', 'timeline')
            ->assertSeeHtml('data-nation-timeline')
            ->call('showWarPreparationPresets')
            ->assertSet('pendingFeature', '戦争方針設定');

        $this->assertDatabaseHas('nation_goals', [
            'nation_id' => $nation->id,
            'title' => '画面から設定する掲示目標',
        ]);
        $this->assertDatabaseHas('nation_wanted_materials', [
            'nation_id' => $nation->id,
            'material_id' => $material->id,
            'is_active' => true,
        ]);
        $this->assertSame('outer_frame_gold', $nation->fresh()->decoration_settings['outer_frame']);
        $this->assertSame(1, NationWantedMaterial::where('nation_id', $nation->id)->count());
        $screen->assertHasNoErrors();
    }

    public function test_member_capacity_guide_is_visible_from_public_detail_and_own_home_even_when_development_ui_is_off(): void
    {
        config()->set('features.nation_development_enabled', false);
        $ruler = $this->character('定員案内国王');
        $nation = app(NationService::class)->create($ruler, '定員案内国');

        $this->actingAs($ruler->user);
        Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-capacity-guide')
            ->assertSee('国家Lvと国民数上限')
            ->assertSee('Lv1〜4')
            ->assertSee('20人')
            ->assertSee('Lv50')
            ->assertSee('40人');

        $visitor = $this->character('定員案内閲覧者');
        $this->actingAs($visitor->user);
        Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-capacity-guide')
            ->call('showNationDetail', $nation->id)
            ->assertSeeHtml('data-nation-capacity-guide')
            ->assertSee('現在の開放上限は国家Lv50の40人です。')
            ->assertSee('国家Lvに応じて開放される機能を予定しています。')
            ->assertSee('現在準備中のため、公開までしばらくお待ちください。');
    }

    public function test_war_milestones_and_existing_achievements_are_recorded_idempotently(): void
    {
        $ruler = $this->character('復元国王');
        $nation = app(NationService::class)->create($ruler, '復元確認国');
        $nation->update(['founded_at' => now()->subYears(2), 'development_exp' => 22_500]);
        $enemy = app(NationService::class)->create($this->character('復元敵国王'), '復元敵国');
        NationResourceTransaction::create([
            'nation_id' => $nation->id,
            'character_id' => $ruler->id,
            'transaction_type' => 'donation',
            'quantity' => 22_500,
            'points_delta' => 22_500,
            'development_exp_delta' => 22_500,
            'balance_after' => 22_500,
            'created_at' => now()->subYear(),
        ]);
        NationActivityLog::create([
            'nation_id' => $nation->id,
            'event_type' => 'member_joined',
            'created_at' => now()->subMonths(10),
        ]);
        NationResourceTransaction::create([
            'nation_id' => $nation->id,
            'transaction_type' => 'facility_upgrade',
            'points_delta' => -400,
            'balance_after' => 22_100,
            'metadata' => ['facility_type' => 'wall', 'from_level' => 1],
            'created_at' => now()->subMonths(9),
        ]);
        $war = NationWar::create([
            'declaring_nation_id' => $nation->id,
            'defending_nation_id' => $enemy->id,
            'status' => 'resolved',
            'declared_at' => now()->subMonths(8)->subDays(8),
            'preparation_starts_at' => now()->subMonths(8)->subDays(8),
            'starts_at' => now()->subMonths(8)->subDays(5),
            'ends_at' => now()->subMonths(8),
            'resolved_at' => now()->subMonths(8),
            'winner_nation_id' => $nation->id,
            'resolution_type' => 'judgment',
        ]);
        $history = NationWarHistory::create([
            'nation_war_id' => $war->id,
            'declaring_nation_id' => $nation->id,
            'defending_nation_id' => $enemy->id,
            'winner_nation_id' => $nation->id,
            'resolution_type' => 'judgment',
            'summary' => ['attacker' => [], 'defender' => []],
            'resolved_at' => $war->resolved_at,
        ]);

        app(NationTimelineService::class)->recordWarResolved($history);
        app(NationAchievementService::class)->recordWarResolved($history);
        $first = app(NationAchievementBackfillService::class)->backfill($nation);
        $second = app(NationAchievementBackfillService::class)->backfill($nation);

        $this->assertSame(6, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(8, NationAchievement::where('nation_id', $nation->id)->count());
        $this->assertDatabaseHas('nation_activity_logs', [
            'nation_id' => $nation->id,
            'event_type' => 'war_first_participation',
        ]);
        $this->assertDatabaseHas('nation_activity_logs', [
            'nation_id' => $nation->id,
            'event_type' => 'war_first_win',
        ]);
        $this->assertDatabaseHas('nation_achievements', [
            'nation_id' => $nation->id,
            'achievement_key' => 'first_anniversary',
        ]);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::create([
            'user_id' => $user->id,
            'name' => $name,
            'level' => 50,
            'last_battle_at' => now(),
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
        ]);
    }
}
