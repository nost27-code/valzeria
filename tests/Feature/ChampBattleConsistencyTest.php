<?php

namespace Tests\Feature;

use App\Http\Controllers\ChampBattleController;
use App\Models\Character;
use App\Models\ChampBattleLog;
use App\Models\ChampState;
use App\Models\User;
use App\Services\ChampBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use ReflectionMethod;
use Tests\TestCase;

class ChampBattleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_exposes_the_current_champ_appointment_identity(): void
    {
        $character = $this->character('表示確認者');
        $champ = ChampState::query()->firstOrFail();

        $summary = app(ChampBattleService::class)->summary($character);

        $this->assertSame(0, $summary['champ_identity']['character_id']);
        $this->assertSame($champ->appointed_at->getTimestamp(), $summary['champ_identity']['appointed_at']);
    }

    public function test_challenge_is_rejected_when_the_displayed_champ_has_changed(): void
    {
        $character = $this->character('交代確認者');
        $champ = ChampState::query()->firstOrFail();

        $result = app(ChampBattleService::class)->executeChallenge(
            $character,
            999999,
            $champ->appointed_at->getTimestamp(),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('チャンプが交代しました。最新の情報を確認して、もう一度挑戦してください。', $result['message']);

        $result = app(ChampBattleService::class)->executeChallenge(
            $character,
            0,
            $champ->appointed_at->getTimestamp() - 1,
        );

        $this->assertFalse($result['ok']);
        $this->assertNull($character->fresh()->last_champ_battle_at);
        $this->assertSame(0, ChampBattleLog::query()->count());
    }

    public function test_cached_challenge_form_without_identity_is_rejected_before_battle(): void
    {
        $character = $this->character('旧画面確認者');
        $this->actingAs($character->user);
        $this->app['session']->start();
        session([
            'current_character_id' => $character->id,
            'lastChampBattleResult' => ['next_available_at' => now()->addMinute()],
        ]);

        $response = app(ChampBattleController::class)->challenge(
            Request::create('/champ/challenge', 'POST'),
            app(ChampBattleService::class),
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(
            '画面のチャンプ情報が古くなっています。最新の情報を確認して、もう一度挑戦してください。',
            session('message'),
        );
        $this->assertNull(session('lastChampBattleResult'));
        $this->assertSame(0, ChampBattleLog::query()->count());
    }

    public function test_recent_logs_use_id_as_a_tie_breaker_for_the_same_timestamp(): void
    {
        $character = $this->character('ログ確認者');
        $createdAt = now()->startOfSecond();

        $olderId = $this->battleLog($character, '先の登録', $createdAt)->id;
        $newerId = $this->battleLog($character, '後の登録', $createdAt)->id;

        $ids = app(ChampBattleService::class)->recentLogs(2)->pluck('id')->all();

        $this->assertSame([$newerId, $olderId], $ids);
    }

    public function test_expired_archived_result_is_cleared_instead_of_being_shown_again(): void
    {
        $this->app['session']->start();
        session([
            'lastChampBattleResult' => [
                'next_available_at' => now()->subSecond(),
            ],
        ]);

        $response = app(ChampBattleController::class)->result();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('home'), $response->getTargetUrl());
        $this->assertNull(session('lastChampBattleResult'));
    }

    public function test_unexpired_archived_result_can_still_be_reloaded_during_cooldown(): void
    {
        $this->app['session']->start();
        session([
            'lastChampBattleResult' => [
                'next_available_at' => now()->addMinute(),
            ],
        ]);

        $response = app(ChampBattleController::class)->result();

        $this->assertInstanceOf(View::class, $response);
        $this->assertSame('champ.result', $response->name());
    }

    public function test_only_a_challenger_action_can_turn_a_dead_champ_into_a_victory(): void
    {
        $method = new ReflectionMethod(ChampBattleService::class, 'isChallengerVictory');
        $method->setAccessible(true);
        $service = app(ChampBattleService::class);

        $this->assertTrue($method->invoke($service, true, true));
        $this->assertFalse($method->invoke($service, true, false));
        $this->assertFalse($method->invoke($service, false, true));
    }

    private function character(string $name): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'explore_stamina' => 0,
            'hp_base' => 100,
            'mp_base' => 0,
            'attack_base' => 10,
            'defense_base' => 8,
            'speed_base' => 8,
            'magic_base' => 8,
            'spirit_base' => 8,
            'luck_base' => 5,
        ]);
    }

    private function battleLog(Character $challenger, string $name, $createdAt): ChampBattleLog
    {
        return ChampBattleLog::query()->create([
            'champ_character_id' => null,
            'champ_player_name' => '試練官',
            'challenger_character_id' => $challenger->id,
            'challenger_player_name' => $name,
            'damage' => 1,
            'is_champ_defeated' => false,
            'champ_hp_before' => 10,
            'champ_hp_after' => 9,
            'exp_gained' => 0,
            'job_exp_gained' => 0,
            'material_id' => null,
            'material_name' => null,
            'material_quantity' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
