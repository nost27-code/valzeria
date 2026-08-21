<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\User;
use App\Services\PublicLogService;
use App\Services\SixHeroRankChangeResult;
use App\Services\SixHeroRankingPublicLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SixHeroRankingPublicLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_place_change_publishes_only_the_six_hero_flash_report(): void
    {
        [$attacker, $defender] = $this->characters();

        $this->service()->publish(
            SixHeroRoomKey::DIVINE_SPEED,
            $attacker,
            $defender,
            $this->rankChange(oldRank: 2, newRank: 1),
        );

        $this->assertDatabaseCount('public_logs', 1);
        $this->assertDatabaseHas('public_logs', [
            'type' => 'arena',
            'character_id' => $attacker->id,
            'importance' => 3,
            'message' => "【六極速報】神速の間で、{$attacker->name}さんが{$defender->name}さんを破り、現在首位を奪取しました！",
        ]);
    }

    public function test_rank_up_to_thirtieth_publishes_a_ranking_notice(): void
    {
        [$attacker, $defender] = $this->characters();

        $this->service()->publish(
            SixHeroRoomKey::SEAL_MAGIC,
            $attacker,
            $defender,
            $this->rankChange(oldRank: 33, newRank: 30),
        );

        $this->assertDatabaseHas('public_logs', [
            'type' => 'arena',
            'character_id' => $attacker->id,
            'importance' => 2,
            'message' => "【六極殿】封魔の間で、{$attacker->name}さんが{$defender->name}さんを破り、33位から30位へ駆け上がりました！",
        ]);
    }

    public function test_rank_below_top_thirty_loss_and_unchanged_rank_are_not_published(): void
    {
        [$attacker, $defender] = $this->characters();
        $service = $this->service();

        $service->publish(
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
            $this->rankChange(oldRank: 34, newRank: 31),
        );
        $service->publish(
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
            $this->rankChange(oldRank: 10, newRank: 10, attackerWon: false, rankChanged: false),
        );
        $service->publish(
            SixHeroRoomKey::MIRACLE,
            $attacker,
            $defender,
            $this->rankChange(oldRank: 10, newRank: 10, rankChanged: false),
        );

        $this->assertDatabaseCount('public_logs', 0);
    }

    private function service(): SixHeroRankingPublicLogService
    {
        return new SixHeroRankingPublicLogService(new PublicLogService);
    }

    /** @return array{Character, Character} */
    private function characters(): array
    {
        return [
            Character::query()->create([
                'user_id' => User::factory()->create()->id,
                'name' => 'セレナ・アルディス',
            ]),
            Character::query()->create([
                'user_id' => User::factory()->create()->id,
                'name' => 'ガルド・ヴェイン',
            ]),
        ];
    }

    private function rankChange(
        int $oldRank,
        int $newRank,
        bool $attackerWon = true,
        bool $rankChanged = true,
    ): SixHeroRankChangeResult {
        return new SixHeroRankChangeResult(
            attackerWon: $attackerWon,
            rankChanged: $rankChanged,
            attackerOldRank: $oldRank,
            attackerNewRank: $newRank,
            defenderOldRank: $newRank,
            defenderNewRank: $newRank + 1,
        );
    }
}
