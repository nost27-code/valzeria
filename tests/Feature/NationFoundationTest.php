<?php

namespace Tests\Feature;

use App\Livewire\Admin\NationWarSettingsManager;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\GameSetting;
use App\Models\Material;
use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationMaterialConversionRate;
use App\Models\NationMembership;
use App\Models\NationResourceTransaction;
use App\Models\NationWar;
use App\Models\NationWarDailySortie;
use App\Models\NationWarFacility;
use App\Models\NationWarParticipant;
use App\Models\NationWarSide;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Battle\BattleActor;
use App\Services\GameSettingService;
use App\Services\Nation\NationDevelopmentLevelService;
use App\Services\Nation\NationDevelopmentService;
use App\Services\Nation\NationFacilityService;
use App\Services\Nation\NationResourceService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationWarBattleService;
use App\Services\Nation\NationWarCannonService;
use App\Services\Nation\NationWarHpCalculator;
use App\Services\Nation\NationWarLifecycleService;
use App\Services\Nation\NationWarRebuildService;
use App\Services\Nation\NationWarRepairService;
use App\Services\Nation\NationWarService;
use Database\Seeders\AllDungeonsSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\EnemyDropsSeeder;
use Database\Seeders\EnemySeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class NationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_founding_creates_ruler_membership_and_all_five_level_one_facilities(): void
    {
        $character = $this->character('建国者');
        $nation = app(NationService::class)->create($character, 'テスト', '説明', 'kingdom');

        $this->assertSame(1, $nation->memberships()->count());
        $this->assertSame('ruler', $nation->memberships()->first()->role);
        $this->assertSame('テスト王国', $nation->display_name);
        $this->assertSame('国王', $nation->ruler_title);
        $this->assertEqualsCanonicalizing(NationFacility::TYPES, $nation->facilities()->pluck('facility_type')->all());
        $this->assertTrue($nation->facilities()->get()->every(fn (NationFacility $facility) => $facility->level === 1 && $facility->condition_bps === 10000));
    }

    public function test_miasma_bone_drops_are_active_even_while_nation_war_is_off(): void
    {
        config()->set('features.nation_war_enabled', false);
        $this->seed([
            CitySeeder::class,
            AllDungeonsSeeder::class,
            EnemySeeder::class,
            EnemyDropsSeeder::class,
        ]);

        $this->assertMiasmaBoneDropsAreActive();

        DB::table('material_drops')
            ->whereIn('material_id', DB::table('materials')->where('material_code', 'WEV0030')->select('id'))
            ->update(['is_active' => false]);

        $migration = require database_path('migrations/2026_08_25_190000_enable_miasma_bone_drops.php');
        $migration->up();

        $this->assertMiasmaBoneDropsAreActive();

        $migration->down();
        $this->assertSame(0, DB::table('material_drops')
            ->whereIn('material_id', DB::table('materials')->where('material_code', 'WEV0030')->select('id'))
            ->where('is_active', true)
            ->count());

        $migration->up();
        $this->assertMiasmaBoneDropsAreActive();
    }

    private function assertMiasmaBoneDropsAreActive(): void
    {
        $drops = DB::table('material_drops')
            ->join('materials', 'materials.id', '=', 'material_drops.material_id')
            ->join('enemies', 'enemies.id', '=', 'material_drops.enemy_id')
            ->where('materials.material_code', 'WEV0030')
            ->where('material_drops.is_active', true)
            ->get([
                'enemies.area_id',
                'enemies.name',
                'material_drops.drop_rate',
                'material_drops.drop_first_clear_only',
            ]);

        $this->assertFalse((bool) config('features.nation_war_enabled', false));
        $this->assertCount(14, $drops);
        $this->assertEqualsCanonicalizing([
            '50:死霊兵', '50:黒骨犬', '51:呪い騎士', '51:吸血コウモリ',
            '52:冥界の番犬', '52:門番デーモン', '53:魔神神殿兵', '53:魔神封印守',
            '54:魔王軍兵', '54:魔王軍弓兵', '55:瘴気スライム', '55:毒霧の悪魔',
            '56:奈落の影', '56:深淵騎士',
        ], $drops->map(fn ($drop): string => "{$drop->area_id}:{$drop->name}")->all());
        $this->assertTrue($drops->every(
            fn ($drop): bool => (float) $drop->drop_rate === 18.0 && ! (bool) $drop->drop_first_clear_only,
        ));
    }

    public function test_nation_screen_shows_unaffiliated_community_actions_and_keeps_war_actions_in_pending_modal(): void
    {
        config()->set('features.nation_community_enabled', true);
        $character = $this->character('未所属冒険者');
        $otherFounder = $this->character('他国建国者');
        app(NationService::class)->create($otherFounder, '蒼天', '仲間と歩む国です。', 'knight_state');
        $this->actingAs($character->user);

        Livewire::test(NationScreen::class)
            ->assertSee('国家を探す')
            ->assertSee('建国する')
            ->assertSee('国家ピックアップ')
            ->assertSee('蒼天騎士国')
            ->assertSee('国家とは？')
            ->assertSee('data-nation-membership-state="unaffiliated"', false)
            ->assertSee('wire:click="showCreate"', false)
            ->call('showNotImplemented', 'resource-management')
            ->assertSet('pendingFeature', '国家資材管理')
            ->assertSee('この機能は現在準備中です。')
            ->call('closeNotImplementedModal')
            ->assertSet('pendingFeature', null);
    }

    public function test_nation_screen_shows_member_community_dashboard_while_war_actions_remain_closed(): void
    {
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', false);
        $character = $this->character('所属冒険者');
        $nation = app(NationService::class)->create($character, '白銀', '静かな北国です。', 'kingdom');
        $nation->update([
            'treasury_points' => 28540,
            'war_wins' => 12,
            'war_losses' => 4,
            'war_draws' => 0,
        ]);
        $nation->facilities()->where('facility_type', 'magic_cannon')->update([
            'level' => 6,
            'condition_bps' => 6800,
        ]);
        $this->actingAs($character->user);

        Livewire::test(NationScreen::class)
            ->assertSee('白銀王国')
            ->assertSee('国王：所属冒険者')
            ->assertSee('統治者メニュー')
            ->assertSee('国家紹介')
            ->assertSee('国民一覧')
            ->assertSee('data-nation-membership-state="member"', false)
            ->assertDontSee('wire:click="donate"', false)
            ->call('showResourceManagement')
            ->assertSet('pendingFeature', '国家資材管理')
            ->assertSee('この機能は現在準備中です。');
    }

    public function test_member_can_confirm_and_donate_materials_from_the_development_screen(): void
    {
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', true);
        $character = $this->character('発展納品者');
        $nation = app(NationService::class)->create($character, '発展確認国');
        $material = Material::create(['material_code' => 'TEST_UI_DONATION_MAT', 'name' => '画面納品試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 3, 'development_exp_per_unit' => 2, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);
        $this->actingAs($character->user);

        Livewire::test(NationScreen::class)
            ->assertSee('国家Lv1')
            ->assertSee('国家資材管理')
            ->call('showResourceManagement')
            ->assertSet('page', 'resources')
            ->assertSee('都市素材は装備進化・素材交換・NPC調達にも使用します。')
            ->assertSee('画面納品試験資材')
            ->set('donationMaterialId', $material->id)
            ->set('donationQuantity', 4)
            ->call('openDonationConfirmation')
            ->assertSet('showDonationConfirmationModal', true)
            ->assertSee('納品後の残数')
            ->assertSee('6個')
            ->call('donateMaterials')
            ->assertSet('showDonationConfirmationModal', false)
            ->assertSee('国家資材 +12pt / 国家発展EXP +8')
            ->assertSee('あなたの貢献度')
            ->assertSee('8 EXP');

        $this->assertSame(6, (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame(12, (int) $nation->fresh()->treasury_points);
        $this->assertSame(8, (int) $nation->fresh()->development_exp);
    }

    public function test_unaffiliated_players_see_only_nation_level_not_development_exp(): void
    {
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', true);
        $viewer = $this->character('国家探索者');
        $founder = $this->character('国家Lv公開者');
        $nation = app(NationService::class)->create($founder, '公開発展');
        $nation->update(['development_exp' => 95000]);
        $this->actingAs($viewer->user);

        Livewire::test(NationScreen::class)
            ->assertSee('公開発展王国')
            ->assertSee('国家Lv20')
            ->assertDontSee('95,000');
    }

    public function test_donation_service_stays_closed_when_the_development_flag_is_off(): void
    {
        config()->set('features.nation_development_enabled', false);
        $character = $this->character('停止中納品者');
        $nation = app(NationService::class)->create($character, '停止中納品国');
        $material = Material::create(['material_code' => 'TEST_DISABLED_DONATION_MAT', 'name' => '停止中納品資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 1, 'development_exp_per_unit' => 1, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 2]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('国家資材納品は現在準備中です。');
        try {
            app(NationResourceService::class)->donate($character, $material->id, 1, 'nation-disabled-donation');
        } finally {
            $this->assertSame(2, (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
            $this->assertSame(0, (int) $nation->fresh()->development_exp);
        }
    }

    public function test_admin_can_edit_nation_settings_without_enabling_global_player_flag(): void
    {
        config()->set('features.nation_war_enabled', false);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $setting = GameSetting::where('setting_key', 'nation_war.sorties_per_day')->firstOrFail();

        Livewire::test(NationWarSettingsManager::class)
            ->assertSee('画面プレビュー ON')
            ->assertSee('国家ゲーム OFF')
            ->set('values.'.$setting->id, '12')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('12', GameSetting::whereKey($setting->id)->value('value'));
        $this->assertFalse((bool) config('features.nation_war_enabled', false));
    }

    public function test_disabled_nation_war_lifecycle_does_not_advance_existing_rows(): void
    {
        config()->set('features.nation_war_enabled', false);
        $attacker = app(NationService::class)->create($this->character('停止中攻撃国王'), '停止中攻撃国');
        $defender = app(NationService::class)->create($this->character('停止中防衛国王'), '停止中防衛国');
        $war = NationWar::create([
            'declaring_nation_id' => $attacker->id,
            'defending_nation_id' => $defender->id,
            'status' => 'reserved',
            'declared_at' => now()->subDays(3),
            'preparation_starts_at' => now()->subDays(3),
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(5),
        ]);

        $this->assertSame(
            ['activated' => 0, 'resolved' => 0, 'rebuilt' => 0],
            app(NationWarLifecycleService::class)->run(),
        );
        $this->assertSame('reserved', $war->fresh()->status);
        $this->assertSame(0, $war->facilities()->count());
    }

    public function test_donation_decrements_inventory_and_records_balanced_ledger_atomically(): void
    {
        $character = $this->character('納品者');
        $nation = app(NationService::class)->create($character, '納品国');
        $material = Material::create(['material_code' => 'TEST_NATION_MAT', 'name' => '試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 3, 'development_exp_per_unit' => 2, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);

        $transaction = app(NationResourceService::class)->donate($character, $material->id, 4, 'nation-test-donation');

        $this->assertSame(6, CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame(12, (int) $nation->fresh()->treasury_points);
        $this->assertSame(12, (int) $transaction->points_delta);
        $this->assertSame(12, (int) $transaction->balance_after);
        $this->assertSame(8, (int) $nation->fresh()->development_exp);
        $this->assertSame(8, (int) $transaction->development_exp_delta);
        $this->assertSame($transaction->id, app(NationResourceService::class)->donate($character, $material->id, 4, 'nation-test-donation')->id);
        $this->assertSame(6, CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
    }

    public function test_donation_rejects_an_idempotency_key_reused_for_another_payload(): void
    {
        $character = $this->character('冪等納品者');
        app(NationService::class)->create($character, '冪等国');
        $material = Material::create(['material_code' => 'TEST_IDEMPOTENT_MAT', 'name' => '冪等試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 3, 'development_exp_per_unit' => 2, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 10]);
        $service = app(NationResourceService::class);
        $service->donate($character, $material->id, 4, 'nation-test-payload-match');

        try {
            $service->donate($character, $material->id, 3, 'nation-test-payload-match');
            $this->fail('異なる数量での冪等キー再利用を拒否する必要があります。');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('異なる納品内容', $exception->getMessage());
        }

        $this->assertSame(6, (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame(1, NationResourceTransaction::where('idempotency_key', 'nation-test-payload-match')->count());
    }

    public function test_donation_lock_queries_follow_the_documented_order(): void
    {
        $character = $this->character('ロック順納品者');
        app(NationService::class)->create($character, 'ロック順国');
        $material = Material::create(['material_code' => 'TEST_LOCK_ORDER_MAT', 'name' => 'ロック順試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 1, 'development_exp_per_unit' => 1, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 2]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(NationResourceService::class)->donate($character, $material->id, 1, 'nation-test-lock-order');

        $nationIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "nations"'));
        $membershipIndex = collect($queries)->search(fn (string $sql, int $index): bool => $index > $nationIndex && str_contains($sql, 'from "nation_memberships"'));
        $rateIndex = collect($queries)->search(fn (string $sql, int $index): bool => $index > $membershipIndex && str_contains($sql, 'from "nation_material_conversion_rates"'));
        $stockIndex = collect($queries)->search(fn (string $sql, int $index): bool => $index > $rateIndex && str_contains($sql, 'from "character_materials"'));

        $this->assertSame(
            ['nations', 'nation_memberships', 'nation_material_conversion_rates', 'character_materials'],
            NationResourceService::DONATION_LOCK_ORDER,
        );
        $this->assertIsInt($nationIndex);
        $this->assertIsInt($membershipIndex);
        $this->assertIsInt($rateIndex);
        $this->assertIsInt($stockIndex);
        $this->assertTrue($nationIndex < $membershipIndex && $membershipIndex < $rateIndex && $rateIndex < $stockIndex);
    }

    public function test_spend_rolls_back_balance_when_ledger_insert_fails_without_an_outer_transaction(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('この失敗注入はSQLiteの一時triggerを使用します。');
        }

        $nation = app(NationService::class)->create($this->character('単独消費国王'), '単独消費国');
        $nation->update(['treasury_points' => 100]);
        DB::statement("CREATE TRIGGER fail_nation_spend BEFORE INSERT ON nation_resource_transactions WHEN NEW.transaction_type = 'forced_failure' BEGIN SELECT RAISE(ABORT, 'forced ledger failure'); END");

        try {
            app(NationResourceService::class)->spend($nation, 40, 'forced_failure');
            $this->fail('台帳作成失敗を送出する必要があります。');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('forced ledger failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_nation_spend');
        }

        $this->assertSame(100, (int) $nation->fresh()->treasury_points);
        $this->assertSame(0, NationResourceTransaction::where('transaction_type', 'forced_failure')->count());
    }

    public function test_nation_level_uses_integer_thresholds_and_keeps_excess_exp_at_level_fifty(): void
    {
        $levels = app(NationDevelopmentLevelService::class);

        $this->assertSame(1, $levels->levelFor(0));
        $this->assertSame(1, $levels->levelFor(499));
        $this->assertSame(2, $levels->levelFor(500));
        $this->assertSame(19, $levels->levelFor(94028));
        $this->assertSame(20, $levels->levelFor(95000));
        $this->assertSame(49, $levels->levelFor(612499));
        $this->assertSame(50, $levels->levelFor(612500));
        $this->assertSame(50, $levels->levelFor(900000));
        $this->assertSame(612500, $levels->cumulativeExpForLevel(50));
        $this->assertSame(900000, $levels->progress(900000)['total_exp']);
        $this->assertTrue($levels->progress(900000)['is_max']);

        for ($level = 2; $level <= 50; $level++) {
            $threshold = $levels->cumulativeExpForLevel($level);
            $this->assertSame($level - 1, $levels->levelFor($threshold - 1));
            $this->assertSame($level, $levels->levelFor($threshold));
        }
    }

    public function test_existing_urban_material_rates_keep_resource_points_and_use_one_or_two_development_exp(): void
    {
        $low = NationMaterialConversionRate::query()
            ->where('points_per_unit', 1)
            ->where('development_exp_per_unit', 1)
            ->count();
        $high = NationMaterialConversionRate::query()
            ->where('points_per_unit', 3)
            ->where('development_exp_per_unit', 2)
            ->count();

        $this->assertSame(20, $low);
        $this->assertSame(20, $high);
        $this->assertSame(40, NationMaterialConversionRate::where('is_active', true)->count());
    }

    public function test_spending_and_crediting_resources_do_not_change_development_exp(): void
    {
        $nation = app(NationService::class)->create($this->character('発展維持国王'), '発展維持国');
        $nation->update(['treasury_points' => 100, 'development_exp' => 50]);
        $resources = app(NationResourceService::class);

        $spent = $resources->spend($nation, 30, 'facility_upgrade');
        $credited = $resources->credit($nation, 10, 'war_pool_refund');

        $this->assertSame(80, (int) $nation->fresh()->treasury_points);
        $this->assertSame(50, (int) $nation->fresh()->development_exp);
        $this->assertSame(0, (int) $spent->development_exp_delta);
        $this->assertSame(0, (int) $credited->development_exp_delta);
    }

    public function test_contribution_rows_keep_deleted_character_exp_as_an_anonymous_row(): void
    {
        $ruler = $this->character('現役納品者');
        $nation = app(NationService::class)->create($ruler, '貢献集計国');
        $retired = $this->character('退会予定納品者');
        NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $retired->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $material = Material::create(['material_code' => 'TEST_CONTRIBUTION_MAT', 'name' => '貢献試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 3, 'development_exp_per_unit' => 2, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $ruler->id, 'material_id' => $material->id, 'quantity' => 2]);
        CharacterMaterial::create(['character_id' => $retired->id, 'material_id' => $material->id, 'quantity' => 3]);
        $resources = app(NationResourceService::class);
        $resources->donate($ruler, $material->id, 1, 'nation-current-contribution');
        $resources->donate($retired, $material->id, 2, 'nation-retired-contribution');

        app(AccountDeletionService::class)->deleteUser($retired->user);

        $development = app(NationDevelopmentService::class);
        $rows = $development->contributionRows($nation);
        $this->assertSame(6, $development->ledgerTotal($nation));
        $this->assertSame(6, (int) $nation->fresh()->development_exp);
        $this->assertSame(2, $development->personalContribution($nation, $ruler));
        $this->assertSame(6, $rows->sum('development_exp'));
        $this->assertSame(['退会した冒険者', '現役納品者'], $rows->pluck('name')->all());
        $this->assertNull(NationResourceTransaction::where('idempotency_key', 'nation-retired-contribution')->value('character_id'));
    }

    public function test_nation_development_audit_detects_and_repairs_cache_drift(): void
    {
        $character = $this->character('監査納品者');
        $nation = app(NationService::class)->create($character, '監査国');
        $material = Material::create(['material_code' => 'TEST_AUDIT_MAT', 'name' => '監査試験資材', 'category' => 'city', 'rarity' => 'R']);
        NationMaterialConversionRate::create(['material_id' => $material->id, 'points_per_unit' => 1, 'development_exp_per_unit' => 1, 'is_active' => true]);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 3]);
        app(NationResourceService::class)->donate($character, $material->id, 2, 'nation-audit-contribution');
        $nation->update(['development_exp' => 999]);

        $this->artisan('nation:audit-development', ['--nation-id' => [$nation->id]])
            ->expectsOutputToContain('MISMATCH')
            ->assertFailed();

        $this->artisan('nation:audit-development', ['--nation-id' => [$nation->id], '--repair' => true])
            ->expectsOutputToContain('REPAIRED')
            ->assertSuccessful();
        $this->assertSame(2, (int) $nation->fresh()->development_exp);
    }

    public function test_facility_upgrade_and_war_declaration_are_closed_by_default(): void
    {
        config()->set('features.nation_war_enabled', false);
        $attacker = $this->character('攻撃国王');
        $defender = $this->character('防衛国王');
        $nation = app(NationService::class)->create($attacker, '攻撃国');
        $defenderNation = app(NationService::class)->create($defender, '防衛国');
        $membership = NationMembership::where('character_id', $attacker->id)->firstOrFail();

        try {
            app(NationFacilityService::class)->upgrade($membership, $nation->facilities()->first());
            $this->fail('upgrade should be disabled');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('停止中', $exception->getMessage());
        }
        try {
            app(NationWarService::class)->declare($membership, $defenderNation);
            $this->fail('declaration should be disabled');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('準備中', $exception->getMessage());
        }
    }

    public function test_uncalibrated_reference_damage_blocks_declaration_even_if_switch_is_on(): void
    {
        config()->set('features.nation_war_enabled', true);
        $attacker = $this->character('未校正攻撃国王');
        $defender = $this->character('未校正防衛国王');
        $nation = app(NationService::class)->create($attacker, '未校正攻撃国');
        $defenderNation = app(NationService::class)->create($defender, '未校正防衛国');
        $nation->update(['founded_at' => now()->subDays(8)]);
        $defenderNation->update(['founded_at' => now()->subDays(8)]);
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
        foreach ([$attackerNation, $defenderNation, $nextNation] as $nation) {
            $nation->update(['founded_at' => now()->subDays(8)]);
        }
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

        try {
            app(NationWarService::class)->declare($membership, $nextNation);
            $this->fail('second reservation should fail');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('予約', $exception->getMessage());
        }
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
        $normalHp = (int) $character->current_hp;
        $normalSp = (int) $character->current_mp;

        $result = app(NationWarBattleService::class)->sortie($character, $war, $target->id, 0);

        $this->assertSame(235, (int) $character->fresh()->explore_stamina);
        $this->assertSame($normalHp, (int) $character->fresh()->current_hp);
        $this->assertSame($normalSp, (int) $character->fresh()->current_mp);
        $this->assertSame(1, (int) NationWarDailySortie::where('nation_war_id', $war->id)->where('character_id', $character->id)->value('sortie_count'));
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
