<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\PvPBattleService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PvPBattleServiceTurnLimitTest extends TestCase
{
    public function test_equal_remaining_hp_ratio_keeps_the_defense_result_after_one_hundred_turns(): void
    {
        $service = $this->service(
            challengerMaxHp: 2_000,
            defenderMaxHp: 1_000,
        );

        $result = $service->executeBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );

        $this->assertSame('defeat', $result->result);
        $this->assertCount(200, $service->observedBattleTypes);
        $this->assertSame(['pvp'], array_values(array_unique($service->observedBattleTypes)));
        $this->assertStringContainsString('--- ターン 100 ---', implode("\n", $result->logs));
        $this->assertStringNotContainsString('--- ターン 101 ---', implode("\n", $result->logs));
        $this->assertStringContainsString('防衛に成功した', implode("\n", $result->logs));
    }

    public function test_higher_remaining_hp_ratio_wins_even_when_challenger_has_less_raw_hp(): void
    {
        $service = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );

        $result = $service->executeBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );

        $this->assertSame('victory', $result->result);
        $this->assertCount(200, $service->observedBattleTypes);
        $this->assertStringContainsString('判定勝利', implode("\n", $result->logs));
    }

    public function test_lower_remaining_hp_ratio_loses_even_when_challenger_has_more_raw_hp(): void
    {
        $service = $this->service(
            challengerDamagePerAction: 4,
            defenderDamagePerAction: 10,
            challengerMaxHp: 2_000,
            defenderMaxHp: 1_000,
        );

        $result = $service->executeBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );

        $this->assertSame('defeat', $result->result);
        $this->assertCount(200, $service->observedBattleTypes);
        $this->assertStringContainsString('防衛に成功した', implode("\n", $result->logs));
    }

    public function test_player_and_npc_rank_battles_share_the_remaining_hp_ratio_judgment(): void
    {
        $pvpService = file_get_contents(app_path('Services/PvPBattleService.php'));
        $npcService = file_get_contents(app_path('Services/ArenaNpcBattleService.php'));

        $this->assertIsString($pvpService);
        $this->assertIsString($npcService);
        $this->assertStringContainsString(
            '$attackerActor->hasHigherRemainingHpRatioThan($defenderActor)',
            $pvpService,
        );
        $this->assertStringContainsString(
            '$attackerActor->hasHigherRemainingHpRatioThan($npcActor)',
            $npcService,
        );
    }

    private function service(
        int $challengerDamagePerAction = 0,
        int $defenderDamagePerAction = 0,
        int $challengerMaxHp = 1_000,
        int $defenderMaxHp = 1_000,
    ): PvPBattleService
    {
        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->twice()->andReturn(
            $this->stats($challengerMaxHp),
            $this->stats($defenderMaxHp),
        );

        $jobArtSupport = Mockery::mock(JobArtBattleSupportService::class);
        $jobArtSupport->shouldReceive('attachBossSet')->twice();
        $jobArtSupport->shouldReceive('registerHpHealingResolver')->once();
        $jobArtSupport->shouldReceive('usesRoleEffects')->times(200)->andReturnFalse();
        $jobArtSupport->shouldReceive('endRound')->times(100)->andReturn([]);
        $jobArtSupport->shouldReceive('battleHud')->once()->andReturnNull();

        DB::shouldReceive('transaction')->once()->andReturnNull();

        return new class(
            $statusService,
            Mockery::mock(DamageCalculator::class),
            $jobArtSupport,
            $challengerDamagePerAction,
            $defenderDamagePerAction,
        ) extends PvPBattleService {
            /** @var list<string> */
            public array $observedBattleTypes = [];

            public function __construct(
                CharacterStatusService $statusService,
                DamageCalculator $damageCalculator,
                JobArtBattleSupportService $jobArtBattleSupport,
                private readonly int $challengerDamagePerAction,
                private readonly int $defenderDamagePerAction,
            ) {
                parent::__construct($statusService, $damageCalculator, $jobArtBattleSupport);
            }

            protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->observedBattleTypes[] = $state->battleType;

                if ($attacker->name === '挑戦者' && $this->challengerDamagePerAction > 0) {
                    $defender->takeDamage($this->challengerDamagePerAction);
                }
                if ($attacker->name === '防衛者' && $this->defenderDamagePerAction > 0) {
                    $defender->takeDamage($this->defenderDamagePerAction);
                }
            }
        };
    }

    /** @return array{max_hp: int, max_mp: int, str: int, def: int, agi: int, mag: int, spr: int, luk: int} */
    private function stats(int $maxHp): array
    {
        return [
            'max_hp' => $maxHp,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ];
    }

    private function character(string $name): Character
    {
        $character = new Character(['name' => $name]);
        $character->setRelation('currentJob', null);

        return $character;
    }
}
