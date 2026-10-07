<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\ArenaRanking;
use App\Services\ArenaNpcRankingService;
use App\Services\CharacterStatusService;
use App\Services\CharacterPowerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArenaRankCompactionPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Isolated in-memory database required.');
        }
        foreach ([
            'users' => 'id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT',
            'characters' => 'id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, current_job_id INTEGER, icon_path TEXT, level INTEGER DEFAULT 1',
            'character_icon_entitlements' => 'id INTEGER PRIMARY KEY, character_id INTEGER, icon_set_key TEXT, arena_showcase_scene TEXT, revoked_at TEXT',
            'npc_master' => 'npc_id INTEGER PRIMARY KEY, npc_name TEXT, npc_title TEXT, image_path TEXT',
            'arena_rankings' => 'id INTEGER PRIMARY KEY, character_id INTEGER UNIQUE, rank INTEGER UNIQUE, wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT',
            'arena_npc_rankings' => 'id INTEGER PRIMARY KEY, npc_id INTEGER, rank INTEGER UNIQUE, level INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT',
        ] as $table => $columns) {
            DB::statement('CREATE TABLE '.$table.' ('.$columns.')');
        }
    }

    public function test_challenge_only_calculates_the_three_nearest_opponents_among_five_hundred_players(): void
    {
        $this->players(500, 0);
        DB::table('arena_npc_rankings')->insert(['id' => 1, 'npc_id' => 1, 'rank' => 900001, 'is_active' => 0]);
        $calculated = [];
        $this->mock(CharacterStatusService::class)->shouldReceive('getFinalStats')->times(3)
            ->andReturnUsing(function (Character $character) use (&$calculated): array {
                $calculated[] = (int) $character->id;

                return ['max_hp' => 100, 'max_mp' => 0, 'str' => 10, 'def' => 8, 'agi' => 8, 'mag' => 8, 'spr' => 8, 'luk' => 5];
            });
        $targets = (new ArenaNpcRankingService)->targetEntries(ArenaRanking::where('character_id', 500)->firstOrFail(), 3);
        $this->assertSame([499, 498, 497], $targets->pluck('rank')->all());
        $this->assertSame([499, 498, 497], $calculated);
        $this->assertSame(['player', 'player', 'player'], $targets->pluck('type')->all());
        $this->assertArrayHasKey('power', $targets->first());
    }

    public function test_combined_candidates_preserve_player_npc_order_and_exclude_inactive_npcs(): void
    {
        $this->players(3, 0);
        DB::table('arena_rankings')->where('id', 2)->update(['rank' => 4]);
        DB::table('arena_rankings')->where('id', 3)->update(['rank' => 8]);
        DB::table('arena_npc_rankings')->insert([
            ['id' => 1, 'npc_id' => 1, 'rank' => 9, 'is_active' => 1],
            ['id' => 2, 'npc_id' => 2, 'rank' => 7, 'is_active' => 1],
            ['id' => 3, 'npc_id' => 3, 'rank' => 6, 'is_active' => 0],
        ]);
        $this->mock(CharacterStatusService::class)->shouldReceive('getFinalStats')->once()->andReturn([]);
        $power = $this->mock(CharacterPowerService::class);
        $power->shouldReceive('fromFinalStats')->once()->andReturn(1000);
        $power->shouldReceive('recommendedRangeForLevels')->twice()->andReturn(['min' => 1000]);
        $service = new ArenaNpcRankingService;
        // Test selection independently from the separately covered rank repair.
        (new \ReflectionProperty($service, 'rankingsEnsured'))->setValue($service, true);
        $targets = $service->targetEntries(new ArenaRanking(['rank' => 10]), 3);
        $this->assertSame([9, 8, 7], $targets->pluck('rank')->all());
        $this->assertSame(['npc', 'player', 'npc'], $targets->pluck('type')->all());
        $this->assertSame([1, 3, 2], $targets->pluck('id')->all());
    }

    public function test_five_hundred_displaced_players_are_repaired_with_six_updates_and_same_order(): void
    {
        $this->players(500, 1);
        DB::enableQueryLog();
        $ranking = (new ArenaNpcRankingService)->ensurePlayerRanking(Character::findOrFail(500));
        $updates = $this->updates();
        $this->assertCount(6, $updates);
        $this->assertSame(500, (int) $ranking->rank);
        $this->assertSame(range(1, 500), DB::table('arena_rankings')->orderBy('rank')->pluck('rank')->all());
        $this->assertSame(range(1, 500), DB::table('arena_rankings')->orderBy('rank')->pluck('character_id')->all());
    }

    public function test_normal_ranks_are_not_written_and_a_single_gap_only_touches_the_displaced_row(): void
    {
        $this->players(3, 0);
        DB::enableQueryLog();
        (new ArenaNpcRankingService)->ensurePlayerRanking(Character::findOrFail(3));
        $this->assertCount(0, $this->updates());
        DB::table('arena_rankings')->where('id', 3)->update(['rank' => 4]);
        DB::flushQueryLog();
        (new ArenaNpcRankingService)->ensurePlayerRanking(Character::findOrFail(3));
        $this->assertCount(2, $this->updates());
        $this->assertNull(DB::table('arena_rankings')->where('id', 1)->value('updated_at'));
        $this->assertSame([1, 2, 3], DB::table('arena_rankings')->orderBy('rank')->pluck('rank')->all());
    }

    public function test_hidden_players_and_active_and_inactive_npcs_keep_the_existing_final_rank_rules(): void
    {
        $this->players(3, 1);
        DB::table('users')->where('id', 2)->update(['email' => 'tester_probe@valzeria.local']);
        DB::table('arena_npc_rankings')->insert([
            ['id' => 1, 'npc_id' => 1, 'rank' => 1, 'is_active' => 0],
            ['id' => 2, 'npc_id' => 2, 'rank' => 2, 'is_active' => 1],
            ['id' => 3, 'npc_id' => 3, 'rank' => 3, 'is_active' => 1],
        ]);
        (new ArenaNpcRankingService)->ensurePlayerRanking(Character::findOrFail(3));
        $this->assertSame([1, 800001, 2], DB::table('arena_rankings')->orderBy('id')->pluck('rank')->all());
        $this->assertSame([900001, 51, 52], DB::table('arena_npc_rankings')->orderBy('id')->pluck('rank')->all());
    }

    public function test_failure_restoring_final_ranks_rolls_back_every_temporary_update(): void
    {
        $this->players(3, 1);
        DB::statement("CREATE TRIGGER reject_final_rank BEFORE UPDATE OF rank ON arena_rankings WHEN NEW.rank > 0 BEGIN SELECT RAISE(ABORT, 'probe failure'); END");
        try {
            (new ArenaNpcRankingService)->ensurePlayerRanking(Character::findOrFail(3));
            $this->fail('Expected injected update failure.');
        } catch (QueryException) {
            $this->assertSame([2, 3, 4], DB::table('arena_rankings')->orderBy('id')->pluck('rank')->all());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    private function players(int $count, int $offset): void
    {
        for ($id = 1; $id <= $count; $id++) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Probe', 'email' => 'probe'.$id.'@example.test', 'role' => 'user']);
            DB::table('characters')->insert(['id' => $id, 'user_id' => $id, 'name' => 'Probe']);
            DB::table('arena_rankings')->insert(['id' => $id, 'character_id' => $id, 'rank' => $id + $offset]);
        }
    }

    private function updates(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($entry) => preg_match('/^update /i', $entry['query'])));
    }
}
