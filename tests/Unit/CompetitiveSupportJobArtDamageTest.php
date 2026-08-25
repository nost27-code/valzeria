<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\ArenaNpcBattleService;
use App\Services\ArenaNpcRankingService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\LevelService;
use App\Services\PvPBattleService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CompetitiveSupportJobArtDamageTest extends TestCase
{
    #[DataProvider('competitiveRoutes')]
    public function test_pure_support_job_art_never_deals_competitive_damage(string $route): void
    {
        $result = $this->executeJobArt($route, 'SELF_BUFF', 'support');

        $this->assertSame(0, $result['calculator']->calls, $route);
        $this->assertSame(1_000, $result['defender']->hp, $route);
        $this->assertSame(0, (int) ($result['action']['damage'] ?? 0), $route);
        $this->assertStringNotContainsString('のダメージ！', $result['log'], $route);
        $this->assertGreaterThan(100, $result['attacker']->str, $route);
    }

    #[DataProvider('competitiveRoutes')]
    public function test_damage_buff_job_art_keeps_competitive_damage(string $route): void
    {
        $result = $this->executeJobArt($route, 'DAMAGE_BUFF', 'physical');

        $this->assertSame(1, $result['calculator']->calls, $route);
        $this->assertSame(['physical'], $result['calculator']->attackTypes, $route);
        $this->assertSame(40, (int) ($result['action']['damage'] ?? 40), $route);
        $this->assertStringContainsString('text-red-600', $result['log'], $route);
        $this->assertStringNotContainsString('text-purple-600', $result['log'], $route);
        $this->assertStringContainsString('40</span> のダメージ！', $result['log'], $route);
        $this->assertGreaterThan(100, $result['attacker']->str, $route);

        if ($route === 'champ') {
            $this->assertSame(1, $result['calculator']->duelCalls, $route);
            $this->assertSame(0, $result['calculator']->rankCalls, $route);
        } else {
            $this->assertSame(0, $result['calculator']->duelCalls, $route);
            $this->assertSame(1, $result['calculator']->rankCalls, $route);
        }

        if ($route !== 'champ') {
            $this->assertSame(960, $result['defender']->hp, $route);
        }
    }

    #[DataProvider('competitiveRoutes')]
    public function test_magical_job_art_uses_magical_damage_color_in_every_competitive_route(string $route): void
    {
        $result = $this->executeJobArt($route, 'MAGICAL_DAMAGE', 'magical');

        $this->assertSame(1, $result['calculator']->calls, $route);
        $this->assertSame(['magical'], $result['calculator']->attackTypes, $route);
        $this->assertStringContainsString('text-purple-600', $result['log'], $route);
        $this->assertStringNotContainsString('text-red-600', $result['log'], $route);
        $this->assertStringContainsString('40</span> のダメージ！', $result['log'], $route);
    }

    /** @return array<string, array{string}> */
    public static function competitiveRoutes(): array
    {
        return [
            'player pvp' => ['pvp'],
            'champ' => ['champ'],
            'npc arena' => ['arena_npc'],
        ];
    }

    /**
     * @return array{
     *     calculator: DamageCalculator&object{calls:int},
     *     attacker: BattleActor,
     *     defender: BattleActor,
     *     action: array<string, mixed>,
     *     log: string
     * }
     */
    private function executeJobArt(string $route, string $template, string $damageType): array
    {
        $calculator = new class extends DamageCalculator
        {
            public int $calls = 0;

            public int $duelCalls = 0;

            public int $rankCalls = 0;

            /** @var list<string> */
            public array $attackTypes = [];

            public function calculateDuelDamage(
                BattleActor $attacker,
                BattleActor $defender,
                string $attackType,
                int $skillPower = 100,
                bool $isCritical = false,
                float $affinityMultiplier = 1.0,
                ?int $overrideAtk = null,
                ?int $overrideDef = null,
                ?int $overrideSpr = null,
            ): int {
                $this->calls++;
                $this->duelCalls++;
                $this->attackTypes[] = $attackType;

                return 40;
            }

            public function calculateRankBattleDamage(
                BattleActor $attacker,
                BattleActor $defender,
                string $attackType,
                int $skillPower = 100,
                bool $isCritical = false,
                float $affinityMultiplier = 1.0,
                ?int $overrideAtk = null,
                ?int $overrideDef = null,
                ?int $overrideSpr = null,
                bool $isSkill = false,
                int $hitCount = 1,
                bool $minimumDamageGuaranteeEnabled = true,
                bool $damageCapEnabled = true,
                float $baseDamageMultiplier = 1.0,
                float $additionalDefenseIgnoreRate = 0.0,
            ): int {
                $this->calls++;
                $this->rankCalls++;
                $this->attackTypes[] = $attackType;

                return 40;
            }
        };
        $support = $this->support();
        $attacker = $this->actor('攻撃側', true);
        $defender = $this->actor('防御側', false);
        $state = new BattleState($attacker, $defender, $route);
        $skill = new Skill([
            'name' => '闇の契約',
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $damageType,
            'power' => 110,
            'power_multiplier' => 1.1,
            'hit_count' => 0,
        ]);
        $skill->setAttribute('id', 9_001);
        $attacker->jobArtRates[9_001] = 1.0;

        $action = [];
        if ($route === 'pvp') {
            $service = new PvPBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $support,
            );
            $this->invoke($service, 'executeSkillAction', [
                $attacker,
                $defender,
                $state,
                $skill,
                false,
                null,
            ]);
        } elseif ($route === 'champ') {
            $service = new ChampBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $this->createMock(LevelService::class),
                $support,
            );
            $action = $this->invoke($service, 'skillAttack', [
                $attacker,
                $defender,
                $skill,
                $state,
                '《闇の契約》が発動！',
                null,
            ]);
        } else {
            $service = new ArenaNpcBattleService(
                $this->createMock(CharacterStatusService::class),
                $calculator,
                $this->createMock(ArenaNpcRankingService::class),
                $support,
            );
            $this->invoke($service, 'executeSkillAction', [
                $attacker,
                $defender,
                $state,
                $skill,
                false,
                null,
            ]);
        }

        return [
            'calculator' => $calculator,
            'attacker' => $attacker,
            'defender' => $defender,
            'action' => $action,
            'log' => $route === 'champ'
                ? (string) ($action['log'] ?? '')
                : implode('\n', $state->logs),
        ];
    }

    private function support(): JobArtBattleSupportService
    {
        $support = $this->createMock(JobArtBattleSupportService::class);
        $support->method('isFieldOnlyArt')->willReturn(false);
        $support->method('defenseOverrides')->willReturn(['def' => null, 'spr' => null]);
        $support->method('damageStatOverrides')->willReturn(['attack' => null, 'def' => null, 'spr' => null]);
        $support->method('modifyFieldDamage')->willReturnArgument(2);
        $support->method('modifyJobArtDamage')->willReturnArgument(3);
        $support->method('usesDamageApplication')->willReturn(false);
        $support->method('applySharedSelfBuff')->willReturn(null);
        $support->method('applyTimedStructuredDebuffs')->willReturn(null);

        return $support;
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
            'normal_attack_type' => 'physical',
        ]);
    }

    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
