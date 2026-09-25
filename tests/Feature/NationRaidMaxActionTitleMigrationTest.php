<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRewardIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NationRaidMaxActionTitleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_max_action_titles_are_idempotent_and_claimed_valgreid_winner_is_backfilled_without_removing_legacy_title(): void
    {
        $event = app(NationRaidEventService::class)->createDraft(
            'valgreid-inaugural',
            '国家対抗レイド 黒天竜ヴァルグレイド',
            now()->subMonth(),
        );
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '初代最大一撃の冒険者',
        ]);
        $legacyTitleId = (int) DB::table('titles')
            ->where('unlock_type', 'nation_raid_honor')
            ->where('target_id', 'max_first')
            ->value('id');
        DB::table('character_titles')->insert([
            'character_id' => $character->id,
            'title_id' => $legacyTitleId,
            'is_equipped' => true,
            'created_at' => now()->subWeeks(2),
            'updated_at' => now()->subWeeks(2),
        ]);
        NationRaidPersonalReward::query()->create([
            'event_id' => $event->id,
            'account_id_snapshot' => $character->user_id,
            'character_id_snapshot' => $character->id,
            'character_id' => $character->id,
            'reward_key' => 'max_first',
            'status' => NationRaidPersonalReward::STATUS_CLAIMED,
            'reward_snapshot' => ['title' => '天穿の一撃'],
            'idempotency_key' => hash('sha256', 'valgreid-max-action-backfill-test'),
            'claimed_at' => now()->subWeek(),
        ]);

        $migration = require database_path('migrations/2026_09_25_150000_add_nation_raid_max_action_titles.php');
        $migration->up();
        $migration->up();

        $valgreidTitleId = (int) DB::table('titles')
            ->where('target_id', NationRaidRewardIdentity::VALGREID_MAX_ACTION_TITLE_TARGET)
            ->value('id');
        $this->assertDatabaseHas('titles', [
            'target_id' => NationRaidRewardIdentity::VALGREID_MAX_ACTION_TITLE_TARGET,
            'name' => '黒天竜穿ちの極撃',
        ]);
        $this->assertDatabaseHas('titles', [
            'target_id' => NationRaidRewardIdentity::ASTRAGIA_MAX_ACTION_TITLE_TARGET,
            'name' => '天墜機神砕きの極撃',
        ]);
        $this->assertSame(1, DB::table('character_titles')
            ->where('character_id', $character->id)
            ->where('title_id', $valgreidTitleId)
            ->count());
        $this->assertDatabaseHas('character_titles', [
            'character_id' => $character->id,
            'title_id' => $legacyTitleId,
            'is_equipped' => true,
        ]);
    }
}
