<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\MonsterMarkRefinement;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\DiscoveryService;
use App\Services\MonsterMarkAlchemyService;
use App\Services\SchemaStateService;
use App\Services\StorageCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplorationSchemaReadReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_checks_share_schema_but_keep_progress_assets_and_bonus_points_fresh(): void
    {
        config(['nameless_relics.enabled' => false]);
        $character = $this->character();
        $area = Area::query()->firstOrFail();
        $discovery = app(DiscoveryService::class);
        $storage = app(StorageCapacityService::class);
        $alchemy = app(MonsterMarkAlchemyService::class);
        $this->app->forgetInstance(SchemaStateService::class);
        DB::enableQueryLog();
        $firstSchemaReads = null;
        for ($run = 1; $run <= 50; $run++) {
            if ($run === 25) {
                PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1]);
            }
            if ($run === 30) {
                MonsterMarkRefinement::create(['character_id' => $character->id, 'request_token' => (string) Str::uuid(),
                    'stat' => 'str', 'points' => 1, 'mark_cost' => 20, 'consumed_marks' => []]);
            }
            $progress = $discovery->checkAfterExplore($character, $area, false);
            $this->assertSame(min(100, $run * 10), $progress['development']['after']);
            $owned = $storage->summaryFromOwnedTotals($character, 0, 0);
            $this->assertSame($run >= 25 ? 1 : 0, $owned['relic_total']);
            $this->assertSame($run >= 30 ? 1 : 0, $alchemy->bonusPointsFor($character)['str']);
            if ($run === 1) {
                $firstSchemaReads = $this->schemaReadCount();
            }
        }
        $this->assertSame($firstSchemaReads, $this->schemaReadCount());
        DB::disableQueryLog();
    }

    public function test_absent_owner_column_is_safe_and_a_new_request_rechecks_the_schema(): void
    {
        $character = $this->character();
        Schema::drop('player_relics');
        Schema::create('player_relics', function ($table) {
            $table->id();
        });
        $this->app->forgetInstance(SchemaStateService::class);
        $storage = app(StorageCapacityService::class);
        $this->assertSame(0, $storage->summaryFromOwnedTotals($character, 0, 0)['relic_total']);
        Schema::table('player_relics', function ($table) {
            $table->unsignedBigInteger('character_id')->nullable();
        });
        DB::table('player_relics')->insert(['character_id' => $character->id]);
        // Emulate the next HTTP request's scoped service lifecycle after DDL.
        $this->app->forgetInstance(SchemaStateService::class);
        $this->assertSame(1, $storage->summaryFromOwnedTotals($character, 0, 0)['relic_total']);
    }

    public function test_battle_strategy_schema_is_shared_but_saved_strategy_is_read_fresh(): void
    {
        $hero = $this->character();
        $service = app(\App\Services\JobArtService::class);
        $this->app->forgetInstance(SchemaStateService::class);
        $service->saveContextSpPolicy($hero, 'normal', 'aggressive');
        DB::enableQueryLog();
        $first = null;
        for ($run = 1; $run <= 50; $run++) {
            if ($run === 25) {
                $service->saveContextSpPolicy($hero, 'normal', 'conserve');
            }
            $this->assertSame($run >= 25 ? 'conserve' : 'aggressive', $service->contextSpPolicy($hero, 'normal'));
            if ($run === 1) {
                $first = $this->schemaReadCount();
            }
        }
        $this->assertSame($first, $this->schemaReadCount());
        DB::disableQueryLog();
    }

    private function schemaReadCount(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($row) => str_contains($row['query'], 'sqlite_master')
            || str_contains($row['query'], 'pragma_table_xinfo')));
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '構造確認共有試験']);
    }
}
