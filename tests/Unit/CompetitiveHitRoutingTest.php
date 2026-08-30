<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use ReflectionMethod;
use Tests\TestCase;

class CompetitiveHitRoutingTest extends TestCase
{
    public function test_pvp_normal_attack_uses_the_shared_player_competitive_hit_rules(): void
    {
        $calculator = $this->recordingCalculator();
        $service = new class($this->createMock(CharacterStatusService::class), $calculator, app(JobArtBattleSupportService::class)) extends PvPBattleService
        {
            public function runNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state): void
            {
                $this->executeNormalAttack($attacker, $defender, $state);
            }
        };
        $attacker = $this->actor('挑戦者', true);
        $defender = $this->actor('対戦相手', false);

        $service->runNormalAttack($attacker, $defender, new BattleState($attacker, $defender, 'pvp'));

        $this->assertSame([[100, 0.08, 84, 97, 0.0]], $calculator->hitRules);
    }

    public function test_champ_normal_attack_uses_the_shared_champ_hit_rules(): void
    {
        $calculator = $this->recordingCalculator();
        $service = new ChampBattleService(
            $this->createMock(CharacterStatusService::class),
            $calculator,
            $this->createMock(LevelService::class),
            app(JobArtBattleSupportService::class),
        );
        $attacker = $this->actor('挑戦者', true);
        $defender = $this->actor('チャンプ', false);
        $state = new BattleState($attacker, $defender, 'champ');

        (new ReflectionMethod(ChampBattleService::class, 'attack'))->invoke(
            $service,
            $attacker,
            $defender,
            1.0,
            $state,
        );

        $this->assertSame([[100, 0.15, 84, 97, 0.0]], $calculator->hitRules);
    }

    private function recordingCalculator(): DamageCalculator
    {
        return new class extends DamageCalculator
        {
            /** @var list<array{int,float,int,int,float}> */
            public array $hitRules = [];

            public function isHit(
                BattleActor $attacker,
                BattleActor $defender,
                int $skillAccuracy = 100,
                float $agiFactor = 0.5,
                int $minHitRate = 70,
                int $maxHitRate = 98,
                float $accuracyDelta = 0.0,
            ): bool {
                $this->hitRules[] = [
                    $skillAccuracy,
                    $agiFactor,
                    $minHitRate,
                    $maxHitRate,
                    $accuracyDelta,
                ];

                return false;
            }
        };
    }

    private function actor(string $name, bool $isPlayer): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }
}
