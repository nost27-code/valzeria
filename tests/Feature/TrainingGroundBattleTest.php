<?php

namespace Tests\Feature;

use App\Models\ArenaLog;
use App\Models\ArenaRanking;
use App\Models\BattleLog;
use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\Character;
use App\Models\GameplayMetric;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\TrainingGroundBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TrainingGroundBattleTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_runs_for_50_turns_without_persisting_character_or_battle_results(): void
    {
        $character = $this->character('訓練確認者');
        $character->forceFill([
            'current_hp' => 17,
            'current_mp' => 3,
            'wins' => 7,
            'losses' => 3,
            'last_champ_battle_at' => now(),
        ])->save();
        $before = $character->fresh()->getAttributes();

        $service = app(TrainingGroundBattleService::class);
        $outcome = $service->practice($character, 'boss');
        $result = $outcome['result'];

        $this->assertSame('boss', $outcome['context']);
        $this->assertSame($service->maxTurns(), $result->turnCount);
        $this->assertSame('training_complete', $result->result);
        $this->assertSame(0, $result->exp);
        $this->assertSame(0, $result->gold);
        $this->assertSame(0, $result->jobExp);
        $this->assertSame([], $result->drops);
        $this->assertLessThanOrEqual(
            $result->playerHpBefore * $service->maxTurns() * $service->incomingDamageCapPercent() / 100,
            $result->damageTaken,
        );
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame(0, BattleLog::query()->count());
        $this->assertSame(0, ChampBattleLog::query()->count());
        $this->assertSame(0, ChampHistory::query()->count());
    }

    public function test_training_ground_is_a_character_scoped_town_facility(): void
    {
        $character = $this->character('施設確認者');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('training-ground.index'))
            ->assertOk()
            ->assertSee('冒険者訓練所')
            ->assertSee('通常戦用セット')
            ->assertSee('ボス戦用セット')
            ->assertSee('対人模擬戦')
            ->assertSee('キャラクター名から探す')
            ->assertSee('闘技場ランキングから選ぶ')
            ->assertSee('対人用のボス戦技を整える')
            ->assertSee('50ターン固定');
    }

    public function test_training_ground_buttons_expose_processing_feedback(): void
    {
        $character = $this->character('操作表示確認者');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('training-ground.index'))
            ->assertOk()
            ->assertSee('data-submit-lock data-loading-text="訓練中..."', false)
            ->assertSee('data-submit-lock data-loading-text="検索中..."', false)
            ->assertSee('data-navigation-lock data-loading-text="移動中..."', false);
    }

    public function test_ranking_opponent_can_start_pvp_training_in_one_post_without_duplicate_card(): void
    {
        $character = $this->character('選ぶ側');
        $rankedTarget = $this->character('一手で戦う相手');
        ArenaRanking::query()->create([
            'character_id' => $rankedTarget->id,
            'rank' => 2,
            'wins' => 10,
            'losses' => 3,
        ]);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('training-ground.index', ['opponent_id' => $rankedTarget->id]))
            ->assertOk()
            ->assertSee('data-ranking-practice-form data-submit-lock data-loading-text="模擬戦中..."', false)
            ->assertSee('action="'.route('training-ground.battle').'" method="POST"', false)
            ->assertSee('name="context" value="pvp"', false)
            ->assertSee('name="opponent_id" required', false)
            ->assertSee($rankedTarget->name)
            ->assertSee('模擬戦をする')
            ->assertDontSee('この冒険者を選ぶ')
            ->assertDontSee('選択中の対戦相手')
            ->assertDontSee($rankedTarget->name.'と模擬戦する');
    }

    public function test_training_ground_pvp_job_art_link_opens_the_requested_context(): void
    {
        config(['battle.job_art_v2.pvp_set' => true]);
        $character = $this->character('戦技導線確認者');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('job-arts.index', ['context' => 'pvp']))
            ->assertOk()
            ->assertSee('data-initial-job-art-context="pvp"', false);
    }

    public function test_training_ground_uses_the_boss_set_link_while_the_pvp_set_flag_is_off(): void
    {
        config(['battle.job_art_v2.pvp_set' => false]);
        $character = $this->character('対人導線確認者');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('training-ground.index'))
            ->assertOk()
            ->assertSee('対人用（ボス戦用）')
            ->assertSee('対人用のボス戦技を整える')
            ->assertDontSee('PvP戦技セットを整える');
    }

    public function test_training_ground_can_find_a_public_opponent_by_name_and_select_ranked_players(): void
    {
        $character = $this->character('探す側');
        $searchTarget = $this->character('蒼の剣士');
        $rankedTarget = $this->character('紅の槍士');
        $hiddenTarget = $this->character('蒼の密偵');
        $hiddenTarget->user->forceFill(['email' => 'tester_hidden@valzeria.local'])->save();
        ArenaRanking::query()->create([
            'character_id' => $rankedTarget->id,
            'rank' => 12,
            'wins' => 8,
            'losses' => 4,
        ]);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('training-ground.index', ['opponent_search' => '蒼']))
            ->assertOk()
            ->assertSee($searchTarget->name)
            ->assertDontSee($hiddenTarget->name)
            ->assertSee($rankedTarget->name)
            ->assertSee('12位');

        $this->get(route('training-ground.index', [
            'opponent_search' => '蒼',
            'opponent_id' => $searchTarget->id,
        ]))
            ->assertOk()
            ->assertSee('選択中の対戦相手')
            ->assertSee($searchTarget->name.'と模擬戦する');
    }

    public function test_pvp_training_preserves_state_metrics_and_chronological_log_order(): void
    {
        $attacker = $this->character('模擬挑戦者');
        $defender = $this->character('模擬対戦者');
        $attacker->forceFill([
            'current_hp' => 17,
            'current_mp' => 3,
            'wins' => 7,
            'losses' => 3,
            'last_champ_battle_at' => now()->subHour(),
        ])->save();
        $defender->forceFill([
            'current_hp' => 9,
            'current_mp' => 2,
            'wins' => 11,
            'losses' => 5,
        ])->save();
        $attackerRanking = ArenaRanking::query()->create([
            'character_id' => $attacker->id,
            'rank' => 1,
            'wins' => 20,
            'losses' => 2,
        ]);
        $defenderRanking = ArenaRanking::query()->create([
            'character_id' => $defender->id,
            'rank' => 2,
            'wins' => 18,
            'losses' => 4,
        ]);
        $attackerBefore = Arr::except($attacker->fresh()->getAttributes(), ['last_seen_at']);
        $defenderBefore = Arr::except($defender->fresh()->getAttributes(), ['last_seen_at']);
        $attackerRankingBefore = $attackerRanking->fresh()->getAttributes();
        $defenderRankingBefore = $defenderRanking->fresh()->getAttributes();

        $response = $this->actingAs($attacker->user)
            ->withSession(['current_character_id' => $attacker->id])
            ->post(route('training-ground.battle'), [
                'context' => 'pvp',
                'opponent_id' => $defender->id,
            ]);

        $response->assertRedirect(route('training-ground.result'));
        $outcome = session('training_ground_result');
        $this->assertIsArray($outcome);
        $this->assertSame('pvp', $outcome['context']);
        $this->assertSame('対人模擬戦', $outcome['context_label']);
        $this->assertSame($defender->id, $outcome['opponent_id']);
        $this->assertSame($defender->name, $outcome['opponent_name']);

        $logs = $outcome['result']->logs;
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString(
            "【対人模擬戦】{$attacker->name} が {$defender->name} に勝負を挑んだ！",
            $logs[0],
        );
        $turnLogIndex = collect($logs)->search(fn (string $line): bool => str_contains($line, '--- ターン 1 ---'));
        $decisionLogIndex = collect($logs)->search(fn (string $line): bool => str_contains($line, '決着！') || str_contains($line, '判定勝利！'));
        $this->assertIsInt($turnLogIndex);
        $this->assertIsInt($decisionLogIndex);
        $this->assertGreaterThan(0, $turnLogIndex);
        $this->assertGreaterThan($turnLogIndex, $decisionLogIndex);
        $decisionMarker = str_contains($logs[$decisionLogIndex], '判定勝利！') ? '判定勝利！' : '決着！';

        $this->get(route('training-ground.result'))
            ->assertOk()
            ->assertSee('対人用のボス戦技を見直す')
            ->assertSeeInOrder([
                "【対人模擬戦】{$attacker->name} が {$defender->name} に勝負を挑んだ！",
                '--- ターン 1 ---',
                $decisionMarker,
            ], false);

        $this->assertSame($attackerBefore, Arr::except($attacker->fresh()->getAttributes(), ['last_seen_at']));
        $this->assertSame($defenderBefore, Arr::except($defender->fresh()->getAttributes(), ['last_seen_at']));
        $this->assertSame($attackerRankingBefore, $attackerRanking->fresh()->getAttributes());
        $this->assertSame($defenderRankingBefore, $defenderRanking->fresh()->getAttributes());
        $this->assertSame(0, ArenaLog::query()->count());
        $this->assertSame(0, BattleLog::query()->count());
        $this->assertSame(0, ChampBattleLog::query()->count());
        $this->assertSame(0, ChampHistory::query()->count());
        $this->assertSame(0, GameplayMetric::query()->count());
    }

    public function test_pvp_training_rejects_selecting_the_current_character(): void
    {
        $character = $this->character('自分自身');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('training-ground.index'))
            ->post(route('training-ground.battle'), [
                'context' => 'pvp',
                'opponent_id' => $character->id,
            ])
            ->assertRedirect(route('training-ground.index'))
            ->assertSessionHasErrors('opponent_id');
    }

    public function test_training_uses_the_selected_context_and_configured_turn_limit(): void
    {
        config(['training_ground.max_turns' => 7]);
        $character = $this->character('通常セット確認者');

        $outcome = app(TrainingGroundBattleService::class)->practice($character, 'pve');

        $this->assertSame('pve', $outcome['context']);
        $this->assertSame('通常戦用セット', $outcome['context_label']);
        $this->assertSame(7, $outcome['max_turns']);
        $this->assertSame(7, $outcome['result']->turnCount);
    }

    public function test_training_ground_rejects_an_unknown_loadout_context(): void
    {
        $character = $this->character('入力確認者');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('training-ground.battle'), ['context' => 'champ'])
            ->assertSessionHasErrors('context');
    }

    public function test_training_ground_rejects_concurrent_requests(): void
    {
        $character = $this->character('連打確認者');
        Cache::put("training_ground_request_delay:{$character->id}", true, now()->addMinute());

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->from(route('training-ground.index'))
            ->post(route('training-ground.battle'), ['context' => 'pve'])
            ->assertRedirect(route('training-ground.index'))
            ->assertSessionHas('message', '訓練の処理中です。少し待ってからもう一度お試しください。');
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 0,
            'hp_base' => 100,
            'mp_base' => 20,
            'attack_base' => 10,
            'defense_base' => 8,
            'speed_base' => 8,
            'magic_base' => 8,
            'spirit_base' => 8,
            'luck_base' => 5,
        ]);
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'training-ground-' . $character->id,
            'name' => '訓練のお供',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return $character;
    }
}
