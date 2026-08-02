<?php

namespace Tests\Feature;

use App\Models\AdminItemGrantLog;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\City;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Models\User;
use App\Services\AreaSevenMarkCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AreaSevenMarkCompensationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{candidate: MonsterMark, peers: array<int, MonsterMark>}> */
    private array $marksByCity = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->createAreaSevenMasters();
    }

    public function test_preview_execute_idempotency_and_rollback_are_safe(): void
    {
        $recipient = $this->createCharacter(User::factory()->create(), '補填対象', 2);
        $noGrant = $this->createCharacter(User::factory()->create(), '補填不要', 1);
        $guest = $this->createCharacter(User::factory()->create([
            'email' => 'guest_area7@example.test',
            'role' => 'guest',
        ]), 'ゲスト', 1);
        $this->createCharacter(User::factory()->create(), '最高街未設定', null);
        $admin = $this->createCharacter(User::factory()->create(['role' => 'admin']), '管理者', 10);
        $tester = $this->createCharacter(User::factory()->create([
            'email' => 'tester_area7_comp@valzeria.local',
        ]), '検証用', 10);

        $this->setPeerQuantities($recipient, 1, [1, 2, 3, 4]);
        $this->setPeerQuantities($noGrant, 1, [1, 1, 1, 1]);
        $this->setQuantity($noGrant, $this->marksByCity[1]['candidate'], 3);

        $service = app(AreaSevenMarkCompensationService::class);
        $preview = $service->preview();

        $this->assertTrue($preview['preview']);
        $this->assertSame(3, $preview['target_character_count']);
        $this->assertSame(1, $preview['ordinary_missing_highest_city_count']);
        $this->assertSame(1, $preview['excluded_admin_count']);
        $this->assertSame(1, $preview['excluded_tester_count']);
        $this->assertSame(1, $preview['recipient_count']);
        $this->assertSame(3, $preview['total_grant_quantity']);
        $this->assertSame(3, $preview['by_area'][7]['grant_quantity']);
        $this->assertDatabaseCount('admin_item_grant_logs', 0);

        $executed = $service->execute();
        $this->assertFalse($executed['preview']);
        $this->assertSame(3, $executed['processed_count']);
        $this->assertSame(0, $executed['skipped_count']);
        $this->assertSame(1, $executed['recipient_count']);
        $this->assertSame(3, $executed['total_grant_quantity']);
        $this->assertNotEmpty($executed['backup_sha256']);
        Storage::disk('local')->assertExists(
            str_replace(Storage::disk('local')->path(''), '', $executed['backup_path'])
        );

        $this->assertDatabaseHas('character_monster_marks', [
            'character_id' => $recipient->id,
            'monster_mark_id' => $this->marksByCity[1]['candidate']->id,
            'quantity' => 3,
            'unlocked_level' => 2,
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $recipient->id,
            'type' => AreaSevenMarkCompensationService::NOTIFICATION_TYPE,
        ]);
        $this->assertDatabaseCount('admin_item_grant_logs', 3);
        $this->assertDatabaseMissing('admin_item_grant_logs', [
            'character_id' => $admin->id,
            'grant_type' => AreaSevenMarkCompensationService::GRANT_TYPE,
        ]);
        $this->assertDatabaseMissing('admin_item_grant_logs', [
            'character_id' => $tester->id,
            'grant_type' => AreaSevenMarkCompensationService::GRANT_TYPE,
        ]);
        $this->assertDatabaseHas('admin_item_grant_logs', [
            'character_id' => $guest->id,
            'grant_type' => AreaSevenMarkCompensationService::GRANT_TYPE,
            'quantity' => 0,
        ]);

        $duplicate = $service->execute();
        $this->assertSame(0, $duplicate['processed_count']);
        $this->assertSame(3, $duplicate['skipped_count']);
        $this->assertSame(3, CharacterMonsterMark::query()
            ->where('character_id', $recipient->id)
            ->where('monster_mark_id', $this->marksByCity[1]['candidate']->id)
            ->value('quantity'));
        $this->assertDatabaseCount('admin_item_grant_logs', 3);

        $rollbackPreview = $service->previewRollback();
        $this->assertSame(3, $rollbackPreview['rollback_pending_count']);
        $this->assertSame(3, $rollbackPreview['total_marks_to_remove']);
        $this->assertSame([], $rollbackPreview['blockers']);

        $rolledBack = $service->rollback();
        $this->assertSame(3, $rolledBack['rolled_back_count']);
        $this->assertDatabaseMissing('character_monster_marks', [
            'character_id' => $recipient->id,
            'monster_mark_id' => $this->marksByCity[1]['candidate']->id,
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $recipient->id,
            'type' => AreaSevenMarkCompensationService::NOTIFICATION_TYPE,
        ]);
        $this->assertSame(3, AdminItemGrantLog::query()
            ->where('grant_type', AreaSevenMarkCompensationService::ROLLBACK_GRANT_TYPE)
            ->count());

        $duplicateRollback = $service->rollback();
        $this->assertSame(0, $duplicateRollback['rolled_back_count']);
        $this->assertSame(3, $duplicateRollback['skipped_count']);
    }

    public function test_command_is_dry_run_by_default_and_requires_confirmation_to_execute(): void
    {
        $character = $this->createCharacter(User::factory()->create(), 'コマンド確認', 1);
        $this->setPeerQuantities($character, 1, [2, 2, 2, 2]);

        $this->artisan('monster-marks:compensate-area7-candidates')
            ->assertSuccessful();
        $this->assertDatabaseCount('admin_item_grant_logs', 0);

        $this->artisan('monster-marks:compensate-area7-candidates', ['--execute' => true])
            ->assertFailed();
        $this->assertDatabaseCount('admin_item_grant_logs', 0);

        $this->artisan('monster-marks:compensate-area7-candidates', [
            '--execute' => true,
            '--confirmation' => 'apply-area7-mark-compensation',
        ])->assertSuccessful();
        $this->assertDatabaseHas('character_monster_marks', [
            'character_id' => $character->id,
            'monster_mark_id' => $this->marksByCity[1]['candidate']->id,
            'quantity' => 2,
        ]);
    }

    public function test_execute_stops_when_the_same_operation_lock_is_held(): void
    {
        $lock = Cache::lock(
            'compensation:'.AreaSevenMarkCompensationService::OPERATION_ID,
            1800
        );
        $this->assertTrue($lock->get());

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('同じ補填処理が実行中です。');
            app(AreaSevenMarkCompensationService::class)->execute();
        } finally {
            $lock->release();
        }
    }

    private function createAreaSevenMasters(): void
    {
        for ($cityId = 1; $cityId <= 10; $cityId++) {
            City::query()->updateOrCreate(
                ['id' => $cityId],
                ['name' => "街{$cityId}", 'sort_order' => $cityId * 10]
            );
            $areaId = $cityId * 7;
            Area::query()->create([
                'id' => $areaId,
                'city_id' => $cityId,
                'name' => "エリア{$areaId}",
                'slug' => "area-seven-{$cityId}",
                'unlock_order' => 7,
                'sort_order' => ($cityId * 100) + 70,
            ]);

            $peers = [];
            for ($index = 1; $index <= 5; $index++) {
                $candidate = $index === 5;
                $enemy = Enemy::query()->create([
                    'id' => ($cityId * 100) + $index,
                    'area_id' => $areaId,
                    'name' => $candidate ? "候補{$cityId}" : "通常{$cityId}-{$index}",
                    'is_boss' => false,
                    'role' => $candidate ? 'ボス候補' : '通常',
                    'sort_order' => $index,
                ]);
                $mark = MonsterMark::query()->create([
                    'enemy_id' => $enemy->id,
                    'mark_name' => $enemy->name.'の印',
                    'bonus_stat' => 'str',
                    'bonus_per_level' => 1,
                    'required_per_level' => 10,
                    'max_level' => 4,
                    'drop_rate' => 8,
                    'is_active' => true,
                ]);
                if ($candidate) {
                    $this->marksByCity[$cityId]['candidate'] = $mark;
                } else {
                    $peers[] = $mark;
                }
            }
            $this->marksByCity[$cityId]['peers'] = $peers;
        }
    }

    private function createCharacter(User $user, string $name, ?int $highestCityId): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'current_city_id' => $highestCityId,
            'highest_city_id' => $highestCityId,
        ]);
    }

    /** @param array<int, int> $quantities */
    private function setPeerQuantities(Character $character, int $cityId, array $quantities): void
    {
        foreach ($this->marksByCity[$cityId]['peers'] as $index => $mark) {
            $this->setQuantity($character, $mark, $quantities[$index]);
        }
    }

    private function setQuantity(Character $character, MonsterMark $mark, int $quantity): void
    {
        CharacterMonsterMark::query()->create([
            'character_id' => $character->id,
            'monster_mark_id' => $mark->id,
            'quantity' => $quantity,
            'unlocked_level' => app(\App\Services\MonsterMarkService::class)
                ->unlockedLevel($quantity, $mark),
        ]);
    }
}
