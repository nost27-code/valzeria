<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\NullPvPRoomRule;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\PvPBattleService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PvPBattleEngineSeparationTest extends TestCase
{
    public function test_resolve_battle_returns_the_pvp_resolution_without_arena_persistence(): void
    {
        DB::shouldReceive('transaction')->never();

        $service = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );

        $attacker = $this->character('挑戦者');
        $defender = $this->character('防衛者');
        $attackerAttributes = $attacker->getAttributes();
        $defenderAttributes = $defender->getAttributes();

        $resolution = $service->resolveBattle($attacker, $defender);

        $this->assertSame('victory', $resolution->result->result);
        $this->assertTrue($resolution->attackerWon);
        $this->assertSame(100, $resolution->turnCount);
        $this->assertSame(600, $resolution->attackerHp);
        $this->assertSame(1_000, $resolution->attackerMaxHp);
        $this->assertSame(1_000, $resolution->defenderHp);
        $this->assertSame(2_000, $resolution->defenderMaxHp);
        $this->assertEqualsWithDelta(0.6, $resolution->attackerHpRatio(), 0.000_001);
        $this->assertEqualsWithDelta(0.5, $resolution->defenderHpRatio(), 0.000_001);
        $this->assertSame(['pvp'], array_values(array_unique($service->observedBattleTypes)));
        $this->assertSame($attackerAttributes, $attacker->getAttributes());
        $this->assertSame($defenderAttributes, $defender->getAttributes());
        $this->assertStringContainsString(
            '【闘技場】挑戦者 が 防衛者 に勝負を挑んだ！',
            implode("\n", $resolution->result->logs),
        );

        $source = file_get_contents(app_path('Services/PvPBattleService.php'));
        $this->assertIsString($source);
        $resolveSection = explode('private function persistArenaBattleOutcome(', $source, 2)[0];
        $this->assertStringNotContainsString('DB::transaction', $resolveSection);
        $this->assertStringNotContainsString('ArenaLog::', $resolveSection);
        $this->assertStringNotContainsString('ArenaNpcRankingService::class', $resolveSection);
        $this->assertStringNotContainsString('CharacterNotificationService::class', $resolveSection);
        $this->assertStringNotContainsString('PublicLogService::class', $resolveSection);
    }

    public function test_execute_battle_delegates_to_resolution_then_persists_the_arena_outcome(): void
    {
        DB::shouldReceive('transaction')->once()->andReturnNull();

        $directService = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );
        $facadeService = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );

        $directResolution = $directService->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            PvPBattleExecutionContext::arena(),
        );
        $facadeResult = $facadeService->executeBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );
        $facadeResolution = $facadeService->lastResolution;

        $this->assertInstanceOf(PvPBattleResolution::class, $facadeResolution);
        $this->assertSame(1, $facadeService->resolveCalls);
        $this->assertSame($directResolution->result->result, $facadeResult->result);
        $this->assertSame($directResolution->result->logs, $facadeResult->logs);
        $this->assertSame($directResolution->attackerWon, $facadeResolution->attackerWon);
        $this->assertSame($directResolution->turnCount, $facadeResolution->turnCount);
        $this->assertSame($directResolution->attackerHp, $facadeResolution->attackerHp);
        $this->assertSame($directResolution->defenderHp, $facadeResolution->defenderHp);
        $this->assertStringContainsString('【闘技場】', implode("\n", $facadeResult->logs));
    }

    public function test_default_and_explicit_arena_context_resolve_to_the_same_battle(): void
    {
        DB::shouldReceive('transaction')->never();

        $defaultService = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );
        $explicitService = $this->service(
            challengerDamagePerAction: 10,
            defenderDamagePerAction: 4,
            challengerMaxHp: 1_000,
            defenderMaxHp: 2_000,
        );

        $defaultResolution = $defaultService->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
        );
        $explicitResolution = $explicitService->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(
                displayLabel: '闘技場',
                jobArtContext: 'champ',
                roomRule: new NullPvPRoomRule(),
            ),
        );

        $this->assertSame($defaultResolution->result->result, $explicitResolution->result->result);
        $this->assertSame($defaultResolution->result->logs, $explicitResolution->result->logs);
        $this->assertSame($defaultResolution->attackerWon, $explicitResolution->attackerWon);
        $this->assertSame($defaultResolution->turnCount, $explicitResolution->turnCount);
        $this->assertSame($defaultResolution->attackerHp, $explicitResolution->attackerHp);
        $this->assertSame($defaultResolution->defenderHp, $explicitResolution->defenderHp);
    }

    public function test_execution_context_applies_the_minimum_damage_policy_to_the_battle_state(): void
    {
        DB::shouldReceive('transaction')->never();

        $service = $this->service();
        $service->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(
                rankBattleMinimumDamageGuaranteeEnabled: false,
            ),
        );

        $this->assertSame(
            [false],
            array_values(array_unique($service->observedMinimumDamageGuarantees)),
        );
    }

    public function test_execution_context_applies_the_damage_cap_policy_to_the_battle_state(): void
    {
        DB::shouldReceive('transaction')->never();

        $service = $this->service();
        $service->resolveBattle(
            $this->character('挑戦者'),
            $this->character('防衛者'),
            new PvPBattleExecutionContext(
                rankBattleDamageCapEnabled: false,
            ),
        );

        $this->assertSame(
            [false],
            array_values(array_unique($service->observedDamageCaps)),
        );
    }

    private function service(
        int $challengerDamagePerAction = 0,
        int $defenderDamagePerAction = 0,
        int $challengerMaxHp = 1_000,
        int $defenderMaxHp = 1_000,
    ): PvPBattleService {
        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->twice()->andReturn(
            $this->stats($challengerMaxHp),
            $this->stats($defenderMaxHp),
        );

        $jobArtSupport = Mockery::mock(JobArtBattleSupportService::class);
        $jobArtSupport->shouldReceive('attachBossSet')
            ->twice()
            ->withArgs(static fn (BattleActor $actor, Character $character, string $context): bool => $context === 'champ');
        $jobArtSupport->shouldReceive('registerHpHealingResolver')->once();
        $jobArtSupport->shouldReceive('usesRoleEffects')->times(200)->andReturnFalse();
        $jobArtSupport->shouldReceive('endRound')->times(100)->andReturn([]);
        $jobArtSupport->shouldReceive('battleHud')->once()->andReturnNull();

        return new class(
            $statusService,
            Mockery::mock(DamageCalculator::class),
            $jobArtSupport,
            Mockery::mock(DamageApplicationService::class),
            $challengerDamagePerAction,
            $defenderDamagePerAction,
        ) extends PvPBattleService {
            /** @var list<string> */
            public array $observedBattleTypes = [];

            /** @var list<bool> */
            public array $observedMinimumDamageGuarantees = [];

            /** @var list<bool> */
            public array $observedDamageCaps = [];

            public int $resolveCalls = 0;

            public ?PvPBattleResolution $lastResolution = null;

            public function __construct(
                CharacterStatusService $statusService,
                DamageCalculator $damageCalculator,
                JobArtBattleSupportService $jobArtBattleSupport,
                DamageApplicationService $damageApplicationService,
                private readonly int $challengerDamagePerAction,
                private readonly int $defenderDamagePerAction,
            ) {
                parent::__construct(
                    $statusService,
                    $damageCalculator,
                    $jobArtBattleSupport,
                    $damageApplicationService,
                );
            }

            public function resolveBattle(
                Character $attackerChar,
                Character $defenderChar,
                ?PvPBattleExecutionContext $context = null,
            ): PvPBattleResolution {
                $this->resolveCalls++;
                $this->lastResolution = parent::resolveBattle($attackerChar, $defenderChar, $context);

                return $this->lastResolution;
            }

            protected function resolveBaseInitiative(
                BattleActor $attacker,
                BattleActor $defender,
                BattleState $state,
                bool $usesRoleSpeed,
            ): bool {
                return true;
            }

            protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->observedBattleTypes[] = $state->battleType;
                $this->observedMinimumDamageGuarantees[] = $state->rankBattleMinimumDamageGuaranteeEnabled;
                $this->observedDamageCaps[] = $state->rankBattleDamageCapEnabled;

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
