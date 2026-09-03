<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2ResourceService;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class JobArtV2RuntimeOverwriteRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_battle_service_never_mutates_a_master_without_crown_balance_metadata(): void
    {
        $actor = $this->actor(20);
        $target = $this->actor(null, false);
        $skill = new Skill([
            'name' => '掘り出し物',
            'skill_type' => 'job_art',
            'job_id' => 20,
            'learn_rank' => 5,
            'effect_template' => 'REWARD_MIXED',
            'damage_type' => 'support',
            'power' => 0,
            'power_multiplier' => 0,
            'hit_count' => 0,
            'duration_turns' => 2,
            'reward_scope' => 'normal_exploration_win_only',
        ]);
        $skill->setAttribute('id', 2_005);
        $this->equip($actor, $skill, 'current', 1.0);
        $masterAttributes = $skill->getAttributes();

        $this->assertNull(app(JobArtV2CrownBalanceCatalog::class)->forArt($skill));

        $this->executePveJobArt($actor, $target, $skill);

        $this->assertSame($masterAttributes, $skill->getAttributes());
        $this->assertSame('REWARD_MIXED', $skill->effect_template);
        $this->assertSame('normal_exploration_win_only', $skill->reward_scope);
    }

    public function test_crown_guard_uses_only_the_v2_one_shot_reduction_for_current_and_inherited_cards(): void
    {
        $ranks = [
            1 => ['name' => '聖冠加護', 'power' => 225, 'rate' => 0.25],
            5 => ['name' => '聖冠大結界', 'power' => 285, 'rate' => 0.35],
            9 => ['name' => '聖冠アイギスロード', 'power' => 355, 'rate' => 0.45],
        ];
        $contexts = [
            'current' => ['job_id' => 66, 'origin' => 'current', 'inheritance_rate' => 1.0],
            'inherited' => ['job_id' => 68, 'origin' => 'inherited', 'inheritance_rate' => 0.7],
        ];

        foreach ($contexts as $context => $configuration) {
            foreach ($ranks as $rank => $expected) {
                $actor = $this->actor($configuration['job_id']);
                $target = $this->actor(null, false);
                $skill = $this->guardArt($rank, $expected['name'], $expected['power']);
                $this->equip(
                    $actor,
                    $skill,
                    $configuration['origin'],
                    $configuration['inheritance_rate'],
                );
                $masterAttributes = $skill->getAttributes();

                $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);

                $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template, "{$context}:rank{$rank}");
                $this->assertSame(0, (int) $execution->damage_reduction_percent, "{$context}:rank{$rank}");

                $this->executePveJobArt($actor, $target, $skill);

                $this->assertSame(0, $actor->damageReductionRate, "{$context}:rank{$rank}");
                $this->assertSame($expected['rate'], $actor->jobArtV2GuardState()?->rate, "{$context}:rank{$rank}");

                $incomingAttacker = $this->actor(null, false);
                $incomingState = new BattleState($incomingAttacker, $actor, 'pve');
                app(JobArtV2ResourceService::class)->beginAction($incomingAttacker, $incomingState);
                $sourceActionId = $incomingState->currentSourceActionId();
                $this->assertNotNull($sourceActionId, "{$context}:rank{$rank}");
                $reducedDamage = app(JobArtV2DefenseService::class)->resolveDamage(
                    $incomingState,
                    new DirectAttackResolution(
                        sourceActionId: $sourceActionId,
                        attacker: $incomingAttacker,
                        target: $actor,
                        hitResult: HitResult::HIT,
                        damageCategory: 'physical',
                        direct: true,
                        actionType: BattleActionType::NORMAL_ATTACK,
                    ),
                    100,
                );
                $this->assertSame(
                    (int) round(100 * (1 - $expected['rate'])),
                    $reducedDamage,
                    "{$context}:rank{$rank}",
                );
                $this->assertNull($actor->jobArtV2GuardState(), "{$context}:rank{$rank}");
                $this->assertSame($masterAttributes, $skill->getAttributes(), "{$context}:rank{$rank}");
            }
        }
    }

    public function test_crown_guard_flag_off_keeps_the_legacy_execution_values_fail_closed(): void
    {
        $actor = $this->actor(66);
        $skill = $this->guardArt(5, '聖冠大結界', 285);
        $this->equip($actor, $skill, 'current', 1.0);
        config(['battle.job_art_v2.resources' => false]);

        $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);

        $this->assertSame('MAGICAL_DAMAGE_BUFF', $execution->effect_template);
        $this->assertSame(35, (int) $execution->damage_reduction_percent);
        $this->assertNull($skill->getAttribute('damage_reduction_percent'));
    }

    public function test_battle_service_records_any_rank_five_chain_for_crown_pierce(): void
    {
        $actor = $this->actor(62);
        $target = $this->actor(null, false);
        $otherLineageRankFive = $this->jobArt(105, 1, 5, '受け返し', 145);
        $crownPierce = $this->jobArt(6_209, 62, 9, '竜冠天穿槍', 355);
        $actor->jobArts = [$otherLineageRankFive, $crownPierce];
        $actor->jobArtOrigins = [
            (int) $otherLineageRankFive->id => 'inherited',
            (int) $crownPierce->id => 'current',
        ];
        $actor->jobArtRates = [
            (int) $otherLineageRankFive->id => 1.0,
            (int) $crownPierce->id => 1.0,
        ];
        $rankFiveMasterAttributes = $otherLineageRankFive->getAttributes();
        $ultimateMasterAttributes = $crownPierce->getAttributes();

        $this->executePveJobArt($actor, $target, $otherLineageRankFive);

        $this->assertTrue($actor->jobArtV2ProgressionState()->crownPierceChainUsed);
        $execution = app(JobArtBattleSupportService::class)->skillForExecution(
            $actor,
            $crownPierce,
            new BattleState($actor, $target, 'pve'),
            $target,
        );
        $this->assertSame(470, (int) $execution->power);
        $this->assertSame(4.7, (float) $execution->power_multiplier);
        $this->assertSame($rankFiveMasterAttributes, $otherLineageRankFive->getAttributes());
        $this->assertSame($ultimateMasterAttributes, $crownPierce->getAttributes());
    }

    public function test_crown_pierce_chain_history_stays_fail_closed_when_resources_are_disabled(): void
    {
        $actor = $this->actor(62);
        $target = $this->actor(null, false);
        $otherLineageRankFive = $this->jobArt(105, 1, 5, '受け返し', 145);
        $crownPierce = $this->jobArt(6_209, 62, 9, '竜冠天穿槍', 355);
        $actor->jobArts = [$otherLineageRankFive, $crownPierce];
        $actor->jobArtOrigins = [
            (int) $otherLineageRankFive->id => 'inherited',
            (int) $crownPierce->id => 'current',
        ];
        $actor->jobArtRates = [
            (int) $otherLineageRankFive->id => 1.0,
            (int) $crownPierce->id => 1.0,
        ];
        config(['battle.job_art_v2.resources' => false]);

        $this->executePveJobArt($actor, $target, $otherLineageRankFive);

        $this->assertFalse($actor->jobArtV2ProgressionState()->crownPierceChainUsed);
        $execution = app(JobArtBattleSupportService::class)->skillForExecution(
            $actor,
            $crownPierce,
            new BattleState($actor, $target, 'pve'),
            $target,
        );
        $this->assertSame(355, (int) $execution->power);
        $this->assertSame(3.55, (float) $execution->power_multiplier);
    }

    private function executePveJobArt(BattleActor $actor, BattleActor $target, Skill $skill): void
    {
        $state = new BattleState($actor, $target, 'pve');
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        $service = app(BattleService::class);
        $hitResolver = Mockery::mock(ActionResolver::class);
        $hitResolver->shouldReceive('resolveJobArt')->once()->andReturn(HitResult::HIT);
        (new ReflectionProperty(BattleService::class, 'jobArtActionResolver'))->setValue($service, $hitResolver);

        $method = new ReflectionMethod($service, 'executeJobArtAction');
        $method->setAccessible(true);
        $method->invoke($service, $actor, $target, $state, $skill);
    }

    private function equip(BattleActor $actor, Skill $skill, string $origin, float $rate): void
    {
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[(int) $skill->id] = $origin;
        $actor->jobArtRates[(int) $skill->id] = $rate;
    }

    private function guardArt(int $rank, string $name, int $power): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => 66,
            'learn_rank' => $rank,
            'effect_template' => 'MAGICAL_DAMAGE_BUFF',
            'damage_type' => 'magical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => 1,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', 6_600 + $rank);

        return $skill;
    }

    private function jobArt(int $id, int $jobId, int $rank, string $name, int $power): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => 1,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function actor(?int $jobId, bool $player = true): BattleActor
    {
        return new BattleActor('actor', $player, [
            'hp' => 100_000,
            'max_hp' => 100_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 1_000,
            'def' => 500,
            'agi' => 100,
            'mag' => 1_000,
            'spr' => 500,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }
}
