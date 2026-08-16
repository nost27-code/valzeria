<?php

namespace Tests\Feature;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\TrainingGroundBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('50ターン固定');
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
