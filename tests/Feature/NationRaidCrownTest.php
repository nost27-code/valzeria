<?php

namespace Tests\Feature;

use App\Enums\SixHeroRoomKey;
use App\Livewire\CityHeader;
use App\Models\Character;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidCrownService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRankingService;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

final class NationRaidCrownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tokyo',
            'features.nation_competitive_raid_enabled' => true,
            'features.six_hero_ui_enabled' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', 'Asia/Tokyo'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_previous_final_winner_remains_until_the_next_raid_actually_starts(): void
    {
        $previousLeader = $this->character('前回レイド覇者');
        $previous = $this->event('previous-raid', '黒天竜ヴァルグレイド', now()->subDays(14), NationRaidEvent::STATUS_ACTIVE);
        $this->addPlayer($previous, $previousLeader, 15, 1_000);
        $this->completeWithCurrentStandings($previous);

        $service = app(NationRaidCrownService::class);
        $this->assertSame('最終1位', $service->leaders()[$previousLeader->id]['status_label']);

        $next = $this->event('next-raid', '天墜機神アストラギア', now()->addDay(), NationRaidEvent::STATUS_SCHEDULED);
        $this->assertArrayHasKey($previousLeader->id, $service->leaders());

        $next->update([
            'status' => NationRaidEvent::STATUS_ACTIVE,
            'starts_at' => now(),
            'activated_at' => now(),
        ]);
        $this->assertSame([], $service->leaders());

        $unqualifiedLeader = $this->character('条件未達の首位');
        $qualifiedSecond = $this->character('条件達成の二位');
        $this->addPlayer($next, $unqualifiedLeader, 14, 2_000);
        $this->addPlayer($next, $qualifiedSecond, 15, 1_000);
        $this->assertSame([], $service->leaders(), '条件未達の1位を飛ばして2位へ王冠を付けない');

        $this->addResolvedSortie($next, $next->participations()->where('character_id', $unqualifiedLeader->id)->firstOrFail(), 15, 2_000);
        $leaders = $service->leaders();
        $this->assertSame([$unqualifiedLeader->id], array_keys($leaders));
        $this->assertSame('現在首位', $leaders[$unqualifiedLeader->id]['status_label']);

        $next->update(['status' => NationRaidEvent::STATUS_FINALIZING]);
        $this->assertArrayHasKey($unqualifiedLeader->id, $service->leaders());
        $this->completeWithCurrentStandings($next);
        $this->assertSame('最終1位', $service->leaders()[$unqualifiedLeader->id]['status_label']);
    }

    public function test_all_qualified_competition_rank_one_players_receive_the_crown(): void
    {
        $event = $this->event('tied-raid', '同率確認レイド', now()->subHour(), NationRaidEvent::STATUS_ACTIVE);
        $first = $this->character('同率首位甲');
        $second = $this->character('同率首位乙');
        $this->addPlayer($event, $first, 15, 1_000);
        $this->addPlayer($event, $second, 15, 1_000);

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_keys(app(NationRaidCrownService::class)->leaders()),
        );
    }

    public function test_online_list_shows_raid_and_six_hero_crowns_together(): void
    {
        $viewer = $this->character('王冠閲覧者');
        $leader = $this->character('二冠の冒険者');
        $leader->forceFill(['last_seen_at' => now()])->saveQuietly();
        $event = $this->event('current-raid', '天墜機神アストラギア', now()->subHour(), NationRaidEvent::STATUS_ACTIVE);
        $this->addPlayer($event, $leader, 15, 1_000);

        $season = SixHeroSeason::query()->create([
            'season_key' => '2026-09',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->startOfMonth()->addMonth(),
            'ranking_initialized_at' => now()->startOfMonth(),
        ]);
        SixHeroRanking::query()->create([
            'season_id' => $season->id,
            'room_key' => SixHeroRoomKey::DIVINE_SPEED,
            'character_id' => $leader->id,
            'rank' => 1,
            'official_attack_wins' => 1,
            'official_attack_losses' => 0,
            'defense_wins' => 0,
            'defense_losses' => 0,
            'registered_at' => now(),
            'first_place_since' => now(),
        ]);

        $this->actingAs($viewer->user)->withSession(['current_character_id' => $viewer->id]);

        Livewire::test(CityHeader::class)
            ->assertSeeHtml('data-nation-raid-top-ranker="'.$leader->id.'"')
            ->assertSeeHtml('data-nation-raid-crown="current-raid"')
            ->assertSeeHtml('raid_champion_crown.webp')
            ->assertSeeHtml('data-six-hero-top-ranker="'.$leader->id.'"')
            ->assertSeeHtml('data-six-hero-crown="divine_speed"')
            ->assertSeeHtml('aria-label="国家対抗レイド 現在首位（天墜機神アストラギア）・六英雄戦 現在首位（神速の間） '.$leader->name.'"');
    }

    private function event(string $key, string $name, Carbon $startsAt, string $status): NationRaidEvent
    {
        $event = app(NationRaidEventService::class)->createDraft($key, $name, $startsAt);
        $event->update([
            'status' => $status,
            'activated_at' => in_array($status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING], true)
                ? $startsAt
                : null,
        ]);

        return $event->fresh();
    }

    private function completeWithCurrentStandings(NationRaidEvent $event): void
    {
        $standings = app(NationRaidRankingService::class)->standings($event->fresh());
        $standings['is_final'] = true;
        $event->update([
            'status' => NationRaidEvent::STATUS_COMPLETED,
            'final_standings_snapshot' => $standings,
            'final_standings_hash' => app(NationRaidRewardPolicy::class)->hash($standings),
            'finalized_at' => now(),
        ]);
    }

    private function addPlayer(NationRaidEvent $event, Character $character, int $sorties, int $damage): void
    {
        $participation = NationRaidParticipation::query()->create([
            'event_id' => $event->id,
            'account_id' => $character->user_id,
            'character_id' => $character->id,
            'character_id_snapshot' => $character->id,
            'is_nation_eligible' => false,
            'reference_active_count' => 0,
            'character_name_snapshot' => $character->name,
        ]);

        foreach (range(1, $sorties) as $sortieNo) {
            $this->addResolvedSortie($event, $participation, $sortieNo, $damage);
        }
    }

    private function addResolvedSortie(
        NationRaidEvent $event,
        NationRaidParticipation $participation,
        int $sortieNo,
        int $damage,
    ): void {
        NationRaidBattleResult::query()->create([
            'event_id' => $event->id,
            'participation_id' => $participation->id,
            'account_id' => $participation->account_id,
            'character_id' => $participation->character_id,
            'battle_token' => bin2hex(random_bytes(32)),
            'sortie_seed' => str_repeat('a', 64),
            'status' => NationRaidBattleResult::STATUS_RESOLVED,
            'raid_day' => 1,
            'day_sortie_no' => $sortieNo,
            'event_sortie_no' => $sortieNo,
            'target_cycle_no' => 1,
            'target_cycle_kind' => 'main',
            'target_stage_no' => 1,
            'target_form' => 'sealed_scale',
            'target_parameter_snapshot' => [],
            'boss_species_key' => 'dragon',
            'strategy' => 'assault',
            'applied_damage_total' => $damage,
            'coordination_damage_total' => 0,
            'nation_damage_total' => 0,
            'max_action_damage' => $damage,
            'started_at' => $event->starts_at,
            'resolved_at' => $event->starts_at,
            'resolution_deadline_at' => $event->starts_at->copy()->addMinutes(10),
        ]);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
    }
}
