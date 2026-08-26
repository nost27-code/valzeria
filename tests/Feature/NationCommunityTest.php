<?php

namespace Tests\Feature;

use App\Enums\NationType;
use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\CityHeader;
use App\Livewire\MainScreenShell;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\Nation;
use App\Models\NationActivityLog;
use App\Models\NationFacility;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Models\NationMembershipCooldown;
use App\Models\NationWar;
use App\Models\NationWarParticipant;
use App\Models\User;
use App\Services\CharacterNotificationService;
use App\Services\GameSettingService;
use App\Services\Nation\NationDissolutionService;
use App\Services\Nation\NationEmblemCatalog;
use App\Services\Nation\NationHeaderBackgroundCatalog;
use App\Services\Nation\NationJoinApplicationService;
use App\Services\Nation\NationMembershipCooldownService;
use App\Services\Nation\NationMembershipService;
use App\Services\Nation\NationProfileService;
use App\Services\Nation\NationRoleService;
use App\Services\Nation\NationRulerTransferService;
use App\Services\Nation\NationService;
use App\Services\Nation\NationWarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class NationCommunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_cooldown_label_rounds_up_without_float_deprecation(): void
    {
        $this->travelTo(now()->startOfMinute());
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== E_DEPRECATED) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $label = app(NationMembershipCooldownService::class)
                ->remainingLabel(now()->addHours(72)->subMicrosecond());
        } finally {
            restore_error_handler();
            $this->travelBack();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame('3日', $label);
    }

    public function test_emblem_catalog_exposes_all_80_numbered_webp_files(): void
    {
        $catalog = app(NationEmblemCatalog::class);
        $emblems = $catalog->all();

        $this->assertCount(80, $emblems);
        $this->assertSame('nation_crest_001', array_key_first($emblems));
        $this->assertSame('nation_crest_080', array_key_last($emblems));
        $this->assertSame('images/nation/nation-crest_001.webp', $emblems['nation_crest_001']['path']);
        $this->assertSame('images/nation/nation-crest_080.webp', $emblems['nation_crest_080']['path']);

        foreach ($emblems as $key => $emblem) {
            $path = public_path($emblem['path']);
            $this->assertLessThanOrEqual(32, strlen($key));
            $this->assertFileExists($path);
            $this->assertSame([128, 128], array_slice(getimagesize($path), 0, 2));
        }

        $this->assertSame($emblems['nation_crest_001'], $catalog->get('green_castle'));
        $this->assertSame($emblems['nation_crest_002'], $catalog->get('blue_shield'));
    }

    public function test_header_background_catalog_exposes_all_20_numbered_webp_files(): void
    {
        $catalog = app(NationHeaderBackgroundCatalog::class);
        $backgrounds = $catalog->all();

        $this->assertCount(20, $backgrounds);
        $this->assertSame('nation_header_bg_001', array_key_first($backgrounds));
        $this->assertSame('nation_header_bg_020', array_key_last($backgrounds));
        $this->assertSame('images/nation/bg/nation-header-bg_001.webp', $backgrounds['nation_header_bg_001']['path']);
        $this->assertSame('images/nation/bg/nation-header-bg_020.webp', $backgrounds['nation_header_bg_020']['path']);

        foreach ($backgrounds as $key => $background) {
            $path = public_path($background['path']);
            $this->assertLessThanOrEqual(32, strlen($key));
            $this->assertFileExists($path);
            $this->assertSame([600, 232], array_slice(getimagesize($path), 0, 2));
        }

        $this->assertSame($backgrounds['nation_header_bg_001'], $catalog->get('invalid-background'));
    }

    public function test_upgrade_migration_normalizes_known_suffix_and_backfills_king_to_ruler(): void
    {
        $character = $this->character('既存統治者');
        $migration = require database_path('migrations/2026_08_24_090000_create_nation_community_foundation.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('nation_join_applications'));

        $nationId = DB::table('nations')->insertGetId([
            'name' => '黎明帝国',
            'founded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('nation_memberships')->insert([
            'nation_id' => $nationId,
            'character_id' => $character->id,
            'role' => 'king',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseHas('nations', ['id' => $nationId, 'name' => '黎明', 'nation_type' => 'empire']);
        $this->assertDatabaseHas('nation_memberships', ['nation_id' => $nationId, 'role' => 'ruler']);
        $this->assertTrue(Schema::hasTable('nation_join_applications'));
        $this->assertTrue(Schema::hasTable('nation_membership_cooldowns'));
        $this->assertTrue(Schema::hasTable('nation_activity_logs'));
        $this->assertDatabaseHas('game_settings', ['setting_key' => 'nation.max_members', 'value' => '100']);
        $this->assertTrue(collect(DB::select("PRAGMA foreign_key_list('nations')"))->contains(
            fn (object $foreignKey): bool => $foreignKey->from === 'dissolution_requested_by_character_id'
                && $foreignKey->table === 'characters'
                && strtoupper((string) $foreignKey->on_delete) === 'SET NULL',
        ));
    }

    public function test_upgrade_preflight_keeps_legacy_schema_untouched_on_normalized_name_collision(): void
    {
        $firstRuler = $this->character('既存第一統治者');
        $secondRuler = $this->character('既存第二統治者');
        $migration = require database_path('migrations/2026_08_24_090000_create_nation_community_foundation.php');
        $migration->down();

        foreach ([
            ['name' => '黎明王国', 'character_id' => $firstRuler->id],
            ['name' => '黎明帝国', 'character_id' => $secondRuler->id],
        ] as $legacy) {
            $nationId = DB::table('nations')->insertGetId([
                'name' => $legacy['name'],
                'founded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('nation_memberships')->insert([
                'nation_id' => $nationId,
                'character_id' => $legacy['character_id'],
                'role' => 'king',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $migration->up();
            $this->fail('国号除去後に重複する既存国家名をmigrationが受け入れました。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('基礎国家名が重複します', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('nations', 'nation_type'));
        $this->assertFalse(Schema::hasTable('nation_join_applications'));
        $this->assertFalse(Schema::hasTable('nation_membership_cooldowns'));
        $this->assertFalse(Schema::hasTable('nation_activity_logs'));

        DB::table('nation_memberships')->delete();
        DB::table('nations')->delete();
        $migration->up();
    }

    public function test_upgrade_rerun_restores_an_index_after_partial_ddl(): void
    {
        $migration = require database_path('migrations/2026_08_24_090000_create_nation_community_foundation.php');

        Schema::table('nation_membership_cooldowns', function (Blueprint $table): void {
            $table->dropIndex('nation_membership_cooldowns_ruler_refound_blocked_until_index');
        });
        $this->assertFalse(Schema::hasIndex('nation_membership_cooldowns', ['ruler_refound_blocked_until']));

        $migration->up();

        $this->assertTrue(Schema::hasIndex('nation_membership_cooldowns', ['ruler_refound_blocked_until']));
    }

    public function test_all_five_nation_types_create_one_ruler_and_five_facilities(): void
    {
        $expected = [
            'kingdom' => ['王国', '国王'],
            'empire' => ['帝国', '皇帝'],
            'duchy' => ['公国', '大公'],
            'republic' => ['共和国', '執政官'],
            'knight_state' => ['騎士国', '騎士団長'],
        ];

        foreach ($expected as $type => [$suffix, $title]) {
            $baseName = '試験'.array_search($type, NationType::values(), true);
            $nation = app(NationService::class)->create($this->character($title), $baseName, null, $type);

            $this->assertSame($baseName.$suffix, $nation->display_name);
            $this->assertSame($title, $nation->ruler_title);
            $this->assertTrue((bool) $nation->recruitment_enabled);
            $this->assertSame(1, $nation->memberships()->where('role', 'ruler')->count());
            $this->assertSame(5, $nation->facilities()->count());
            $this->assertEqualsCanonicalizing(NationFacility::TYPES, $nation->facilities()->pluck('facility_type')->all());
        }
    }

    public function test_founding_rejects_duplicate_base_name_members_and_pending_applicants(): void
    {
        app(NationService::class)->create($this->character('先の建国者'), '白銀', null, 'kingdom');
        $this->assertDomainFailure(
            fn () => app(NationService::class)->create($this->character('後の建国者'), '白銀', null, 'empire'),
            'すでに使われています',
        );

        $member = $this->character('所属済み');
        app(NationService::class)->create($member, '所属国');
        $this->assertDomainFailure(fn () => app(NationService::class)->create($member, '二重国'), 'すでに国家へ所属');

        $applicant = $this->character('申請中');
        $target = app(NationService::class)->create($this->character('申請先統治者'), '申請先');
        app(NationJoinApplicationService::class)->submit($applicant, $target);
        $this->assertDomainFailure(fn () => app(NationService::class)->create($applicant, '申請中建国'), '加入申請中');
    }

    public function test_founding_name_unique_race_returns_domain_message_without_partial_rows(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite fault injection only; MariaDB concurrency is covered by the release gate.');
        }

        $founder = $this->character('競合建国者');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER simulate_nation_name_unique_race
BEFORE INSERT ON nations
WHEN NEW.name = '競合国'
BEGIN
    SELECT RAISE(ABORT, 'UNIQUE constraint failed: nations.name');
END
SQL);

        try {
            $this->assertDomainFailure(
                fn () => app(NationService::class)->create($founder, '競合国'),
                'その国家名はすでに使われています。',
            );
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS simulate_nation_name_unique_race');
        }

        $this->assertDatabaseMissing('nations', ['name' => '競合国']);
        $this->assertDatabaseMissing('nation_memberships', ['character_id' => $founder->id]);
        $this->assertSame(0, NationFacility::count());
    }

    public function test_join_application_requires_recruitment_one_pending_capacity_and_retry_window(): void
    {
        $first = app(NationService::class)->create($this->character('第一統治者'), '第一');
        $second = app(NationService::class)->create($this->character('第二統治者'), '第二');
        $applicant = $this->character('申請者');
        $service = app(NationJoinApplicationService::class);

        $application = $service->submit($applicant, $first, 'よろしくお願いします');
        $this->assertSame(NationJoinApplication::STATUS_PENDING, $application->status);
        $this->assertSame('よろしくお願いします', $application->message);
        $this->assertDomainFailure(fn () => $service->submit($applicant, $second), '別の加入申請');

        $service->cancel($applicant, $application);
        $this->assertSame(NationJoinApplication::STATUS_CANCELED, $application->fresh()->status);
        $this->assertDomainFailure(fn () => $service->submit($applicant, $first), '再申請待機');
        $this->travel(25)->hours();
        $this->assertSame(NationJoinApplication::STATUS_PENDING, $service->submit($applicant, $first)->status);

        $first->update(['recruitment_enabled' => false]);
        $this->assertDomainFailure(fn () => $service->submit($this->character('募集停止申請者'), $first), '募集を停止');

        app(GameSettingService::class)->set('nation.max_members', '1');
        $this->assertDomainFailure(fn () => $service->submit($this->character('満員申請者'), $second), '定員');
    }

    public function test_join_application_notifies_ruler_and_approval_notifies_applicant(): void
    {
        $ruler = $this->character('通知統治者');
        $nation = app(NationService::class)->create($ruler, '通知');
        $applicant = $this->character('通知申請者');
        $service = app(NationJoinApplicationService::class);

        $application = $service->submit($applicant, $nation, '加入を希望します。');

        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $ruler->id,
            'category' => 'nation',
            'type' => 'nation_join_application_submitted',
            'title' => '【国家】加入申請が届きました',
            'body' => "{$applicant->name}さんから{$nation->display_name}への加入申請が届きました。\n一言：加入を希望します。",
            'read_at' => null,
        ]);
        $this->assertSame(1, app(CharacterNotificationService::class)->unreadCount($ruler));
        $rulerNotification = DB::table('character_notifications')
            ->where('character_id', $ruler->id)
            ->where('type', 'nation_join_application_submitted')
            ->sole();
        $this->assertSame('加入申請を見る', $rulerNotification->action_label);
        $this->assertSame(route('nation.applications'), $rulerNotification->url);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $applicant->id,
            'type' => 'nation_join_application_approved',
        ]);

        $service->approve($ruler, $application);

        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $applicant->id,
            'category' => 'nation',
            'type' => 'nation_join_application_approved',
            'title' => '【国家】加入申請が承認されました',
            'body' => "{$nation->display_name}への加入申請が承認され、国民になりました。",
            'read_at' => null,
        ]);
        $this->assertSame(1, app(CharacterNotificationService::class)->unreadCount($applicant));

        $this->assertDomainFailure(fn () => $service->approve($ruler, $application), 'すでに処理');
        $this->assertSame(1, DB::table('character_notifications')
            ->where('character_id', $applicant->id)
            ->where('type', 'nation_join_application_approved')
            ->count());
    }

    public function test_notification_destination_opens_the_ruler_join_applications_screen(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('通知遷移統治者');
        $nation = app(NationService::class)->create($ruler, '通知遷移');
        $applicant = $this->character('通知遷移申請者');
        app(NationJoinApplicationService::class)->submit($applicant, $nation, '確認をお願いします。');
        $notification = CharacterNotification::query()
            ->where('character_id', $ruler->id)
            ->where('type', 'nation_join_application_submitted')
            ->sole();
        $notification->forceFill(['url' => null])->save();

        session(['current_character_id' => $ruler->id]);
        $this->actingAs($ruler->user);

        Livewire::test(CityHeader::class, ['modalOnly' => true])
            ->call('openNotification', $notification->id)
            ->assertRedirect(route('nation.applications'));
        $this->assertNotNull($notification->fresh()->read_at);

        $this->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('nation.applications'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('current_location', 'nation')
            ->assertSessionHas('nation_initial_page', 'applications');

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'nation')
            ->assertSeeHtml('wire:name="nation-screen"')
            ->assertSeeHtml('data-nation-applications')
            ->assertSee($applicant->name);
        $this->assertNull(session('nation_initial_page'));
    }

    public function test_only_ruler_can_reject_and_rejection_blocks_same_nation_for_24_hours(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '審査国');
        $citizen = $this->character('一般国民');
        NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'citizen', 'joined_at' => now()->subDays(2)]);
        $applicant = $this->character('申請者');
        $service = app(NationJoinApplicationService::class);
        $application = $service->submit($applicant, $nation);

        $this->assertDomainFailure(fn () => $service->reject($citizen, $application), '役職権限');
        $service->reject($ruler, $application);
        $this->assertSame(NationJoinApplication::STATUS_REJECTED, $application->fresh()->status);
        $this->assertNull(NationMembership::where('character_id', $applicant->id)->first());
        $this->assertTrue($application->fresh()->retry_after->isFuture());
        $this->assertDomainFailure(fn () => $service->submit($applicant, $nation), '再申請待機');
    }

    public function test_approval_rechecks_membership_capacity_cooldown_and_is_double_submit_safe(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '承認国');
        $applicant = $this->character('承認対象');
        $service = app(NationJoinApplicationService::class);
        $application = $service->submit($applicant, $nation);
        $reviewer = $this->character('承認権限なし');
        $reviewerMembership = NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $reviewer->id,
            'role' => 'citizen',
            'joined_at' => now()->subDays(2),
        ]);
        $this->assertDomainFailure(fn () => $service->approve($reviewer, $application), '役職権限');
        $reviewerMembership->delete();
        NationMembershipCooldown::create([
            'character_id' => $applicant->id,
            'global_join_blocked_until' => now()->addDay(),
            'reason' => 'left',
        ]);
        $this->assertDomainFailure(fn () => $service->approve($ruler, $application), '待機期間');
        NationMembershipCooldown::where('character_id', $applicant->id)->delete();

        $membership = $service->approve($ruler, $application);
        $this->assertSame('citizen', $membership->role);
        $this->assertSame(NationJoinApplication::STATUS_APPROVED, $application->fresh()->status);
        $this->assertSame(1, NationMembership::where('character_id', $applicant->id)->count());
        $this->assertDomainFailure(fn () => $service->approve($ruler, $application), 'すでに処理');
        $this->assertSame(1, NationMembership::where('character_id', $applicant->id)->count());

        $capacityApplicant = $this->character('定員再確認対象');
        app(GameSettingService::class)->set('nation.max_members', '3');
        $capacityApplication = $service->submit($capacityApplicant, $nation);
        NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $this->character('定員到達国民')->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $this->assertDomainFailure(fn () => $service->approve($ruler, $capacityApplication), '定員');

        app(GameSettingService::class)->set('nation.max_members', '20');
        $memberElsewhere = $this->character('承認前に他国所属');
        $otherApplication = $service->submit($memberElsewhere, $nation);
        $otherNation = app(NationService::class)->create($this->character('別国統治者'), '別所属国');
        NationMembership::create([
            'nation_id' => $otherNation->id,
            'character_id' => $memberElsewhere->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $this->assertDomainFailure(fn () => $service->approve($ruler, $otherApplication), 'すでに国家へ所属');
    }

    public function test_minimum_stay_ruler_and_war_participant_rules_protect_voluntary_leave(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '脱退国');
        $citizen = $this->character('一般国民');
        $membership = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'citizen', 'joined_at' => now()]);
        $service = app(NationMembershipService::class);

        $this->assertDomainFailure(fn () => $service->leave($ruler), '統治者');
        $this->assertDomainFailure(fn () => $service->leave($citizen), '加入から');
        $membership->update(['joined_at' => now()->subHours(25)]);

        $enemy = app(NationService::class)->create($this->character('相手統治者'), '相手国');
        $war = $this->war($nation, $enemy, 'preparing');
        NationWarParticipant::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'character_id' => $citizen->id, 'frozen_at' => now()]);
        $this->assertDomainFailure(fn () => $service->leave($citizen), '終戦まで脱退');

        $newcomer = $this->character('戦争開始後の新規国民');
        NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $newcomer->id,
            'role' => 'citizen',
            'joined_at' => now()->subHours(25),
        ]);
        $service->leave($newcomer);
        $this->assertNull(NationMembership::where('character_id', $newcomer->id)->first());

        $war->update(['status' => 'resolved', 'resolved_at' => now()]);
        $service->leave($citizen);
        $this->assertNull(NationMembership::where('character_id', $citizen->id)->first());
        $this->assertTrue(NationMembershipCooldown::where('character_id', $citizen->id)->firstOrFail()->global_join_blocked_until->isFuture());
    }

    public function test_only_ruler_can_expel_non_ruler_and_expulsion_applies_both_cooldowns(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '追放国');
        $citizen = $this->character('追放対象');
        $target = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'chancellor', 'joined_at' => now()->subDays(2)]);
        $other = $this->character('権限なし');
        $otherMembership = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $other->id, 'role' => 'citizen', 'joined_at' => now()->subDays(2)]);
        $service = app(NationMembershipService::class);

        $this->assertDomainFailure(fn () => $service->expel($otherMembership, $target), '役職権限');
        $this->assertDomainFailure(fn () => $service->expel(NationMembership::where('character_id', $ruler->id)->firstOrFail(), NationMembership::where('character_id', $ruler->id)->firstOrFail()), '統治者を追放');
        $service->expel(NationMembership::where('character_id', $ruler->id)->firstOrFail(), $target);

        $this->assertNull(NationMembership::where('character_id', $citizen->id)->first());
        $cooldown = NationMembershipCooldown::where('character_id', $citizen->id)->firstOrFail();
        $this->assertTrue($cooldown->global_join_blocked_until->isFuture());
        $this->assertSame($nation->id, $cooldown->same_nation_id);
        $this->assertGreaterThan(6, now()->diffInDays($cooldown->same_nation_blocked_until));
    }

    public function test_war_participant_cannot_be_expelled_until_war_ends(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '戦時追放国');
        $citizen = $this->character('戦時国民');
        $target = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'citizen', 'joined_at' => now()->subDays(2)]);
        $enemy = app(NationService::class)->create($this->character('敵統治者'), '敵国');
        $war = $this->war($nation, $enemy, 'active');
        NationWarParticipant::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'character_id' => $citizen->id, 'frozen_at' => now()]);

        $this->assertDomainFailure(
            fn () => app(NationMembershipService::class)->expel(NationMembership::where('character_id', $ruler->id)->firstOrFail(), $target),
            '終戦まで追放',
        );
    }

    public function test_role_changes_are_ruler_only_and_cannot_assign_or_remove_ruler_normally(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '役職国');
        $citizen = $this->character('役職対象');
        $target = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'citizen', 'joined_at' => now()]);
        $actor = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $service = app(NationMembershipService::class);

        $service->changeRole($actor, $target, 'marshal');
        $this->assertSame('marshal', $target->fresh()->role);
        $service->changeRole($actor, $target, 'citizen');
        $this->assertSame('citizen', $target->fresh()->role);
        $this->assertDomainFailure(fn () => $service->changeRole($actor, $target, 'ruler'), '指定された役職');
        $this->assertDomainFailure(fn () => $service->changeRole($actor, $actor, 'citizen'), '統治者の交代');
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'role_assigned']);
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'role_removed']);
    }

    public function test_ruler_migration_keeps_existing_war_role_permissions_but_community_management_is_ruler_only(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '権限国');
        $chancellorCharacter = $this->character('宰相');
        $chancellor = NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $chancellorCharacter->id,
            'role' => 'chancellor',
            'joined_at' => now(),
        ]);
        $rulerMembership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $roles = app(NationRoleService::class);

        $this->assertTrue($roles->allows($rulerMembership, 'manage_members'));
        $this->assertTrue($roles->allows($rulerMembership, 'declare_war'));
        $this->assertTrue($roles->allows($chancellor, 'declare_war'));
        $this->assertTrue($roles->allows($chancellor, 'allocate_war_resources'));
        $this->assertFalse($roles->allows($chancellor, 'manage_members'));
        $this->assertFalse($roles->allows($chancellor, 'manage_roles'));
    }

    public function test_profile_updates_are_audited_and_recruitment_off_does_not_block_existing_review(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '紹介国');
        $applicant = $this->character('既存申請者');
        $application = app(NationJoinApplicationService::class)->submit($applicant, $nation);
        $actor = NationMembership::where('character_id', $ruler->id)->firstOrFail();

        app(NationProfileService::class)->update($actor, '新しい紹介', false, '募集停止中', 'nation_crest_080');
        $nation->refresh();
        $this->assertSame('新しい紹介', $nation->description);
        $this->assertFalse($nation->recruitment_enabled);
        $this->assertSame('nation_crest_080', $nation->emblem_key);
        $this->assertDomainFailure(fn () => app(NationJoinApplicationService::class)->submit($this->character('新規申請者'), $nation), '募集を停止');

        app(NationJoinApplicationService::class)->approve($ruler, $application);
        $this->assertNotNull(NationMembership::where('character_id', $applicant->id)->first());
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'description_changed']);
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'recruitment_disabled']);
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'emblem_changed']);
    }

    public function test_only_ruler_can_update_the_header_background_and_the_change_is_audited(): void
    {
        $ruler = $this->character('背景変更統治者');
        $nation = app(NationService::class)->create($ruler, '背景変更国');
        $citizenCharacter = $this->character('背景変更国民');
        $citizen = NationMembership::create([
            'nation_id' => $nation->id,
            'character_id' => $citizenCharacter->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ]);
        $rulerMembership = NationMembership::where('character_id', $ruler->id)->firstOrFail();
        $profiles = app(NationProfileService::class);

        $this->assertSame('nation_header_bg_001', $nation->fresh()->header_background_key);
        $this->assertDomainFailure(
            fn () => $profiles->updateHeaderBackground($citizen, 'nation_header_bg_020'),
            '役職権限',
        );
        $this->assertDomainFailure(
            fn () => $profiles->updateHeaderBackground($rulerMembership, 'invalid-background'),
            '使用できません',
        );

        $profiles->updateHeaderBackground($rulerMembership, 'nation_header_bg_020');

        $this->assertSame('nation_header_bg_020', $nation->fresh()->header_background_key);
        $this->assertDatabaseHas('nation_activity_logs', [
            'nation_id' => $nation->id,
            'actor_character_id' => $ruler->id,
            'event_type' => 'header_background_changed',
        ]);
    }

    public function test_ruler_transfer_is_atomic_same_nation_only_and_keeps_exactly_one_ruler(): void
    {
        $ruler = $this->character('旧統治者');
        $nation = app(NationService::class)->create($ruler, '譲渡国', null, 'empire');
        $successor = $this->character('新統治者');
        $target = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $successor->id, 'role' => 'marshal', 'joined_at' => now()]);
        $outsiderNation = app(NationService::class)->create($this->character('外部統治者'), '外部国');
        $outsider = NationMembership::where('nation_id', $outsiderNation->id)->firstOrFail();
        $service = app(NationRulerTransferService::class);

        $this->assertDomainFailure(fn () => $service->transfer($ruler, $outsider), '同じ国家');
        $service->transfer($ruler, $target);
        $this->assertSame('citizen', NationMembership::where('character_id', $ruler->id)->value('role'));
        $this->assertSame('ruler', NationMembership::where('character_id', $successor->id)->value('role'));
        $this->assertSame(1, NationMembership::where('nation_id', $nation->id)->where('role', 'ruler')->count());
        $this->assertDomainFailure(fn () => $service->transfer($ruler, $target), '役職権限');
    }

    public function test_dissolution_can_be_canceled_then_logically_disbands_without_member_leave_cooldown(): void
    {
        $ruler = $this->character('解散統治者');
        $nation = app(NationService::class)->create($ruler, '解散国', null, 'republic');
        $citizen = $this->character('解散国民');
        $citizenMembership = NationMembership::create(['nation_id' => $nation->id, 'character_id' => $citizen->id, 'role' => 'citizen', 'joined_at' => now()->subDays(2)]);
        $applicant = $this->character('解散時申請者');
        $application = app(NationJoinApplicationService::class)->submit($applicant, $nation);
        $service = app(NationDissolutionService::class);

        $this->assertDomainFailure(fn () => $service->request($citizen, '解散国共和国'), '役職権限');
        $service->request($ruler, '解散国共和国');
        $this->assertSame(Nation::STATUS_DISBAND_PENDING, $nation->fresh()->status);
        $this->assertFalse($nation->fresh()->recruitment_enabled);
        $this->assertSame(NationJoinApplication::STATUS_CANCELED, $application->fresh()->status);
        $leaveEligibility = app(NationMembershipService::class)->leaveEligibility($citizenMembership->fresh());
        $this->assertFalse($leaveEligibility['allowed']);
        $this->assertStringContainsString('解散完了時に待機時間なしで自動的に無所属', $leaveEligibility['reason']);
        $this->assertDomainFailure(
            fn () => app(NationMembershipService::class)->leave($citizen),
            '解散完了時に待機時間なしで自動的に無所属',
        );
        $this->assertDatabaseHas('nation_memberships', ['id' => $citizenMembership->id]);
        $this->assertDatabaseMissing('nation_membership_cooldowns', ['character_id' => $citizen->id]);
        $this->actingAs($citizen->user);
        Livewire::test(NationScreen::class)
            ->assertSee('一般国民は解散完了時に、加入待機時間なしで自動的に無所属になります。')
            ->assertSee('国家解散の待機中は自主脱退できません。');
        $service->cancel($ruler);
        $this->assertSame(Nation::STATUS_ACTIVE, $nation->fresh()->status);
        $this->assertTrue($nation->fresh()->recruitment_enabled);
        $this->assertTrue(app(NationMembershipService::class)->leaveEligibility($citizenMembership->fresh())['allowed']);

        $service->request($ruler, '解散国共和国');
        $this->travel(25)->hours();
        $this->assertSame(1, $service->processDue());
        $this->assertSame(Nation::STATUS_DISBANDED, $nation->fresh()->status);
        $this->assertNotNull($nation->fresh()->disbanded_at);
        $this->assertSame(0, NationMembership::where('nation_id', $nation->id)->count());
        $this->assertNull(NationMembershipCooldown::where('character_id', $citizen->id)->value('global_join_blocked_until'));
        $this->assertTrue(NationMembershipCooldown::where('character_id', $ruler->id)->firstOrFail()->ruler_refound_blocked_until->isFuture());
        $this->assertDomainFailure(fn () => app(NationService::class)->create($ruler, '再建国'), '再建国待機');
        $this->assertDatabaseHas('nations', ['id' => $nation->id, 'status' => Nation::STATUS_DISBANDED]);
        $this->assertDatabaseHas('nation_activity_logs', ['nation_id' => $nation->id, 'event_type' => 'nation_disbanded']);
    }

    public function test_live_or_reserved_war_blocks_dissolution_and_pending_nation_cannot_declare(): void
    {
        $ruler = $this->character('統治者');
        $nation = app(NationService::class)->create($ruler, '戦争国');
        $enemy = app(NationService::class)->create($this->character('敵統治者'), '戦争敵国');
        $war = $this->war($nation, $enemy, 'reserved');
        $service = app(NationDissolutionService::class);

        $this->assertDomainFailure(fn () => $service->request($ruler, $nation->display_name), '国家戦');
        $war->update(['status' => 'resolved', 'resolved_at' => now()]);
        $service->request($ruler, $nation->display_name);
        $this->assertSame(Nation::STATUS_DISBAND_PENDING, $nation->fresh()->status);

        config()->set('features.nation_war_enabled', true);
        app(GameSettingService::class)->set('nation_war.declaration_enabled', '1');
        app(GameSettingService::class)->set('nation_war.reference_damage', '1000');
        $nation->update(['founded_at' => now()->subDays(8)]);
        $enemy->update(['founded_at' => now()->subDays(8)]);
        $this->assertDomainFailure(
            fn () => app(NationWarService::class)->declare(
                NationMembership::where('character_id', $ruler->id)->firstOrFail(),
                $enemy,
            ),
            '解散手続き中',
        );
    }

    public function test_livewire_supports_founding_application_approval_and_coming_soon_without_backend_write(): void
    {
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_development_enabled', false);
        $ruler = $this->character('画面統治者');
        $this->actingAs($ruler->user);

        $nationScreen = Livewire::test(NationScreen::class)
            ->assertSee('images/icon/icon_306.webp', false)
            ->call('showCreate')
            ->assertSet('showFoundingEmblemModal', false)
            ->assertSee('紋章を選ぶ')
            ->assertDontSee('全80種から選べます')
            ->call('openFoundingEmblemModal')
            ->assertSet('showFoundingEmblemModal', true)
            ->assertSee('全80種から選べます')
            ->assertSee('No.080')
            ->call('selectFoundingEmblem', 'invalid-emblem')
            ->assertHasErrors(['foundingEmblemKey'])
            ->assertSet('showFoundingEmblemModal', true)
            ->call('selectFoundingEmblem', 'nation_crest_080')
            ->assertSet('showFoundingEmblemModal', false)
            ->assertSet('foundingEmblemKey', 'nation_crest_080')
            ->assertHasNoErrors('foundingEmblemKey')
            ->call('openFoundingConfirmation')
            ->assertHasErrors(['foundingName'])
            ->assertSet('showFoundingConfirmationModal', false)
            ->set('foundingName', '画面国')
            ->set('foundingNationType', 'duchy')
            ->set('foundingDescription', str_repeat('国', 201))
            ->call('openFoundingConfirmation')
            ->assertHasErrors(['foundingDescription'])
            ->assertSet('showFoundingConfirmationModal', false)
            ->set('foundingDescription', '画面から建国しました。')
            ->call('openFoundingConfirmation')
            ->assertHasNoErrors()
            ->assertSet('showFoundingConfirmationModal', true)
            ->assertSee('この内容で建国しますか？')
            ->assertSee('画面国公国')
            ->assertSee('画面から建国しました。')
            ->call('closeFoundingConfirmation')
            ->assertSet('showFoundingConfirmationModal', false);
        $nationScreen->call('createNation');
        $this->assertDatabaseMissing('nations', ['name' => '画面国']);
        $nationScreen
            ->call('openFoundingConfirmation')
            ->call('createNation')
            ->assertHasNoErrors()
            ->assertSet('showFoundingConfirmationModal', false)
            ->assertSee('画面国公国')
            ->assertSee('国民数')
            ->assertSee('1 / 20人')
            ->assertSee('統治者メニュー')
            ->assertSee('届いた加入申請を確認・審査する')
            ->assertSee('国民の役職変更や追放を行う')
            ->assertSee('国家戦に備える資材を確認・管理する')
            ->assertSee('data-nation-ruler-menu', false)
            ->assertSee('data-nation-upcoming-menu', false);
        $nationScreen
            ->call('showMemberManagement')
            ->assertSee('現在、役職による権限変更は未実装です。')
            ->assertSee('この画面で実際に利用できる管理操作は追放です。')
            ->call('showHome');
        $nation = Nation::where('name', '画面国')->firstOrFail();
        $this->assertSame('nation_crest_080', $nation->emblem_key);

        $nationScreen
            ->assertSeeHtml('data-nation-home-header')
            ->assertSeeHtml('data-nation-nameplate')
            ->assertSeeHtml('data-nation-header-layout="crest-details"')
            ->assertSeeHtml('data-nation-header-emblem')
            ->assertSeeHtml('data-nation-header-ruler')
            ->assertSeeHtml('data-nation-header-level-badge')
            ->assertSeeHtml('data-nation-header-member-summary')
            ->assertSeeHtml('data-nation-header-capacity')
            ->assertSeeHtml('data-nation-header-recruitment')
            ->assertDontSee('min-h-[330px]', false)
            ->assertSee('images/nation/bg/nation-header-bg_001.webp', false)
            ->assertSeeHtml('data-nation-header-background-open')
            ->call('openHeaderBackgroundModal')
            ->assertSet('showHeaderBackgroundModal', true)
            ->assertSeeHtml('data-nation-header-background-modal')
            ->assertSee('全20種から選べます')
            ->assertSee('背景 No.020')
            ->call('selectHeaderBackground', 'invalid-background')
            ->assertHasErrors(['profileHeaderBackgroundKey'])
            ->assertSet('showHeaderBackgroundModal', true)
            ->call('selectHeaderBackground', 'nation_header_bg_020')
            ->assertSet('profileHeaderBackgroundKey', 'nation_header_bg_020')
            ->assertHasNoErrors('profileHeaderBackgroundKey')
            ->call('saveHeaderBackground')
            ->assertSet('showHeaderBackgroundModal', false)
            ->assertHasNoErrors()
            ->assertSee('国家ヘッダ背景を更新しました。')
            ->assertSee('images/nation/bg/nation-header-bg_020.webp', false);
        $this->assertSame('nation_header_bg_020', $nation->fresh()->header_background_key);

        $applicant = $this->character('画面申請者');
        $this->actingAs($applicant->user);
        Livewire::test(NationScreen::class)
            ->call('showNationDetail', $nation->id)
            ->assertSeeHtml('data-nation-detail-header')
            ->assertSeeHtml('data-nation-header-layout="crest-details"')
            ->assertSeeHtml('data-nation-header-emblem')
            ->assertSeeHtml('data-nation-nameplate')
            ->assertSeeHtml('data-nation-header-ruler')
            ->assertSeeHtml('data-nation-header-level-badge')
            ->assertSeeHtml('data-nation-header-member-summary')
            ->assertSeeHtml('data-nation-header-capacity')
            ->assertSeeHtml('data-nation-header-recruitment')
            ->assertSee('images/nation/bg/nation-header-bg_020.webp', false)
            ->assertDontSeeHtml('data-nation-header-background-open')
            ->assertSeeHtml('data-nation-detail')
            ->assertSee('国家紹介')
            ->set('joinMessage', '参加希望です。')
            ->call('submitJoinApplication')
            ->assertHasNoErrors()
            ->assertSee('加入申請中');
        $application = NationJoinApplication::where('character_id', $applicant->id)->firstOrFail();

        $this->actingAs($ruler->user);
        $approvalScreen = Livewire::test(NationScreen::class)
            ->call('showApplications')
            ->assertSee('画面申請者')
            ->assertSeeHtml('data-nation-application-profile-link="'.$applicant->id.'"')
            ->assertSeeHtml('aria-label="画面申請者の冒険者カードを見る"')
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false)
            ->call('openApplicationApprovalConfirmation', $application->id)
            ->assertSet('confirmationAction', 'approve-application')
            ->assertSet('confirmationTargetId', $application->id)
            ->assertSeeHtml('data-nation-application-approval-confirmation')
            ->assertSee('画面申請者を国民として承認しますか？')
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $nation->id, 'character_id' => $applicant->id]);
        $approvalScreen
            ->call('confirmAction')
            ->assertSet('confirmationAction', null)
            ->assertSet('confirmationTargetId', null)
            ->assertHasNoErrors();
        $this->assertDatabaseHas('nation_memberships', ['nation_id' => $nation->id, 'character_id' => $applicant->id, 'role' => 'citizen']);

        $logCount = NationActivityLog::count();
        Livewire::test(NationScreen::class)
            ->call('showNotImplemented', 'declare-war')
            ->assertSet('pendingFeature', '宣戦布告')
            ->assertSee('この機能は現在準備中です。');
        $this->assertSame($logCount, NationActivityLog::count());
    }

    public function test_ruler_sees_only_five_recent_activity_logs_until_opening_the_history_modal(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('履歴確認統治者');
        $nation = app(NationService::class)->create($ruler, '履歴確認国');
        foreach (range(1, 8) as $number) {
            NationActivityLog::query()->create([
                'nation_id' => $nation->id,
                'actor_character_id' => $ruler->id,
                'event_type' => 'description_changed',
                'metadata' => ['sequence' => $number],
                'created_at' => now()->addSeconds($number),
            ]);
        }
        $this->actingAs($ruler->user);

        $screen = Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-activity-log-preview')
            ->assertSeeHtml('data-nation-activity-log-open')
            ->assertDontSeeHtml('data-nation-activity-log-modal');
        $this->assertSame(5, substr_count($screen->html(), 'data-nation-activity-log-preview-item'));

        $screen
            ->call('openActivityLogModal')
            ->assertSet('showActivityLogModal', true)
            ->assertSeeHtml('data-nation-activity-log-modal');
        $this->assertSame(5, substr_count($screen->html(), 'data-nation-activity-log-preview-item'));
        $this->assertSame(9, substr_count($screen->html(), 'data-nation-activity-log-modal-item'));

        $screen
            ->call('closeActivityLogModal')
            ->assertSet('showActivityLogModal', false)
            ->assertDontSeeHtml('data-nation-activity-log-modal');
    }

    public function test_nation_list_hides_home_hero_and_has_buttons_that_return_home(): void
    {
        config()->set('features.nation_community_enabled', true);
        $character = $this->character('国家一覧閲覧者');
        $this->actingAs($character->user);

        Livewire::test(NationScreen::class)
            ->assertDontSeeHtml('data-nation-view-tabs')
            ->call('showNationList')
            ->assertSet('page', 'nation-list')
            ->assertDontSeeHtml('data-nation-home-hero')
            ->assertDontSeeHtml('data-nation-about')
            ->assertDontSeeHtml('data-nation-showcase-all-button')
            ->assertSeeHtml('data-nation-list-home-button')
            ->assertSee('国家トップへ戻る')
            ->call('showHome')
            ->assertSet('page', 'home')
            ->assertSeeHtml('data-nation-home-hero')
            ->assertSeeHtml('data-nation-about')
            ->assertSeeHtml('data-nation-showcase-all-button')
            ->assertSee('国家は、冒険者同士で協力して活動するためのコミュニティです。')
            ->assertSee('国家への納品・物資の共有')
            ->assertSee('国家同士の戦いへの参加')
            ->assertSee('国家の発展・共同目標')
            ->assertSee('国家戦績・ランキング')
            ->assertSee('仲間と協力する新たな遊び')
            ->assertSee('国家への所属は必須ではなく、無所属でもこれまで通り冒険を楽しめます。')
            ->assertSee('一部の機能は現在準備中です。')
            ->assertDontSeeHtml('data-nation-list-home-button');
    }

    public function test_member_can_browse_other_nations_without_join_application_controls(): void
    {
        config()->set('features.nation_community_enabled', true);
        $member = $this->character('所属中閲覧者');
        $ownNation = app(NationService::class)->create($member, '所属国');
        $otherRuler = $this->character('他国統治者');
        $otherNation = app(NationService::class)->create($otherRuler, '公開国');
        $otherNation->update([
            'description' => '所属者にも公開する国家紹介',
            'recruitment_enabled' => true,
            'recruitment_message' => '公開中の募集文',
        ]);
        $this->actingAs($member->user);

        $screen = Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-view-tabs')
            ->assertSeeHtml('data-nation-view-tab="self"')
            ->assertSeeHtml('data-nation-view-tab="other"')
            ->assertSeeHtml('data-nation-active-view="self"')
            ->assertSee('自国')
            ->assertSee('他国')
            ->assertDontSeeHtml('data-nation-member-browse-button')
            ->call('showNationList')
            ->assertSet('page', 'nation-list')
            ->assertHasNoErrors('nationAction')
            ->assertSeeHtml('data-nation-active-view="other"')
            ->assertDontSeeHtml('data-nation-list-home-button')
            ->assertSee($otherNation->display_name)
            ->call('showNationDetail', $otherNation->id)
            ->assertSet('page', 'detail')
            ->assertSeeHtml('data-nation-active-view="other"')
            ->assertSeeHtml('data-nation-detail')
            ->assertSee($otherNation->display_name)
            ->assertSee('所属者にも公開する国家紹介')
            ->assertSee('公開中の募集文')
            ->assertSee('他国統治者')
            ->assertDontSee('加入申請を送る')
            ->assertDontSee('加入申請できません');

        $screen
            ->call('submitJoinApplication')
            ->assertHasErrors('nationAction');
        $this->assertDatabaseMissing('nation_join_applications', [
            'character_id' => $member->id,
            'nation_id' => $otherNation->id,
        ]);

        $screen
            ->call('showHome')
            ->assertSet('page', 'home')
            ->assertSeeHtml('data-nation-active-view="self"')
            ->assertSeeHtml('data-nation-home-header')
            ->assertSee($ownNation->display_name);
    }

    public function test_nation_member_preview_uses_three_column_icons_and_opens_all_members_modal(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('一覧国王');
        $nation = app(NationService::class)->create($ruler, '一覧確認');
        $citizenIds = [];

        for ($number = 1; $number <= 9; $number++) {
            $citizen = $this->character(sprintf('一覧国民%02d', $number));
            $citizenIds[$number] = $citizen->id;
            $citizen->update(['icon_path' => sprintf('/images/chara/chara_%03d.webp', $number + 1)]);
            NationMembership::create([
                'nation_id' => $nation->id,
                'character_id' => $citizen->id,
                'role' => 'citizen',
                'joined_at' => now()->addMinutes($number),
            ]);
        }

        $this->actingAs($ruler->user);
        $screen = Livewire::test(NationScreen::class)
            ->assertSet('showMemberListModal', false)
            ->assertSet('memberSort', 'ruler_joined')
            ->assertSeeHtml('data-nation-member-grid')
            ->assertSeeHtml('data-nation-member-sort')
            ->assertSeeHtml('data-nation-member-card="'.$ruler->id.'"')
            ->assertSee('一覧国民08')
            ->assertDontSee('一覧国民09')
            ->assertSee('国民をすべて見る（10人）')
            ->assertSeeHtml('data-nation-member-list-open');

        $this->assertSame(9, substr_count($screen->html(), 'data-nation-member-card='));

        $screen
            ->set('memberSort', 'joined_desc')
            ->assertDontSeeHtml('data-nation-member-card="'.$ruler->id.'"');

        $expectedNewestFirst = array_values(array_reverse($citizenIds));
        $this->assertSame($expectedNewestFirst, $this->nationMemberCardIds($screen->html()));

        $screen
            ->call('openMemberListModal')
            ->assertSet('showMemberListModal', true)
            ->assertSeeHtml('data-nation-member-list-modal')
            ->assertSeeHtml('data-nation-member-modal-sort')
            ->assertSee('一覧確認王国・全10人')
            ->assertSee('一覧国民09')
            ->assertSee("Livewire.dispatch('open-adventurer-card'", false)
            ->assertSet('memberSort', 'joined_desc');

        $allCards = $this->nationMemberCardIds($screen->html());
        $this->assertSame($expectedNewestFirst, array_slice($allCards, 0, 9));
        $this->assertSame([...$expectedNewestFirst, $ruler->id], array_slice($allCards, 9));

        $screen
            ->call('closeMemberListModal')
            ->assertSet('showMemberListModal', false)
            ->assertDontSeeHtml('data-nation-member-list-modal');

        $screen
            ->set('memberSort', 'not-supported')
            ->assertSet('memberSort', 'ruler_joined');

        $outsider = $this->character('一覧閲覧者');
        $this->actingAs($outsider->user);
        Livewire::test(NationScreen::class)
            ->call('showNationDetail', $nation->id)
            ->assertSeeHtml('data-nation-member-grid')
            ->assertSeeHtml('data-nation-member-sort')
            ->assertSee('国民をすべて見る（10人）')
            ->call('openMemberListModal')
            ->assertSet('showMemberListModal', true)
            ->assertSee('一覧国民09')
            ->call('showNationList')
            ->assertSet('showMemberListModal', false)
            ->assertDontSeeHtml('data-nation-member-list-modal');

        $nineMemberRuler = $this->character('九人国王');
        $nineMemberNation = app(NationService::class)->create($nineMemberRuler, '九人確認');
        for ($number = 1; $number <= 8; $number++) {
            NationMembership::create([
                'nation_id' => $nineMemberNation->id,
                'character_id' => $this->character(sprintf('九人国民%02d', $number))->id,
                'role' => 'citizen',
                'joined_at' => now()->addMinutes($number),
            ]);
        }

        $this->actingAs($nineMemberRuler->user);
        $nineMemberScreen = Livewire::test(NationScreen::class)
            ->assertSee('九人国民08')
            ->assertDontSeeHtml('data-nation-member-list-open')
            ->call('openMemberListModal')
            ->assertSet('showMemberListModal', false)
            ->assertDontSeeHtml('data-nation-member-list-modal');

        $this->assertSame(9, substr_count($nineMemberScreen->html(), 'data-nation-member-card='));
    }

    public function test_nation_member_list_can_be_sorted_by_level_and_name(): void
    {
        config()->set('features.nation_community_enabled', true);
        $ruler = $this->character('SortDelta');
        $ruler->update(['level' => 40]);
        $nation = app(NationService::class)->create($ruler, '並び替え確認');

        $members = collect([
            ['name' => 'SortCharlie', 'level' => 70],
            ['name' => 'SortAlpha', 'level' => 20],
            ['name' => 'SortBravo', 'level' => 50],
        ])->map(function (array $member, int $index) use ($nation): Character {
            $character = $this->character($member['name']);
            $character->update(['level' => $member['level']]);
            NationMembership::create([
                'nation_id' => $nation->id,
                'character_id' => $character->id,
                'role' => 'citizen',
                'joined_at' => now()->addMinutes($index + 1),
            ]);

            return $character;
        })->keyBy('name');

        $this->actingAs($ruler->user);
        $screen = Livewire::test(NationScreen::class);

        $screen->set('memberSort', 'level_desc');
        $this->assertSame([
            $members['SortCharlie']->id,
            $members['SortBravo']->id,
            $ruler->id,
            $members['SortAlpha']->id,
        ], $this->nationMemberCardIds($screen->html()));

        $screen->set('memberSort', 'level_asc');
        $this->assertSame([
            $members['SortAlpha']->id,
            $ruler->id,
            $members['SortBravo']->id,
            $members['SortCharlie']->id,
        ], $this->nationMemberCardIds($screen->html()));

        $screen->set('memberSort', 'name_asc');
        $this->assertSame([
            $members['SortAlpha']->id,
            $members['SortBravo']->id,
            $members['SortCharlie']->id,
            $ruler->id,
        ], $this->nationMemberCardIds($screen->html()));
    }

    public function test_nation_home_rotates_three_daily_showcases_and_full_list_keeps_every_nation_visible(): void
    {
        config()->set('features.nation_community_enabled', true);
        $this->travelTo(CarbonImmutable::create(2026, 1, 1, 12, 0, 0, 'Asia/Tokyo'));

        $viewer = $this->character('公平表示閲覧者');
        $nations = [];
        foreach (['公平一', '公平二', '公平三', '公平四', '公平五'] as $index => $nationName) {
            $nations[] = app(NationService::class)->create($this->character('公平統治者'.($index + 1)), $nationName);
        }
        $nations[0]->update(['recruitment_enabled' => false, 'prestige' => 999]);
        $nations[1]->update(['recruitment_enabled' => true, 'prestige' => 10]);
        $nations[2]->update(['recruitment_enabled' => true, 'prestige' => 20]);
        $nations[3]->update(['recruitment_enabled' => false, 'prestige' => 1000]);
        $nations[4]->update(['recruitment_enabled' => true, 'prestige' => 20]);
        $this->actingAs($viewer->user);

        Livewire::test(NationScreen::class)
            ->assertSee('国家ピックアップ')
            ->assertSee('全5国から日替わりで3国を紹介しています')
            ->assertSee('全国家を見る')
            ->assertSee('全国家（5国）を見る')
            ->assertSeeHtml('data-nation-showcase-all-button')
            ->assertSee('公平一王国')
            ->assertSee('公平二王国')
            ->assertSee('公平三王国')
            ->assertDontSee('公平四王国')
            ->assertDontSee('公平五王国');

        $this->travel(1)->days();

        $fullList = Livewire::test(NationScreen::class)
            ->assertDontSee('公平一王国')
            ->assertSee('公平二王国')
            ->assertSee('公平三王国')
            ->assertSee('公平四王国')
            ->assertDontSee('公平五王国')
            ->call('showNationList')
            ->assertSee('国家を探す')
            ->assertSee('公平一王国')
            ->assertSee('公平二王国')
            ->assertSee('公平三王国')
            ->assertSee('公平四王国')
            ->assertSee('公平五王国');
        $listHtml = $fullList->html();
        $positions = array_map(
            static fn (string $name): int|false => strpos($listHtml, $name),
            ['公平三王国', '公平五王国', '公平二王国', '公平四王国', '公平一王国'],
        );
        $sortedPositions = $positions;
        sort($sortedPositions, SORT_NUMERIC);
        $this->assertSame($sortedPositions, $positions);

        $nations[4]->update(['status' => Nation::STATUS_DISBAND_PENDING]);

        Livewire::test(NationScreen::class)
            ->assertSee('全4国から日替わりで3国を紹介しています')
            ->assertDontSee('公平五王国')
            ->call('showNationList')
            ->assertDontSee('公平五王国');
    }

    /** @return list<int> */
    private function nationMemberCardIds(string $html): array
    {
        preg_match_all('/data-nation-member-card="(\d+)"/', $html, $cards);

        return array_map('intval', $cards[1]);
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

    private function war(Nation $attacker, Nation $defender, string $status): NationWar
    {
        return NationWar::create([
            'declaring_nation_id' => $attacker->id,
            'defending_nation_id' => $defender->id,
            'status' => $status,
            'declared_at' => now(),
            'preparation_starts_at' => now(),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(8),
        ]);
    }

    private function assertDomainFailure(\Closure $action, string $messagePart): void
    {
        try {
            $action();
            $this->fail('DomainException was not thrown.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());
        }
    }
}
