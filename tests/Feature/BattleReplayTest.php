<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Models\PlayerValmon;
use App\Models\ValmonMaster;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleReplayRecorder;
use App\Services\Battle\BattleState;
use App\Services\TrainingGroundBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class BattleReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_victory_and_defeat_keep_rewards_rng_and_post_battle_recovery_identical(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '勝敗確認',
            'hp_base' => 100, 'mp_base' => 20, 'attack_base' => 10, 'defense_base' => 8,
            'speed_base' => 8, 'magic_base' => 8, 'spirit_base' => 8, 'luck_base' => 5,
        ]);
        $before = $character->fresh()->getAttributes();
        foreach (['victory', 'defeat'] as $expected) {
            $enemy = new \App\Models\Enemy([
                'name' => '確認用の敵', 'level' => 1, 'max_hp' => $expected === 'victory' ? 1 : 1000000,
                'max_mp' => 0, 'str' => $expected === 'victory' ? 1 : 1000000,
                'def' => 1, 'agi' => 1, 'mag' => 1, 'spr' => 1, 'luk' => 1,
                'type_name' => '標準型', 'normal_attack_type' => 'physical',
                'exp_reward' => 11, 'gold_reward' => 7, 'job_exp_reward' => 3,
                'skip_danger_bonus' => true, 'skip_durability_bonus' => true,
            ]);
            $enemy->setRelation('actions', collect());
            $enemy->setRelation('area', null);
            $results = [];
            $rngAfter = [];
            foreach ([false, true] as $enabled) {
                config(['battle.replay.enabled' => $enabled]);
                $service = app(\App\Services\BattleService::class);
                $randomizer = new Randomizer(new Mt19937(1742));
                (new \ReflectionMethod($service, 'useScopedBattleRandomizer'))->invoke($service, $randomizer);
                $results[] = $service->executeBattle($character->fresh(), $enemy, 0, [
                    'persist_character_state' => false, 'auto_unequip_invalid_items' => false,
                    'exploration_support_enabled' => false, 'valmon_assist_enabled' => false,
                    'starting_hp' => 100, 'starting_mp' => 20, 'force_player_first' => true,
                ]);
                $rngAfter[] = $randomizer->getInt(0, PHP_INT_MAX);
            }
            [$off, $on] = $results;
            $this->assertSame($rngAfter[0], $rngAfter[1]);
            $this->assertSame($expected, $on->result);
            $copy = clone $on;
            $copy->battleReplay = null;
            $this->assertEquals($off, $copy);
            $frames = $on->battleReplay['frames'];
            $this->assertSame($on->logs, array_merge(...array_column($frames, 'logs')));
            $this->assertSame($on->playerHpAfter, $frames[array_key_last($frames)]['actors']['player']['hp']);
            if ($expected === 'defeat') {
                $this->assertContains(0, array_map(fn ($frame) => $frame['actors']['player']['hp'], $frames));
                $this->assertGreaterThan(0, $on->playerHpAfter);
            }
        }
        $this->assertSame($before, $character->fresh()->getAttributes());
    }

    public function test_recording_does_not_change_a_real_training_battle_or_persist_character_state(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '再生確認',
            'hp_base' => 100, 'mp_base' => 20, 'attack_base' => 10, 'defense_base' => 8,
            'speed_base' => 8, 'magic_base' => 8, 'spirit_base' => 8, 'luck_base' => 5,
        ]);
        $before = $character->fresh()->getAttributes();
        $master = ValmonMaster::query()->create(['valmon_key' => 'replay-check', 'name' => '訓練のお供', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::query()->create(['character_id' => $character->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);
        $results = [];
        $rngAfter = [];
        foreach ([false, true] as $enabled) {
            config(['battle.replay.enabled' => $enabled]);
            mt_srand(1742);
            $service = app(TrainingGroundBattleService::class);
            $randomizer = new Randomizer(new Mt19937(1742));
            (new \ReflectionMethod($service, 'useScopedBattleRandomizer'))->invoke($service, $randomizer);
            $results[] = $service->practice($character->fresh(), 'pve')['result'];
            $rngAfter[] = $randomizer->getInt(0, PHP_INT_MAX);
        }
        [$off, $on] = $results;
        $this->assertSame($rngAfter[0], $rngAfter[1]);
        $this->assertNull($off->battleReplay);
        $this->assertNotEmpty($on->battleReplay['frames']);
        $copy = clone $on;
        $copy->battleReplay = null;
        $this->assertEquals($off, $copy, 'Display recording must not alter RNG, logs, vitals or rewards.');
        $this->assertSame($before, $character->fresh()->getAttributes());
        $frames = $on->battleReplay['frames'];
        $last = $frames[array_key_last($frames)]['actors'];
        $this->assertSame($on->playerHpAfter, $last['player']['hp']);
        $this->assertSame($on->playerMpAfter, $last['player']['sp']);
        $this->assertSame($on->enemyHpAfter, $last['enemy']['hp']);
        $this->assertSame($on->logs, array_merge(...array_column($frames, 'logs')));
        $this->assertLessThan(1024 * 1024, strlen(json_encode($on->battleReplay)));
        $actions = array_filter($frames, fn ($frame) => isset($frame['action']['side']));
        $this->assertGreaterThanOrEqual($on->turnCount, count($actions));
        $this->assertLessThanOrEqual($on->turnCount * 2, count($actions));

        $this->actingAs($character->user)->withSession([
            'current_character_id' => $character->id,
            'training_ground_result' => ['context' => 'pve', 'result' => $on],
        ])->get(route('training-ground.result'))->assertOk()->assertSee('data-battle-replay', false)->assertSee('次へ');

        $enemy = new \App\Models\Enemy(['name' => 'スライム', 'str' => 10, 'def' => 8, 'max_hp' => $on->enemyMaxHp]);
        $response = $this->withSession(['lastBattleData' => [
            'character' => $character, 'isBoss' => false, 'areaId' => 0, 'jobLevel' => 1,
            'result' => ['enemy' => $enemy, 'result' => 'timeout', 'log' => implode('<br>', $on->logs),
                'battle_replay' => $on->battleReplay, 'exp_gained' => 0, 'job_exp_gained' => 0, 'gold_gained' => 0],
        ]])->get(route('battle.result'))->assertOk()->assertSee('data-battle-replay', false)->assertSee('前へ')
            ->assertDontSee('aria-label="簡易戦闘結果"', false)
            ->assertSee('data-compact-accordion="log"', false)
            ->assertSee('data-compact-accordion="result"', false);
        $this->assertSame(1, substr_count($response->getContent(), '/js/battle-replay.js?v='));
        $response->assertSee('結果を見る')->assertSee('painted-atlas.webp');
        $batchData = session('lastBattleData');
        $batchData['result']['batch_explore'] = ['requested' => 50, 'completed' => 47];
        $this->withSession(['lastBattleData' => $batchData])->get(route('battle.result'))
            ->assertOk()->assertSee('最後の戦闘')->assertSee('47回探索のうち、最後の1戦')
            ->assertSee('47回分の合計')->assertSee('47/50回で終了');

        $legacyBattleData = session('lastBattleData');
        unset($legacyBattleData['result']['battle_replay']);
        $this->withSession(['lastBattleData' => $legacyBattleData])->get(route('battle.result'))
            ->assertOk()->assertSee('/js/battle-replay.js?v=', false)
            ->assertDontSee('data-battle-replay aria-label', false);

        config(['battle.replay.enabled' => false]);
        $this->get(route('battle.result'))->assertOk()->assertSee('aria-label="簡易戦闘結果"', false)
            ->assertDontSee('/js/battle-replay.js?v=', false);
    }

    public function test_snapshots_preserve_miss_critical_reflection_and_terminal_vitals_without_mutation(): void
    {
        $player = new BattleActor('<script>確認</script>', true, ['max_hp' => 100, 'max_mp' => 0]);
        $enemy = new BattleActor('敵', false, ['max_hp' => 50]);
        $state = new BattleState($player, $enemy);
        $recorder = new BattleReplayRecorder($state);
        $before = [get_object_vars($player), get_object_vars($enemy)];
        $recorder->begin($player);
        $recorder->miss($enemy);
        $state->addLog('攻撃をかわした！');
        $recorder->finish();
        $this->assertSame($before, [get_object_vars($player), get_object_vars($enemy)]);
        $recorder->begin($enemy);
        $recorder->critical(true);
        $player->hp = 0;
        $enemy->hp = 17;
        $player->conditions = ['burn' => ['turns' => 2]];
        $state->addLog('被弾と反撃');
        $recorder->finish();
        $result = $recorder->result();
        $this->assertSame('miss', $result['frames'][0]['action']['hits'][0]['kind']);
        $this->assertTrue($result['frames'][1]['action']['critical']);
        $this->assertSame(0, $result['frames'][1]['actors']['player']['hp']);
        $this->assertSame(17, $result['frames'][1]['actors']['enemy']['hp']);
        $this->assertSame(['火傷 2T'], $result['frames'][1]['actors']['player']['badges']);
        config(['battle.replay.enabled' => true]);
        $html = view('components.battle-replay', ['replay' => $result])->render();
        $this->assertStringNotContainsString('<script>確認</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;確認&lt;/script&gt;', $html);
    }

    public function test_old_results_and_disabled_recording_keep_the_log_only_view(): void
    {
        config(['battle.replay.enabled' => true]);
        $this->assertStringNotContainsString('data-battle-replay', view('components.battle-replay')->render());
        config(['battle.replay.enabled' => false]);
        $this->assertStringNotContainsString('data-battle-replay', view('components.battle-replay', ['replay' => ['version' => 1, 'frames' => [[]]]])->render());
    }
}
