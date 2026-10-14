<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Models\PlayerNamelessEquipment;
use App\Models\User;
use App\Services\BattleReadScope;
use App\Services\BattleSchemaMetadata;
use App\Services\CharacterStatusService;
use App\Services\ExplorationDepthService;
use App\Services\FerdiaMapService;
use App\Services\MonsterMarkService;
use App\Services\NamelessSchemaService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BattleReadScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_reads_share_derived_values_but_query_builder_updates_invalidate_them(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = $this->hero();
        $peer = $this->hero();
        $enemy = Enemy::where('is_boss', false)->firstOrFail();
        $mark = MonsterMark::updateOrCreate(['enemy_id' => $enemy->id], ['mark_name' => $enemy->name,
            'bonus_stat' => 'str', 'bonus_per_level' => 1, 'max_level' => 4, 'required_per_level' => 1, 'is_active' => true]);
        CharacterMonsterMark::create(['character_id' => $hero->id, 'monster_mark_id' => $mark->id, 'quantity' => 1, 'unlocked_level' => 1]);
        $gear = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        $marks = app(MonsterMarkService::class);
        $workshop = app(NamelessWorkshopService::class);
        $before = $marks->permanentBonuses($hero)['str'];
        $power = $workshop->equippedWeaponOffense($hero)['str'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(NamelessSchemaService::class)->withBattleSnapshot(function () use ($marks, $workshop, $hero, $peer, $mark, $gear, $before, $power) {
            for ($i = 0; $i < 50; $i++) {
                if ($i === 25) {
                    DB::table('character_monster_marks')->where('character_id', $hero->id)->where('monster_mark_id', $mark->id)->update(['quantity' => 15]);
                    DB::table('player_nameless_equipments')->where('id', $gear->id)->update(['forge_level' => 1]);
                }
                $this->assertSame($i < 25 ? $before : $before + 3, $marks->permanentBonuses($hero)['str']);
                $this->assertSame(0, $marks->permanentBonuses($peer)['str']);
                $actual = $workshop->equippedWeaponOffense($hero)['str'];
                $this->assertTrue($i < 25 ? $actual === $power : $actual > $power);
                $this->assertSame(['str' => 0, 'mag' => 0], $workshop->equippedWeaponOffense($peer));
            }
        });
        $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select'));
        $this->assertCount(4, $reads->filter(fn ($q) => str_contains($q['query'], 'join "character_monster_marks"')));
        $this->assertCount(4, $reads->filter(fn ($q) => str_contains($q['query'], 'from "player_nameless_equipments"')));
        DB::disableQueryLog();
        DB::table('player_nameless_equipments')->where('id', $gear->id)->update(['is_equipped' => false]);
        $this->assertSame(['str' => 0, 'mag' => 0], $workshop->equippedWeaponOffense($hero));
    }

    public function test_nested_rollback_discards_values_read_after_writes(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = $this->hero();
        $gear = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'armor', 'equipment_type' => '鎧', 'is_equipped' => true]);
        $workshop = app(NamelessWorkshopService::class);
        app(NamelessSchemaService::class)->withBattleSnapshot(function () use ($hero, $gear, $workshop) {
            $before = $workshop->equippedArmorDefense($hero);
            DB::beginTransaction();
            $gear->update(['forge_level' => 2]);
            $this->assertGreaterThan($before['def'], $workshop->equippedArmorDefense($hero)['def']);
            DB::rollBack();
            $this->assertSame($before, $workshop->equippedArmorDefense($hero));
        });
    }

    public function test_exception_restores_schema_and_discards_all_owned_values(): void
    {
        $schema = Schema::getFacadeRoot();
        $scope = app(BattleReadScope::class);
        $calls = 0;
        try {
            $scope->run(function () use ($scope, &$calls) {
                $scope->remember('development', 'sentinel', function () use (&$calls) {
                    return ++$calls;
                });
                throw new \RuntimeException('failed operation');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('failed operation', $e->getMessage());
        }
        $this->assertNotInstanceOf(BattleSchemaMetadata::class, Schema::getFacadeRoot());
        $this->assertSame($schema->getConnection(), Schema::getFacadeRoot()->getConnection());
        $scope->run(function () use ($scope, &$calls) {
            $this->assertSame(2, $scope->remember('development', 'sentinel', function () use (&$calls) {
                return ++$calls;
            }));
            $this->assertSame(2, $scope->run(fn () => $scope->remember('development', 'sentinel', fn () => 999)));
        });
        $this->assertNotInstanceOf(BattleSchemaMetadata::class, Schema::getFacadeRoot());
        $this->assertSame($schema->getConnection(), Schema::getFacadeRoot()->getConnection());
    }

    public function test_feature_off_cannot_reuse_equipped_values_from_feature_on(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = $this->hero();
        PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        app(NamelessSchemaService::class)->withBattleSnapshot(function () use ($hero) {
            $workshop = app(NamelessWorkshopService::class);
            $this->assertGreaterThan(0, $workshop->equippedWeaponOffense($hero)['str']);
            config(['nameless_relics.enabled' => false]);
            $this->assertSame(['str' => 0, 'mag' => 0], $workshop->equippedWeaponOffense($hero));
        });
    }

    public function test_development_completion_is_visible_immediately_after_a_progress_write(): void
    {
        $hero = $this->hero();
        $area = Area::firstOrFail();
        $map = $this->mock(FerdiaMapService::class);
        $map->shouldReceive('isFerdiaAreaId')->andReturn(true);
        $map->shouldReceive('maxDevelopmentPointForArea')->andReturn(100);
        CharacterAreaProgress::create(['character_id' => $hero->id, 'area_id' => $area->id, 'development_point' => 0]);
        $read = new \ReflectionMethod(ExplorationDepthService::class, 'ferdiaDevelopmentIncomplete');
        app(BattleReadScope::class)->run(function () use ($read, $hero, $area) {
            $service = app(ExplorationDepthService::class);
            for ($i = 0; $i < 50; $i++) {
                if ($i === 25) {
                    DB::table('character_area_progresses')->where('character_id', $hero->id)->update(['development_point' => 100]);
                }
                $this->assertSame($i < 25, $read->invoke($service, $hero, $area));
            }
        });
    }

    public function test_schema_absence_case_insensitive_columns_and_ddl_are_not_stale(): void
    {
        $scope = app(BattleReadScope::class);
        $scope->run(function () {
            $this->assertFalse(Schema::hasTable('scope_probe'));
            Schema::create('scope_probe', fn ($table) => $table->integer('sample'));
            $this->assertTrue(Schema::hasTable('scope_probe'));
            $this->assertTrue(Schema::hasColumn('scope_probe', 'SAMPLE'));
            $this->assertTrue(Schema::hasColumns('scope_probe', ['sample']));
            $this->assertFalse(Schema::hasColumns('scope_probe', ['sample', 'absent']));
            Schema::drop('scope_probe');
            $this->assertFalse(Schema::hasTable('scope_probe'));
        });
    }

    public function test_final_stats_still_follow_character_growth_within_the_operation(): void
    {
        $hero = $this->hero();
        $status = app(CharacterStatusService::class);
        app(NamelessSchemaService::class)->withBattleSnapshot(function () use ($hero, $status) {
            $before = $status->getFinalStats($hero);
            $hero->increment('attack_base', 10);
            CharacterStatusService::clearRequestCache((int) $hero->id);
            $after = $status->getFinalStats($hero);
            $this->assertGreaterThan($before['str'], $after['str']);
        });
    }

    public function test_schema_factory_follows_connection_switch_after_scope(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.scope_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        app(BattleReadScope::class)->run(fn () => Schema::hasTable('characters'));
        try {
            DB::setDefaultConnection('scope_other');
            Schema::create('scope_other_probe', fn ($table) => $table->integer('value'));
            $this->assertTrue(Schema::hasTable('scope_other_probe'));
            $this->assertFalse(Schema::hasTable('characters'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('scope_other');
        }
        $this->assertTrue(Schema::hasTable('characters'));
        $this->assertFalse(Schema::hasTable('scope_other_probe'));
    }

    private function hero(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '操作内共有試験']);
    }
}
