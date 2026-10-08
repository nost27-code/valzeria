<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\NamelessPreparationService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicCatalog;
use App\Services\NamelessRelicEquipmentService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NamelessBattleReadReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_battle_read_inspects_once_and_next_read_sees_changed_relics_and_missing_preparation(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Read reuse']);
        $body = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        $relic = PlayerRelic::create(['character_id' => $hero->id, 'effect_key' => 'brand_spirit', 'rank' => 1,
            'nameless_equipment_id' => $body->id, 'slot_number' => 1]);
        $before = [$hero->fresh()->getAttributes(), $body->fresh()->getAttributes(), $relic->fresh()->getAttributes()];
        $battle = app(NamelessRelicBattleService::class);
        $actor = new BattleActor('Read reuse', true, [], $hero);
        DB::enableQueryLog();
        try {
            $battle->attach($hero, $actor);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $migrationReads = array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], '"migrations"'));
        $this->assertCount(1, $migrationReads);
        $this->assertTrue($actor->namelessRelicsEnabled);
        $this->assertSame(app(NamelessRelicCatalog::class)->value('brand_spirit', 1), $actor->namelessBrand['potency']);
        $this->assertSame($before, [$hero->fresh()->getAttributes(), $body->fresh()->getAttributes(), $relic->fresh()->getAttributes()]);

        $relic->update(['rank' => 2]);
        $next = new BattleActor('Read reuse', true, [], $hero);
        $battle->attach($hero, $next);
        $this->assertSame(app(NamelessRelicCatalog::class)->value('brand_spirit', 2), $next->namelessBrand['potency']);
        $body->update(['is_equipped' => false]);
        $this->assertTrue(app(NamelessRelicEquipmentService::class)->activeRelics($hero)->isEmpty());
        $body->update(['is_equipped' => true]);
        $this->assertSame([$relic->id], app(NamelessRelicEquipmentService::class)->activeRelics($hero)->modelKeys());

        DB::beginTransaction();
        try {
            DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
            // No read snapshot remains around a subsequent write's availability check.
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
            $closed = new BattleActor('Read reuse', true, [], $hero);
            $battle->attach($hero, $closed);
            $this->assertFalse($closed->namelessRelicsEnabled);
            $this->assertTrue(app(NamelessRelicEquipmentService::class)->activeRelics($hero)->isEmpty());
        } finally {
            DB::rollBack();
        }
        $this->assertTrue(app(NamelessWorkshopService::class)->ready());
        config(['nameless_relics.enabled' => false]);
        $off = new BattleActor('Read reuse', true, [], $hero);
        $battle->attach($hero, $off);
        $this->assertFalse($off->namelessRelicsEnabled);
    }

    public function test_direct_relic_read_shares_nested_gates_without_sharing_owned_data(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Relic read']);
        DB::enableQueryLog();
        try {
            $this->assertTrue(app(NamelessRelicEquipmentService::class)->activeRelics($hero)->isEmpty());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], '"migrations"')));
    }

    public function test_failed_effect_read_does_not_leave_a_successful_schema_snapshot(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Failed read']);
        $body = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        $relic = PlayerRelic::create(['character_id' => $hero->id, 'effect_key' => 'brand_spirit', 'rank' => 1,
            'nameless_equipment_id' => $body->id, 'slot_number' => 1]);
        $catalog = $this->mock(NamelessRelicCatalog::class);
        $catalog->shouldReceive('definition')->once()->andThrow(new \RuntimeException('effect-read sentinel'));
        try {
            app(NamelessRelicBattleService::class)->attach($hero, new BattleActor('Failed read', true, [], $hero));
            $this->fail('Expected failed effect read');
        } catch (\RuntimeException $exception) {
            $this->assertSame('effect-read sentinel', $exception->getMessage());
        }
        DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertSame(1, (int) $relic->fresh()->rank);
    }
}
