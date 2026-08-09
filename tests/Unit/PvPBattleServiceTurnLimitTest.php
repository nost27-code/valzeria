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
    public function test_time_limit_is_one_hundred_turns_and_keeps_the_existing_defense_result(): void
    {
        $service = $this->service();

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

    public function test_time_limit_keeps_the_existing_attacker_hp_judgment_victory(): void
    {
        $service = $this->service(challengerDamagePerAction: 1);

        $result = $service->executeBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );

        $this->assertSame('victory', $result->result);
        $this->assertCount(200, $service->observedBattleTypes);
        $this->assertStringContainsString('判定勝利', implode("\n", $result->logs));
    }

    private function service(int $challengerDamagePerAction = 0): PvPBattleService
    {
        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->twice()->andReturn([
            'max_hp' => 1_000,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);

        $jobArtSupport = Mockery::mock(JobArtBattleSupportService::class);
        $jobArtSupport->shouldReceive('attachBossSet')->twice();
        $jobArtSupport->shouldReceive('usesRoleEffects')->times(200)->andReturnFalse();
        $jobArtSupport->shouldReceive('endRound')->times(100)->andReturn([]);
        $jobArtSupport->shouldReceive('battleHud')->once()->andReturnNull();

        DB::shouldReceive('transaction')->once()->andReturnNull();

        return new class(
            $statusService,
            Mockery::mock(DamageCalculator::class),
            $jobArtSupport,
            $challengerDamagePerAction,
        ) extends PvPBattleService {
            /** @var list<string> */
            public array $observedBattleTypes = [];

            public function __construct(
                CharacterStatusService $statusService,
                DamageCalculator $damageCalculator,
                JobArtBattleSupportService $jobArtBattleSupport,
                private readonly int $challengerDamagePerAction,
            ) {
                parent::__construct($statusService, $damageCalculator, $jobArtBattleSupport);
            }

            protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->observedBattleTypes[] = $state->battleType;

                if ($attacker->name === '挑戦者' && $this->challengerDamagePerAction > 0) {
                    $defender->takeDamage($this->challengerDamagePerAction);
                }
            }
        };
    }

    private function character(string $name): Character
    {
        $character = new Character(['name' => $name]);
        $character->setRelation('currentJob', null);

        return $character;
    }
}
