<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Services\MonsterMarkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class MonsterMarkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTestTables();

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
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
            $table->unsignedTinyInteger('unlocked_level')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'monster_mark_id']);
        });
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();

        parent::tearDown();
    }

    public function test_same_area_and_enemy_name_marks_are_merged_even_when_enemy_ids_differ(): void
    {
        $service = new MonsterMarkService;
        $area = new Area;
        $area->id = 51;

        $ownedEnemy = new Enemy(['area_id' => 51, 'name' => '呪い騎士']);
        $ownedEnemy->id = 5101;
        $unownedEnemy = new Enemy(['area_id' => 51, 'name' => '呪い騎士']);
        $unownedEnemy->id = 5102;

        $ownedMark = new MonsterMark(['mark_name' => '呪い騎士の印', 'bonus_per_level' => 3, 'max_level' => 4]);
        $ownedMark->id = 101;
        $unownedMark = new MonsterMark(['mark_name' => '呪い騎士の印', 'bonus_per_level' => 3, 'max_level' => 4]);
        $unownedMark->id = 102;

        $entries = new Collection([
            $this->entry($ownedMark, $ownedEnemy, $area, 9),
            $this->entry($unownedMark, $unownedEnemy, $area, 0),
        ]);

        $merged = $this->invokePrivate($service, 'deduplicateCollectionEntries', [$entries]);

        $this->assertCount(1, $merged);
        $this->assertSame(9, $merged->first()['quantity']);
        $this->assertSame(3, $merged->first()['unlocked_level']);
        $this->assertTrue($merged->first()['is_discovered']);

        $ownedMark->setRelation('enemy', $ownedEnemy);
        $unownedMark->setRelation('enemy', $unownedEnemy);
        $this->assertSame(
            $this->invokePrivate($service, 'markSignature', [$ownedMark]),
            $this->invokePrivate($service, 'markSignature', [$unownedMark]),
        );
    }

    public function test_boss_and_dungeon_lord_are_not_eligible_for_monster_marks(): void
    {
        $service = new MonsterMarkService;

        $boss = new Enemy(['is_boss' => true]);
        $dungeonLord = new Enemy(['is_boss' => false, 'role' => 'ダンジョン主']);
        $normalEnemy = new Enemy(['is_boss' => false, 'role' => '通常']);

        $this->assertFalse($this->invokePrivate($service, 'isEligibleEnemy', [$boss]));
        $this->assertFalse($this->invokePrivate($service, 'isEligibleEnemy', [$dungeonLord]));
        $this->assertTrue($this->invokePrivate($service, 'isEligibleEnemy', [$normalEnemy]));
    }

    public function test_grant_uses_combined_quantity_without_false_first_unlock(): void
    {
        $fixture = $this->duplicateMarkFixture(8);

        $result = (new MonsterMarkService)->rollAndGrant(
            $fixture['character'],
            $fixture['canonical_enemy'],
        );

        $this->assertNotNull($result);
        $this->assertSame(9, $result['total_quantity']);
        $this->assertSame(3, $result['before_level']);
        $this->assertSame(3, $result['unlocked_level']);
        $this->assertFalse($result['level_up']);
        $this->assertDatabaseHas('character_monster_marks', [
            'character_id' => $fixture['character']->id,
            'monster_mark_id' => $fixture['canonical_mark']->id,
            'quantity' => 1,
            'unlocked_level' => 3,
        ]);
        $this->assertDatabaseHas('character_monster_marks', [
            'character_id' => $fixture['character']->id,
            'monster_mark_id' => $fixture['duplicate_mark']->id,
            'quantity' => 8,
        ]);
    }

    public function test_grant_unlocks_the_actual_combined_threshold(): void
    {
        $fixture = $this->duplicateMarkFixture(6);

        $result = (new MonsterMarkService)->rollAndGrant(
            $fixture['character'],
            $fixture['canonical_enemy'],
        );

        $this->assertNotNull($result);
        $this->assertSame(7, $result['total_quantity']);
        $this->assertSame(2, $result['before_level']);
        $this->assertSame(3, $result['unlocked_level']);
        $this->assertTrue($result['level_up']);
        $this->assertSame(9, $result['total_bonus']);
    }

    public function test_runtime_renamed_enemy_uses_persisted_enemy_mark(): void
    {
        $fixture = $this->duplicateMarkFixture(8);
        $runtimeEnemy = $fixture['duplicate_enemy']->replicate();
        $runtimeEnemy->id = $fixture['duplicate_enemy']->id;
        $runtimeEnemy->exists = true;
        $runtimeEnemy->name = '黄金ゴブリン';
        $runtimeEnemy->role = '特殊';

        $result = (new MonsterMarkService)->rollAndGrant($fixture['character'], $runtimeEnemy);

        $this->assertNotNull($result);
        $this->assertSame($fixture['canonical_mark']->id, $result['monster_mark_id']);
        $this->assertSame(9, $result['total_quantity']);
        $this->assertFalse($result['level_up']);
        $this->assertDatabaseHas('character_monster_marks', [
            'character_id' => $fixture['character']->id,
            'monster_mark_id' => $fixture['canonical_mark']->id,
            'quantity' => 1,
        ]);
    }

    public function test_drop_rate_reduction_uses_combined_quantity(): void
    {
        $fixture = $this->duplicateMarkFixture(15, 12.0);
        $service = new MonsterMarkService;
        $markIds = $this->invokePrivate($service, 'equivalentMarkIds', [$fixture['canonical_mark']]);
        $quantity = $this->invokePrivate($service, 'ownedQuantity', [$fixture['character'], $markIds]);

        $this->assertSame(15, $quantity);
        $this->assertSame(
            2.0,
            $this->invokePrivate($service, 'effectiveDropRate', [$fixture['canonical_mark'], $quantity]),
        );
    }

    private function entry(MonsterMark $mark, Enemy $enemy, Area $area, int $quantity): array
    {
        return [
            'mark' => $mark,
            'enemy' => $enemy,
            'area' => $area,
            'quantity' => $quantity,
            'unlocked_level' => 0,
            'next_required' => null,
            'max_level' => 4,
            'bonus_label' => 'HP',
            'total_bonus' => 0,
            'progress_percent' => 0,
            'is_discovered' => $quantity > 0,
            'is_area_discovered' => true,
            'is_complete' => false,
        ];
    }

    private function duplicateMarkFixture(int $ownedQuantity, float $dropRate = 999.0): array
    {
        $now = now();
        DB::table('characters')->insert([
            'id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $character = Character::query()->findOrFail(1);

        $canonicalEnemy = Enemy::query()->create([
            'area_id' => 51,
            'name' => '呪い騎士',
            'role' => '通常',
            'is_boss' => false,
        ]);
        $duplicateEnemy = Enemy::query()->create([
            'area_id' => 51,
            'name' => '呪い騎士',
            'role' => '通常',
            'is_boss' => false,
        ]);
        $canonicalMark = MonsterMark::query()->create([
            'enemy_id' => $canonicalEnemy->id,
            'mark_name' => '呪い騎士の印',
            'bonus_stat' => 'def',
            'bonus_per_level' => 3,
            'required_per_level' => 10,
            'max_level' => 4,
            'drop_rate' => $dropRate,
            'is_active' => true,
        ]);
        $duplicateMark = MonsterMark::query()->create([
            'enemy_id' => $duplicateEnemy->id,
            'mark_name' => '呪い騎士の印',
            'bonus_stat' => 'def',
            'bonus_per_level' => 3,
            'required_per_level' => 10,
            'max_level' => 4,
            'drop_rate' => $dropRate,
            'is_active' => true,
        ]);
        CharacterMonsterMark::query()->create([
            'character_id' => $character->id,
            'monster_mark_id' => $duplicateMark->id,
            'quantity' => $ownedQuantity,
            'unlocked_level' => 0,
        ]);

        return [
            'character' => $character,
            'canonical_enemy' => $canonicalEnemy,
            'duplicate_enemy' => $duplicateEnemy,
            'canonical_mark' => $canonicalMark,
            'duplicate_mark' => $duplicateMark,
        ];
    }

    private function dropTestTables(): void
    {
        foreach (['character_monster_marks', 'monster_marks', 'enemies', 'characters'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
