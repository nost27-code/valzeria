<?php

namespace Tests\Feature;

use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\ChampState;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\User;
use App\Services\ChampBattleService;
use App\Services\ChampBattleTransactionRunner;
use App\Services\CharacterStatusService;
use App\Services\LevelService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ChampBattleContentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_late_lock_failure_rolls_back_rewards_before_retry_and_cooldown_blocks_replay(): void
    {
        $character = $this->character();
        $champBefore = ChampState::firstOrFail()->getRawOriginal();
        $baseStats = app(CharacterStatusService::class)->getFinalStats($character);
        $attempts = 0;
        ChampBattleLog::creating(function () use (&$attempts, $character) {
            if (++$attempts === 1) {
                // Fail after rewards and the shared champ update have already been written.
                $this->assertGreaterThan(0, CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
                throw $this->queryError(1205);
            }
        });

        $result = app(ChampBattleService::class)->executeChallenge($character);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, ChampBattleLog::count());
        $this->assertSame((int) $champBefore['current_hp'], $result['champ_hp_before']);
        $this->assertSame($baseStats['max_hp'], $result['challenger_actor']['max_hp']);
        $this->assertSame((int) $champBefore['defense_count'] + 1, (int) ChampState::firstOrFail()->defense_count);
        $this->assertEquals($result['material_quantity'], CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
        $this->assertNotNull($character->fresh()->last_champ_battle_at);
        $spentExp = 0;
        for ($level = 1; $level < $character->fresh()->level; $level++) {
            $spentExp += app(LevelService::class)->getRequiredExp($level);
        }
        $this->assertSame(99 + $result['exp_gained'] - $spentExp, (int) $character->fresh()->exp);
        $this->assertSame($champBefore['appointed_at'], ChampState::firstOrFail()->getRawOriginal('appointed_at'));
        $replay = app(ChampBattleService::class)->executeChallenge($character);
        $this->assertFalse($replay['ok']);
        $this->assertSame(1, ChampBattleLog::count());
        $this->assertEquals($result['material_quantity'], CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
    }

    public function test_repeated_late_contention_leaves_no_rewards_champ_damage_or_cooldown(): void
    {
        $character = $this->character();
        $characterBefore = $character->fresh()->getRawOriginal();
        $champBefore = ChampState::firstOrFail()->getRawOriginal();
        Log::spy();
        $attempts = 0;
        ChampBattleLog::creating(function () use (&$attempts) {
            $attempts++;
            throw $this->queryError(1205);
        });

        $result = app(ChampBattleService::class)->executeChallenge($character);

        $this->assertFalse($result['ok']);
        $this->assertSame(3, $attempts);
        $this->assertSame($characterBefore, $character->fresh()->getRawOriginal());
        $this->assertSame($champBefore, ChampState::firstOrFail()->getRawOriginal());
        $this->assertSame(0, ChampBattleLog::count());
        $this->assertSame(0, CharacterMaterial::where('character_id', $character->id)->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Champ battle database contention handled.'
            && $context['phase'] === 'battle_log_save'
            && $context['attempts'] === 3
            && $context['database_error_code'] === 1205
            && array_keys($context) === ['database_error_code', 'reason', 'phase', 'attempts', 'elapsed_ms']
        );
    }

    public function test_non_contention_database_error_is_rolled_back_and_not_retried_or_hidden(): void
    {
        $character = $this->character();
        $before = $character->fresh()->getRawOriginal();
        $attempts = 0;
        ChampBattleLog::creating(function () use (&$attempts) {
            $attempts++;
            throw $this->queryError(1062);
        });

        try {
            app(ChampBattleService::class)->executeChallenge($character);
            $this->fail('Expected the original database error.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
        }
        $this->assertSame(1, $attempts);
        $this->assertSame($before, $character->fresh()->getRawOriginal());
        $this->assertSame(0, CharacterMaterial::where('character_id', $character->id)->count());
    }

    public function test_retry_backoff_occurs_after_rollback(): void
    {
        $initialLevel = DB::transactionLevel();
        $runner = new class($initialLevel) extends ChampBattleTransactionRunner
        {
            public int $waits = 0;

            public function __construct(private int $initialLevel) {}

            protected function waitBeforeRetry(int $attempt): void
            {
                Assert::assertSame($this->initialLevel, DB::transactionLevel());
                $this->waits++;
            }
        };
        $calls = 0;
        $result = $runner->run(0, function () use (&$calls) {
            if (++$calls === 1) {
                throw $this->queryError(1205);
            }

            return ['ok' => true];
        });
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $runner->waits);
        $this->assertSame(2, $calls);
    }

    public function test_victory_and_history_are_committed_once_after_a_late_retry(): void
    {
        $character = $this->character();
        $character->update(['hp_base' => 10000, 'current_hp' => 10000, 'speed_base' => 1000]);
        ChampState::firstOrFail()->update([
            'current_hp' => 1, 'max_hp' => 1, 'atk' => 1, 'def' => 0, 'spd' => 1,
        ]);
        $attempts = 0;
        ChampBattleLog::creating(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw $this->queryError(1205);
            }
        });

        $result = app(ChampBattleService::class)->executeChallenge($character);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['champ_defeated']);
        $this->assertSame(2, $attempts);
        $this->assertSame((int) $character->id, (int) ChampState::firstOrFail()->character_id);
        $this->assertSame(1, ChampHistory::count());
        $this->assertSame(1, ChampBattleLog::count());
        $this->assertEquals($result['material_quantity'], CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
    }

    public function test_missing_initial_champ_is_created_once_and_can_be_challenged(): void
    {
        $character = $this->character();
        ChampState::query()->delete();

        $result = app(ChampBattleService::class)->executeChallenge($character);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, ChampState::count());
        $this->assertSame(1, ChampBattleLog::count());
    }

    public function test_early_incumbent_wait_can_retry_after_mariadb_timeout_exceeds_two_seconds(): void
    {
        $calls = 0;
        $result = app(ChampBattleTransactionRunner::class)->run(0, function (string &$phase) use (&$calls) {
            $phase = 'champ_character_lock';
            if (++$calls === 1) {
                usleep(2_100_000);
                throw $this->queryError(1205);
            }

            return ['ok' => true];
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }

    public function test_later_contention_still_stops_at_the_existing_elapsed_budget(): void
    {
        Log::spy();
        $calls = 0;
        $result = app(ChampBattleTransactionRunner::class)->run(0, function (string &$phase) use (&$calls) {
            $phase = 'battle_log_save';
            $calls++;
            usleep(2_100_000);
            throw $this->queryError(1205);
        });

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $calls);
        $this->assertStringNotContainsString('ほかの冒険者のチャンプ戦', $result['message']);
    }

    public function test_persistent_early_incumbent_wait_is_limited_to_three_attempts(): void
    {
        Log::spy();
        $calls = 0;
        $result = app(ChampBattleTransactionRunner::class)->run(0, function (string &$phase) use (&$calls) {
            $phase = 'champ_character_lock';
            $calls++;
            throw $this->queryError(1205);
        });

        $this->assertFalse($result['ok']);
        $this->assertSame(3, $calls);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['phase'] === 'champ_character_lock'
            && $context['attempts'] === 3
            && $context['database_error_code'] === 1205
        );
    }

    private function character(): Character
    {
        ChampState::firstOrFail()->update([
            'character_id' => null, 'level' => 1, 'current_hp' => 1000000,
            'max_hp' => 1000000, 'atk' => 10000, 'def' => 10000, 'spd' => 100,
            'appointed_at' => now()->subDay(),
        ]);

        return Character::create([
            'user_id' => User::factory()->create()->id, 'name' => '競合検証者',
            'level' => 1, 'exp' => 99, 'hp_base' => 100, 'current_hp' => 100,
            'mp_base' => 0, 'current_mp' => 0, 'attack_base' => 10,
            'defense_base' => 8, 'speed_base' => 8, 'magic_base' => 8,
            'spirit_base' => 8, 'luck_base' => 5,
        ]);
    }

    private function queryError(int $code): QueryException
    {
        $previous = new PDOException('Injected database failure');
        $previous->errorInfo = ['HY000', $code, 'Injected database failure'];

        return new QueryException('mysql', 'test query', [], $previous);
    }
}
