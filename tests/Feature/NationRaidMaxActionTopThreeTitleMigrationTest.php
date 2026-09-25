<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRewardIdentity;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NationRaidMaxActionTopThreeTitleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_titles_and_claimed_rewards_are_backfilled_from_the_verified_inaugural_final_standings_idempotently(): void
    {
        $event = app(NationRaidEventService::class)->createDraft(
            'valgreid-inaugural',
            '国家対抗レイド 黒天竜ヴァルグレイド',
            now()->subMonth(),
        );
        $rankTwo = $this->character('最大一撃二位');
        $rankThree = $this->character('最大一撃三位');
        $unqualified = $this->character('出撃条件未達');
        $rankFour = $this->character('最大一撃四位');
        $snapshot = ['max_action' => [
            $this->standing($rankTwo, 2, true),
            $this->standing($rankThree, 3, true),
            $this->standing($unqualified, 2, false),
            $this->standing($rankFour, 4, true),
        ]];
        $finalizedAt = now()->subWeeks(2);
        $event->update([
            'status' => 'completed',
            'completed_at' => $finalizedAt->copy()->subHour(),
            'finalized_at' => $finalizedAt,
            'final_standings_snapshot' => $snapshot,
            'final_standings_hash' => app(NationRaidRewardPolicy::class)->hash($snapshot),
        ]);
        $originalSnapshot = $event->fresh()->final_standings_snapshot;

        $migration = require database_path('migrations/2026_09_26_000000_add_nation_raid_max_action_top_three_titles.php');
        $migration->up();
        $migration->up();

        $valgreidTitleId = (int) DB::table('titles')
            ->where('target_id', NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET)
            ->value('id');
        $this->assertDatabaseHas('titles', [
            'target_id' => NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET,
            'name' => '黒天竜穿ちの剛撃',
        ]);
        $this->assertDatabaseHas('titles', [
            'target_id' => NationRaidRewardIdentity::ASTRAGIA_MAX_ACTION_TOP_THREE_TITLE_TARGET,
            'name' => '天墜機神砕きの剛撃',
        ]);
        foreach ([[$rankTwo, 2], [$rankThree, 3]] as [$character, $rank]) {
            $this->assertDatabaseHas('character_titles', [
                'character_id' => $character->id,
                'title_id' => $valgreidTitleId,
                'is_equipped' => false,
            ]);
            $reward = DB::table('nation_raid_personal_rewards')
                ->where('event_id', $event->id)
                ->where('character_id_snapshot', $character->id)
                ->where('reward_key', 'max_top3')
                ->sole();
            $payload = json_decode($reward->reward_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $balance = json_decode($reward->balance_after_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('claimed', $reward->status);
            $this->assertSame('finalization', $reward->availability_type);
            $this->assertSame('黒天竜穿ちの剛撃', $payload['title']);
            $this->assertSame(NationRaidRewardIdentity::VALGREID_MAX_ACTION_TOP_THREE_TITLE_TARGET, $payload['title_target_id']);
            $this->assertFalse($payload['badge']);
            $this->assertSame($rank, $payload['rank']);
            $this->assertGreaterThan(0, $balance['character_title_id']);
        }
        foreach ([$unqualified, $rankFour] as $character) {
            $this->assertDatabaseMissing('character_titles', [
                'character_id' => $character->id,
                'title_id' => $valgreidTitleId,
            ]);
            $this->assertDatabaseMissing('nation_raid_personal_rewards', [
                'event_id' => $event->id,
                'character_id_snapshot' => $character->id,
                'reward_key' => 'max_top3',
            ]);
        }
        $this->assertSame(2, DB::table('character_titles')->where('title_id', $valgreidTitleId)->count());
        $this->assertSame(2, DB::table('nation_raid_personal_rewards')->where('event_id', $event->id)->where('reward_key', 'max_top3')->count());
        $this->assertSame($originalSnapshot, $event->fresh()->final_standings_snapshot);
    }

    private function character(string $name): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
        ]);
    }

    private function standing(Character $character, int $rank, bool $qualified): array
    {
        return [
            'account_id' => $character->user_id,
            'character_id' => $character->id,
            'name' => $character->name,
            'rank' => $rank,
            'qualified' => $qualified,
        ];
    }
}
