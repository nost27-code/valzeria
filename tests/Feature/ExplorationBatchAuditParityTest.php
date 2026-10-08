<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\User;
use App\Services\BattleLogService;
use App\Services\CharacterStatusService;
use App\Services\ExplorationService;
use App\Services\ExplorationStaminaService;
use App\Services\GoldService;
use App\Services\LevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ExplorationBatchAuditParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_repeat_loop_keeps_rewards_logs_discoveries_and_milestones_on_completion_and_stops(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        config(['exploration_performance.batch_reads_enabled' => true, 'nameless_relics.enabled' => true]);
        request()->setMethod('POST');
        request()->attributes->set('committed_exploration_token', '00000000-0000-4000-8000-000000000003');
        $hero = Character::create(['user_id' => User::factory()->create(['role' => 'user'])->id,
            'name' => 'Synthetic sequential explorer', 'level' => 1, 'current_hp' => 100, 'hp_base' => 100,
            'current_mp' => 100, 'mp_base' => 100, 'money' => 100, 'explore_stamina' => 50,
            'explore_stamina_max' => 50, 'explore_stamina_updated_at' => now(),
            'current_job_id' => JobClass::firstOrFail()->id]);
        CharacterJob::create(['character_id' => $hero->id, 'job_class_id' => $hero->current_job_id, 'job_level' => 1, 'job_exp' => 0]);
        $enemy = Enemy::create(['name' => 'Synthetic sequential enemy', 'area_id' => Area::firstOrFail()->id, 'is_boss' => false]);
        $stamina = Mockery::mock(ExplorationStaminaService::class);
        $stamina->shouldReceive('enabled')->andReturnTrue();
        $stamina->shouldReceive('summary')->andReturnUsing(fn (Character $c) => ['enabled' => true, 'current' => (int) $c->explore_stamina, 'max' => 50, 'cost' => 1]);
        $this->app->instance(ExplorationStaminaService::class, $stamina);
        $stats = Mockery::mock(CharacterStatusService::class);
        $stats->shouldReceive('getFinalStats')->andReturn(['max_hp' => 100, 'max_mp' => 100]);
        $this->app->instance(CharacterStatusService::class, $stats);
        $cases = ['complete' => [50, null], 'hp_pinch' => [2, 'hp_pinch'],
            'stamina_empty' => [2, 'stamina_empty'], 'defeat' => [2, 'defeat'],
            'timeout' => [2, 'timeout'], 'event' => [2, 'hidden_area_gate'],
            'shortage' => [0, 'stamina_shortage'], 'initial_hp_pinch' => [0, 'hp_pinch']];
        try {
            foreach ($cases as $case => [$completed, $stop]) {
                $variants = [];
                foreach ([false, true, 'state'] as $batched) {
                    config(['exploration_performance.batch_discoveries_enabled' => (bool) $batched,
                        'exploration_performance.batch_state_enabled' => $batched === 'state']);
                    DB::beginTransaction();
                    try {
                        $character = $hero->fresh();
                        if ($case === 'shortage') {
                            $character->update(['explore_stamina' => 49]);
                        } elseif ($case === 'initial_hp_pinch') {
                            $character->update(['current_hp' => 25]);
                        }
                        $service = new SyntheticAuditedExploration($enemy, $case);
                        $result = $service->exploreRepeated($character, $enemy->area_id, 50);
                        $this->assertSame($completed, data_get($result, 'batch_explore.completed'), $case);
                        $this->assertSame($stop, data_get($result, 'batch_explore.stop_reason'), $case);
                        $this->assertSame($completed, $service->calls, $case);
                        $snapshot = ['result' => $result, 'character' => $character->fresh()->getAttributes()];
                        foreach (['character_jobs', 'gold_transactions', 'battle_logs', 'character_enemy_discoveries', 'player_lifecycle_events'] as $table) {
                            $snapshot[$table] = DB::table($table)->where('character_id', $hero->id)->orderBy('id')->get()->toArray();
                        }
                        $this->assertCount($completed, $snapshot['battle_logs']);
                        $this->assertSame((int) $character->money, (int) ($snapshot['gold_transactions'][count($snapshot['gold_transactions']) - 1]->balance_after ?? 100));
                        $balance = 100;
                        foreach ($snapshot['gold_transactions'] as $ledger) {
                            $balance += (int) $ledger->amount;
                            $this->assertSame($balance, (int) $ledger->balance_after);
                        }
                        $this->assertSame((int) $character->money, $balance);
                        $this->assertSame((int) ($result['gold_gained'] ?? 0), array_sum(array_column($snapshot['battle_logs'], 'gold_gained')));
                        $variants[] = $snapshot;
                    } finally {
                        DB::rollBack();
                        CharacterStatusService::clearRequestCache();
                    }
                }
                $this->assertEquals($variants[0], $variants[1], $case);
                $this->assertEquals($variants[0], $variants[2], $case);
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}

/** Real repeat loop/reward/ledger/log/discovery services; only battle outcome/stamina are scripted. */
class SyntheticAuditedExploration extends ExplorationService
{
    public int $calls = 0;

    public function __construct(private Enemy $enemy, private string $stopCase) {}

    public function explore(Character $character, int $areaId, bool $isBossBattle = false, ?string $forcedEvent = null, bool $skipBattleCooldown = false): array
    {
        $this->calls++;
        $result = $this->calls === 2 && in_array($this->stopCase, ['defeat', 'timeout', 'event'], true)
            ? $this->stopCase : 'victory';
        $gold = $result === 'victory' ? 3 : 0;
        $exp = $result === 'victory' ? 10 : 0;
        $jobExp = $result === 'victory' ? 1 : 0;
        $reward = app(LevelService::class)->addRewardAndCheckLevelUp($character, $exp, $gold, $jobExp);
        if (in_array($result, ['defeat', 'timeout'], true)) {
            app(GoldService::class)->spend($character, 10, 'battle_loss', 'Synthetic defeat');
        }
        $character->explore_stamina--;
        if ($this->calls === 2 && $this->stopCase === 'hp_pinch') {
            $character->current_hp = 25;
        } elseif ($this->calls === 2 && $this->stopCase === 'stamina_empty') {
            $character->explore_stamina = 0;
        }
        $character->save();
        app(BattleLogService::class)->addLog($character, $areaId, $this->enemy->id, 'normal', $result,
            $exp, $gold, $jobExp, $reward['level_up_count'], 'Synthetic round '.$this->calls,
            goldLost: in_array($result, ['defeat', 'timeout'], true) ? 10 : 0);

        return ['result' => $result, 'enemy' => $this->enemy, 'turn_count' => 1, 'log' => '',
            'exp_gained' => $exp, 'gold_gained' => $gold, 'job_exp_gained' => $jobExp,
            'level_up_details' => $reward['details'], 'special_event' => $result === 'event' ? 'hidden_area_gate' : null];
    }
}
