<?php

namespace Tests\Unit;

use App\Models\ChampState;
use App\Models\Character;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleTurnVitalsLogFormatter;
use App\Services\Battle\DamageCalculator;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use App\Services\SixHeroBattleResultPresenter;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class BattleTurnVitalsLogTest extends TestCase
{
    public function test_formatting_escapes_names_handles_zero_sp_and_does_not_mutate_actors(): void
    {
        $player = new BattleActor('<img src=x onerror=alert(1)>', true, $this->stats());
        $opponent = new BattleActor('相手', false, $this->stats());
        $player->hp = 250;
        $player->mp = 0;
        $player->maxMp = 0;
        $opponent->mp = 200;
        $before = [get_object_vars($player), get_object_vars($opponent)];

        $log = BattleTurnVitalsLogFormatter::fromActors($player, $opponent);

        $this->assertStringContainsString('あなた（&lt;img src=x onerror=alert(1)&gt;）', $log);
        $this->assertStringNotContainsString('<img', $log);
        $this->assertStringContainsString('HP 250 / 1,000', $log);
        $this->assertStringContainsString('SP 0 / 0', $log);
        $this->assertStringContainsString('style="width: 25.00%;"', $log);
        $this->assertStringContainsString('style="width: 0.00%;"', $log);
        $this->assertStringContainsString('style="width: 100.00%;"', $log);
        $this->assertSame($before, [get_object_vars($player), get_object_vars($opponent)]);
    }

    public function test_six_hero_sanitizer_preserves_bars_but_rejects_arbitrary_styles(): void
    {
        $player = new BattleActor('挑戦者', true, $this->stats());
        $opponent = new BattleActor('防衛者', false, $this->stats());
        $player->hp = 333;
        $log = BattleTurnVitalsLogFormatter::fromActors($player, $opponent);
        $presenter = app(SixHeroBattleResultPresenter::class);
        $sanitize = new ReflectionMethod($presenter, 'sanitizeBattleLog');

        $this->assertSame($log, $sanitize->invoke($presenter, $log));
        foreach (['101.00%', '-1.00%', 'NaN%', '50.00%;background:url(javascript:alert(1))', '50.00%" onmouseover="alert(1)'] as $width) {
            $unsafe = '<span class="battle-log-vitals-fill battle-log-vitals-hp" style="width: '.$width.';">残HP</span>';
            $safe = $sanitize->invoke($presenter, $unsafe);
            $this->assertSame('残HP', $safe);
        }
    }

    public function test_pvp_snapshots_each_turn_before_actions_and_keeps_the_final_turn(): void
    {
        $status = Mockery::mock(CharacterStatusService::class);
        $status->shouldReceive('getFinalStats')->twice()->andReturn($this->stats(), $this->stats());
        $support = $this->support();
        $support->shouldReceive('registerHpHealingResolver')->once();
        $support->shouldReceive('endRound')->times(3)->andReturn([]);
        $service = new class($status, app(DamageCalculator::class), $support) extends PvPBattleService
        {
            protected function resolveBaseInitiative(BattleActor $attacker, BattleActor $defender, BattleState $state, bool $usesRoleSpeed): bool
            {
                return true;
            }

            protected function executeAction(BattleActor $actor, BattleActor $target, BattleState $state, bool $tickCooldowns = true): void
            {
                $state->addLog($actor->name.'の行動');
                $actor->mp -= 10;
                $target->takeDamage($actor->isPlayer ? 400 : 100);
                if (! $actor->isPlayer) {
                    $target->healHp(20);
                }
            }
        };

        $resolution = $service->resolveBattle($this->character('挑戦者'), $this->character('防衛者'));
        $logs = $resolution->result->logs;
        $snapshots = $this->snapshots($logs);

        $this->assertCount(3, $snapshots);
        $this->assertStringContainsString('HP 1,000 / 1,000', $snapshots[0]);
        $this->assertStringContainsString('HP 920 / 1,000', $snapshots[1]);
        $this->assertStringContainsString('HP 600 / 1,000', $snapshots[1]);
        $this->assertSame(2, substr_count($snapshots[1], 'SP 90 / 100'));
        $this->assertStringContainsString('HP 840 / 1,000', $snapshots[2]);
        $this->assertStringContainsString('HP 200 / 1,000', $snapshots[2]);
        $this->assertSame(3, $resolution->turnCount);
        $this->assertTrue($resolution->attackerWon);
        $this->assertSame(840, $resolution->attackerHp);
        $this->assertSame(0, $resolution->defenderHp);
        $this->assertSame(70, $resolution->result->playerMpAfter);
        $this->assertTurnOrder($logs, 3);
    }

    public function test_champ_snapshots_saved_starting_hp_and_sp_before_each_turn(): void
    {
        $status = Mockery::mock(CharacterStatusService::class);
        $status->shouldReceive('getFinalStats')->once()->andReturn($this->stats());
        $support = $this->support();
        $support->shouldReceive('beginAction', 'tickCooldowns', 'recordNormalAttackResolution', 'finishAction');
        $support->shouldReceive('selectForTurn')->andReturnUsing(static function (BattleActor $actor): null {
            $actor->mp -= 7;

            return null;
        });
        $support->shouldReceive('fieldAccuracyDelta')->andReturn(0);
        $support->shouldReceive('modifyFieldDamage')->andReturnUsing(static fn ($actor, $state, $damage) => $damage);
        $support->shouldReceive('usesDamageApplication')->andReturnFalse();
        $support->shouldReceive('endRound')->twice()->andReturn([]);
        $damage = Mockery::mock(DamageCalculator::class);
        $damage->shouldReceive('isHit')->andReturnTrue();
        $damage->shouldReceive('isDuelCritical')->andReturnFalse();
        $damage->shouldReceive('calculateDuelDamage')->andReturnUsing(static fn (BattleActor $actor) => $actor->isPlayer ? 350 : 40);
        $service = new ChampBattleService($status, $damage, Mockery::mock(LevelService::class), $support);
        $champ = new ChampState([
            'player_name' => 'チャンプ', 'current_hp' => 650, 'max_hp' => 1_000,
            'current_mp' => 50, 'max_mp' => 100, 'pre_equipment_mp' => 100,
            'atk' => 100, 'def' => 100, 'mag' => 100, 'spr' => 100, 'spd' => 100, 'luk' => 100,
            'level' => 1, 'defense_count' => 0, 'normal_attack_type' => 'physical',
        ]);

        $battle = (new ReflectionMethod($service, 'runBattle'))->invoke($service, $this->character('挑戦者'), $champ);
        $snapshots = $this->snapshots($battle['log']);

        $this->assertCount(2, $snapshots);
        $this->assertStringContainsString('HP 650 / 1,000', $snapshots[0]);
        $this->assertStringContainsString('SP 50 / 100', $snapshots[0]);
        $this->assertStringContainsString('HP 300 / 1,000', $snapshots[1]);
        $this->assertStringContainsString('HP 960 / 1,000', $snapshots[1]);
        $this->assertStringContainsString('SP 93 / 100', $snapshots[1]);
        $this->assertStringContainsString('SP 43 / 100', $snapshots[1]);
        $this->assertTrue($battle['champ_defeated']);
        $this->assertSame(960, $battle['challenger_hp_after_battle']);
        $this->assertSame(0, $battle['champ_hp_after_battle']);
        $this->assertSame(86, $battle['challenger_mp_after']);
        $this->assertSame(43, $battle['champ_mp_after']);
        $this->assertSame(650, (int) $champ->current_hp);
        $this->assertSame(50, (int) $champ->current_mp);
        $this->assertTurnOrder($battle['log'], 2);
    }

    private function support(): JobArtBattleSupportService
    {
        $support = Mockery::mock(JobArtBattleSupportService::class);
        $support->shouldReceive('beginBattleTelemetry', 'persistBattleTelemetry', 'attachBossSet');
        $support->shouldReceive('usesRoleEffects')->andReturnFalse();
        $support->shouldReceive('battleHud')->andReturnNull();

        return $support;
    }

    private function character(string $name): Character
    {
        $character = new Character(['name' => $name, 'level' => 1]);
        $character->setRelation('currentJob', null);
        $character->setRelation('jobClass', null);

        return $character;
    }

    private function stats(): array
    {
        return ['max_hp' => 1_000, 'max_mp' => 100, 'str' => 100, 'def' => 100,
            'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100];
    }

    private function snapshots(array $logs): array
    {
        return array_values(array_filter($logs, static fn (string $log): bool => str_starts_with($log, '<span class="battle-log-turn-vitals">')));
    }

    private function assertTurnOrder(array $logs, int $turnCount): void
    {
        for ($turn = 1; $turn <= $turnCount; $turn++) {
            $index = array_search("<br><br>--- ターン {$turn} ---", $logs, true);
            $this->assertIsInt($index);
            $this->assertStringStartsWith('<span class="battle-log-turn-vitals">', $logs[$index + 1]);
            $this->assertSame(4, substr_count($logs[$index + 1], 'class="battle-log-vitals-fill '));
        }
    }
}
