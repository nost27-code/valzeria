<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Services\BonusPointService;
use App\Services\MonsterMarkAlchemyService;
use App\Services\MonsterMarkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MonsterMarkAlchemyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-29T09:00:00+09:00'));

        $this->dropTestTables();

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('current_hp')->default(100);
            $table->unsignedInteger('current_mp')->default(50);
            $table->timestamps();
        });
        Schema::create('enemies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->string('name');
            $table->string('role')->nullable();
            $table->boolean('is_boss')->default(false);
            $table->timestamps();
        });
        Schema::create('monster_marks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enemy_id')->unique();
            $table->string('mark_name');
            $table->string('bonus_stat', 16);
            $table->unsignedInteger('bonus_per_level')->default(1);
            $table->unsignedInteger('required_per_level')->default(10);
            $table->unsignedTinyInteger('max_level')->default(4);
            $table->decimal('drop_rate', 8, 2)->default(8.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('character_monster_marks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('monster_mark_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('spent_quantity')->default(0);
            $table->unsignedTinyInteger('unlocked_level')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'monster_mark_id']);
        });
        Schema::create('monster_mark_refinements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->uuid('request_token');
            $table->string('stat', 16);
            $table->unsignedTinyInteger('points')->default(1);
            $table->unsignedInteger('mark_cost');
            $table->json('consumed_marks');
            $table->timestamps();
            $table->unique(['character_id', 'request_token']);
        });
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();

        parent::tearDown();
    }

    public function test_first_fifteen_marks_are_protected(): void
    {
        $character = $this->character();
        $this->ownMark($character, '石牙狼', 15);

        $summary = $this->service()->summary($character);

        $this->assertSame(0, $summary['surplus_total']);
        $this->assertSame(20, $summary['next_cost']);
        $this->assertFalse($summary['can_refine']);
    }

    public function test_duplicate_mark_ids_are_combined_and_refinement_keeps_fifteen_unspent(): void
    {
        $character = $this->character();
        $first = $this->ownMark($character, '呪い騎士', 18, 51);
        $second = $this->ownMark($character, '呪い騎士', 17, 51);
        $service = $this->service();

        $result = $service->refine($character, 'str', '11111111-1111-4111-8111-111111111111');

        $this->assertSame(20, $result['spent_marks']);
        $this->assertSame(1, $result['gain']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame(35, $first->fresh()->quantity + $second->fresh()->quantity);
        $this->assertSame(20, $first->fresh()->spent_quantity + $second->fresh()->spent_quantity);
        $this->assertSame(0, $service->summary($character)['surplus_total']);
        $this->assertSame(1, $service->bonusPointsFor($character)['str']);
        $this->assertSame(1, $service->bonusesFor($character)['str']);
    }

    public function test_surplus_from_different_marks_can_be_pooled(): void
    {
        $character = $this->character();
        $this->ownMark($character, '石牙狼', 25, 1);
        $this->ownMark($character, '森ゴブリン', 25, 2);

        $result = $this->service()->refine(
            $character,
            'hp',
            '22222222-2222-4222-8222-222222222222',
        );

        $this->assertSame(10, $result['gain']);
        $this->assertSame(110, (int) $character->fresh()->current_hp);
        $consumedMarks = json_decode(
            (string) DB::table('monster_mark_refinements')->value('consumed_marks'),
            true,
        );
        $this->assertCount(2, $consumedMarks);
    }

    public function test_retry_with_the_same_request_token_does_not_consume_twice(): void
    {
        $character = $this->character();
        $owned = $this->ownMark($character, '石牙狼', 55);
        $service = $this->service();
        $token = '33333333-3333-4333-8333-333333333333';

        $first = $service->refine($character, 'def', $token);
        $retry = $service->refine($character, 'def', $token);

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($retry['idempotent']);
        $this->assertSame(20, $owned->fresh()->spent_quantity);
        $this->assertSame(1, DB::table('monster_mark_refinements')->count());
    }

    public function test_boss_and_dungeon_lord_marks_are_not_spendable(): void
    {
        $character = $this->character();
        $this->ownMark($character, '迷宮王', 100, 1, true, 'ボス');
        $this->ownMark($character, '深坑の主', 100, 2, false, 'ダンジョン主');

        $this->assertSame(0, $this->service()->summary($character)['surplus_total']);
    }

    public function test_cost_increases_after_ten_refinements_and_stops_at_twenty(): void
    {
        $service = $this->service();

        $this->assertSame(20, $service->nextCost(0));
        $this->assertSame(20, $service->nextCost(9));
        $this->assertSame(30, $service->nextCost(10));
        $this->assertSame(30, $service->nextCost(19));
        $this->assertNull($service->nextCost(20));
    }

    public function test_battle_result_progress_reports_global_surplus_and_remaining_cost(): void
    {
        $character = $this->character();
        $this->ownMark($character, '石牙狼', 25);

        $progress = $this->service()->battleResultProgress($character);

        $this->assertNotNull($progress);
        $this->assertSame(10, $progress['surplus_total']);
        $this->assertSame(0, $progress['total_points']);
        $this->assertSame(20, $progress['next_cost']);
        $this->assertSame(10, $progress['remaining_to_next']);
        $this->assertFalse($progress['at_cap']);
    }

    private function service(): MonsterMarkAlchemyService
    {
        return new MonsterMarkAlchemyService(new MonsterMarkService, new BonusPointService);
    }

    private function character(): Character
    {
        $id = DB::table('characters')->insertGetId([
            'current_hp' => 100,
            'current_mp' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Character::query()->findOrFail($id);
    }

    private function ownMark(
        Character $character,
        string $enemyName,
        int $quantity,
        int $areaId = 1,
        bool $isBoss = false,
        string $role = '通常',
    ): CharacterMonsterMark {
        $enemy = Enemy::query()->create([
            'area_id' => $areaId,
            'name' => $enemyName,
            'role' => $role,
            'is_boss' => $isBoss,
        ]);
        $mark = MonsterMark::query()->create([
            'enemy_id' => $enemy->id,
            'mark_name' => $enemyName.'の印',
            'bonus_stat' => 'str',
            'bonus_per_level' => 1,
            'required_per_level' => 10,
            'max_level' => 4,
            'drop_rate' => 8,
            'is_active' => true,
        ]);

        return CharacterMonsterMark::query()->create([
            'character_id' => $character->id,
            'monster_mark_id' => $mark->id,
            'quantity' => $quantity,
            'spent_quantity' => 0,
            'unlocked_level' => min(4, $quantity >= 15 ? 4 : 0),
        ]);
    }

    private function dropTestTables(): void
    {
        foreach (['monster_mark_refinements', 'character_monster_marks', 'monster_marks', 'enemies', 'characters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
