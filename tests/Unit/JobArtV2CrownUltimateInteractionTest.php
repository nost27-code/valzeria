<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleState;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2TimedEffectState;
use Tests\TestCase;

final class JobArtV2CrownUltimateInteractionTest extends TestCase
{
    private int $nextSkillId = 1_390_000;

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
            'battle.job_art_v2.c_design_prototype' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_musou_zanshin_reduces_one_physical_action_and_converts_the_actual_mitigation_to_four_sword_momentum(): void
    {
        [$actor, $target, $state] = $this->battle(28);
        $ultimate = $this->art(28, 9, '無双一閃');
        $this->cast($actor, $target, $state, $ultimate);

        $this->assertSame(
            4,
            $actor->jobArtV2ProgressionState()->musouZanshin['remaining'],
            json_encode($actor->jobArtV2ProgressionState()->musouZanshin, JSON_UNESCAPED_UNICODE),
        );
        $sourceActionId = $this->beginAction($target, $state);
        $resolution = new DirectAttackResolution(
            $sourceActionId,
            $target,
            $actor,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );

        $this->assertSame(80, app(JobArtV2DefenseService::class)->resolveDamage($state, $resolution, 100));
        $this->assertSame(
            5,
            $actor->getResource('sword_momentum'),
            '無双残心の+4と、反撃系譜共通の実軽減成功+1をそれぞれ一度だけ得る。',
        );
        $this->assertNull($actor->jobArtV2ProgressionState()->musouZanshin);
    }

    public function test_nightmare_bonus_uses_actual_nonlethal_self_cost_and_resets_after_the_finisher(): void
    {
        [$actor, $target, $state] = $this->battle(51);
        $rankFive = $this->art(51, 5, '黒炎斬');
        $execution = $this->cast($actor, $target, $state, $rankFive);

        $this->assertSame(950, $actor->hp);
        $this->assertSame(50, $actor->jobArtV2ProgressionState()->nightmareSelfDamage);
        $this->assertSame(115, $this->progression()->modifyJobArtDamage($actor, $state, $execution, 100));

        $ultimate = $this->art(51, 9, '獄炎ナイトメア');
        $ultimateExecution = $this->cast($actor, $target, $state, $ultimate);
        $this->assertSame(105, $this->progression()->modifyJobArtDamage($actor, $state, $ultimateExecution, 100));
        $this->assertSame(0, $actor->jobArtV2ProgressionState()->nightmareSelfDamage);
    }

    public function test_alchemy_collapse_shortens_both_sides_and_uses_the_second_own_harmful_effect_as_fallback(): void
    {
        [$actor, $target, $state] = $this->battle(49);
        $actor->replaceJobArtV2TimedEffect($this->timed('harmful-a', ['def' => -0.20], 6, 1));
        $actor->replaceJobArtV2TimedEffect($this->timed('harmful-b', ['spr' => -0.10], 5, 2));
        $target->replaceJobArtV2TimedEffect($this->timed('positive', ['str' => 0.20], 7, 3));

        $this->cast($actor, $target, $state, $this->art(49, 9, '錬金大崩壊'));
        $this->assertSame(3, $actor->jobArtV2TimedEffect('harmful-a')?->remainingRounds);
        $this->assertSame(4, $target->jobArtV2TimedEffect('positive')?->remainingRounds);

        [$fallback, $fallbackTarget, $fallbackState] = $this->battle(49);
        $fallback->replaceJobArtV2TimedEffect($this->timed('harmful-a', ['def' => -0.20], 6, 4));
        $fallback->replaceJobArtV2TimedEffect($this->timed('harmful-b', ['spr' => -0.10], 5, 5));
        $this->cast($fallback, $fallbackTarget, $fallbackState, $this->art(49, 9, '錬金大崩壊'));

        $this->assertSame(3, $fallback->jobArtV2TimedEffect('harmful-a')?->remainingRounds);
        $this->assertSame(2, $fallback->jobArtV2TimedEffect('harmful-b')?->remainingRounds);
    }

    public function test_black_crown_reversal_resolves_after_healing_and_remains_nonlethal(): void
    {
        [$actor, $target, $state] = $this->battle(61);
        $this->cast($actor, $target, $state, $this->art(61, 9, '黒冠アビスブレイク'));
        $target->hp = 100;

        $actual = app(\App\Services\JobArtV2FieldService::class)->applyHpHeal($target, $state, 200);

        $this->assertSame(100, $actual, 'The incoming 200-point heal is halved before application.');
        $this->assertSame(100, $target->hp, 'The prevented 100 points are dealt after healing, not before it.');
        $this->assertNull($target->jobArtV2ProgressionState()->blackCrownReversal);
        $this->assertSame(0, $target->jobArtV2ProgressionState()->pendingBlackCrownReversalDamage);
    }

    public function test_black_crown_reversal_expires_into_five_percent_nonlethal_damage_when_no_heal_occurs(): void
    {
        [$actor, $target, $state] = $this->battle(61);
        $this->cast($actor, $target, $state, $this->art(61, 9, '黒冠アビスブレイク'));
        $target->hp = 40;

        for ($turn = 1; $turn <= 5; $turn++) {
            $state->turnCount = $turn;
            $this->progression()->endRound($state);
        }

        $this->assertSame(1, $target->hp);
        $this->assertNull($target->jobArtV2ProgressionState()->blackCrownReversal);
    }

    public function test_holy_wall_converts_only_overheal_and_absorbs_the_next_direct_action(): void
    {
        [$actor, $target, $state] = $this->battle(36);
        $actor->hp = 950;

        $this->cast($actor, $target, $state, $this->art(36, 9, '神域審判', 'MAGICAL_DAMAGE'));

        $wall = $actor->jobArtV2ProgressionState()->holyWall;
        $this->assertSame(70, $wall['amount']);
        $this->assertSame(4, $wall['remaining']);

        $sourceActionId = $this->beginAction($target, $state);
        $resolution = new DirectAttackResolution(
            $sourceActionId,
            $target,
            $actor,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(30, $this->progression()->absorbHolyWall($actor, $state, $resolution, 100));
        $this->assertNull($actor->jobArtV2ProgressionState()->holyWall);
    }

    public function test_overlord_formation_halves_sp_for_two_different_stages_without_consuming_on_same_stage(): void
    {
        [$actor, $target, $state] = $this->battle(48);
        $this->cast($actor, $target, $state, $this->art(48, 9, '覇王大戦略'));

        $starter = $this->art(7, 1, 'ヒール', 'HEAL');
        $this->assertSame(50, $this->progression()->adjustedSpCost($actor, $starter, 100));
        $this->cast($actor, $target, $state, $starter);
        $this->assertSame(1, $actor->jobArtV2ProgressionState()->overlordFormation['charges']);

        $secondStarter = $this->art(7, 1, '祝福の祈り', 'PHYSICAL_DAMAGE');
        $this->assertSame(100, $this->progression()->adjustedSpCost($actor, $secondStarter, 100));
        $this->cast($actor, $target, $state, $secondStarter);
        $this->assertSame(1, $actor->jobArtV2ProgressionState()->overlordFormation['charges']);

        $chain = $this->art(11, 5, '勝利の采配', 'PHYSICAL_DAMAGE');
        $this->assertSame(50, $this->progression()->adjustedSpCost($actor, $chain, 100));
        $this->cast($actor, $target, $state, $chain);
        $this->assertNull($actor->jobArtV2ProgressionState()->overlordFormation);
    }

    public function test_star_fixed_field_is_amplified_and_cannot_be_extended_by_its_owner(): void
    {
        [$actor, $target, $state] = $this->battle(53);
        $sourceActionId = $this->beginAction($actor, $state);
        $fields = app(JobArtV2FieldService::class);
        $fields->deployPrimary($actor, $state, 'star_light', 1, $sourceActionId, 3);

        $this->cast($actor, $target, $state, $this->art(53, 9, '星天グランドスペル', 'MAGICAL_DAMAGE'));
        $fixed = $state->primaryField();
        $this->assertSame(5, $fixed?->remainingRounds);
        $this->assertSame(1.5, $fixed?->effectMultiplier);
        $this->assertTrue($fixed?->ownerModificationLocked ?? false);

        $ownerActionId = $this->beginAction($actor, $state);
        $fields->extendPrimary($actor, $state, 2, $ownerActionId, 2);
        $this->assertSame(5, $state->primaryField()?->remainingRounds);

        $opponentActionId = $this->beginAction($target, $state);
        $fields->deployPrimary($target, $state, 'melody', 3, $opponentActionId, 3);
        $this->assertSame('melody', $state->primaryField()?->key);
        $this->assertSame($state->actorKey($target), $state->primaryField()?->ownerActorKey);
    }

    public function test_tracking_coordinates_skip_base_miss_only_when_the_extra_sp_can_be_paid(): void
    {
        [$actor, $target, $state] = $this->battle(55);
        $this->cast($actor, $target, $state, $this->art(55, 9, '機神オーバードライブ', 'MAGICAL_DAMAGE'));
        $this->assertSame(2, $actor->jobArtV2ProgressionState()->trackingCoordinates['charges']);

        $aim = $this->art(55, 5, '追尾射撃', 'MAGICAL_DAMAGE');
        $execution = $this->cast($actor, $target, $state, $aim);
        $this->assertTrue(
            (bool) ($state->jobArtV2RoleAction()['progression_tracking_sure_hit'] ?? false),
            json_encode([
                'context' => $state->jobArtV2RoleAction(),
                'origins' => $actor->jobArtOrigins,
                'tracking' => $actor->jobArtV2ProgressionState()->trackingCoordinates,
            ], JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame(970, $actor->mp);
        $this->assertSame(1, $actor->jobArtV2ProgressionState()->trackingCoordinates['charges']);

        $actor->mp = 0;
        $executionWithoutSp = $this->cast($actor, $target, $state, $aim);
        $this->assertFalse((bool) ($state->jobArtV2RoleAction()['progression_tracking_sure_hit'] ?? false));
        $this->assertSame(1, $actor->jobArtV2ProgressionState()->trackingCoordinates['charges']);
    }

    public function test_eight_formation_rewards_three_successive_stage_changes_once(): void
    {
        [$actor, $target, $state] = $this->battle(59);
        $this->cast($actor, $target, $state, $this->art(59, 9, '八陣無双策'));
        $starter = $this->art(59, 1, '戦線把握', 'PHYSICAL_DAMAGE');
        $chain = $this->art(59, 5, '勝機の戦陣', 'PHYSICAL_DAMAGE');
        $secondStarter = $this->art(59, 1, '先陣把握', 'PHYSICAL_DAMAGE');
        $this->cast($actor, $target, $state, $starter);
        $this->cast($actor, $target, $state, $chain);
        $this->cast($actor, $target, $state, $secondStarter);

        $formation = $actor->jobArtV2ProgressionState()->eightFormation;
        $this->assertSame(3, $formation['count'], json_encode($formation, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($formation['ready']);
        $this->current($actor, $chain);
        $this->assertSame(75.0, $this->progression()->activationRate($actor, $chain, 50.0));

        $this->cast($actor, $target, $state, $chain);
        $this->assertSame(110, $this->progression()->modifyJobArtDamage($actor, $state, $chain, 100));
        $this->assertSame(0, $actor->jobArtV2ProgressionState()->eightFormation['count']);
        $this->assertFalse($actor->jobArtV2ProgressionState()->eightFormation['ready']);
    }

    public function test_royal_formation_prioritizes_and_boosts_only_a_different_stage(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $ultimate = $this->art(69, 9, '王戦アークフォーメーション');
        $this->cast($actor, $target, $state, $ultimate);
        $same = $this->art(69, 9, '王戦予備陣');
        $different = $this->art(69, 5, '戦冠総攻令', 'PHYSICAL_DAMAGE');
        $this->current($actor, $same);
        $this->current($actor, $different);

        $ordered = $this->progression()->orderCandidates($actor, [$same, $different]);
        $this->assertSame((int) $different->id, (int) $ordered[0]->id);
        $this->assertSame(75.0, $this->progression()->activationRate($actor, $different, 50.0));
        $this->assertSame(50.0, $this->progression()->activationRate($actor, $same, 50.0));

        $this->progression()->finishActivationAttempt($actor, $same);
        $this->assertSame(3, $actor->jobArtV2ProgressionState()->royalFormation['charges']);
        $this->progression()->finishActivationAttempt($actor, $different);
        $this->assertSame(2, $actor->jobArtV2ProgressionState()->royalFormation['charges']);
        $this->assertTrue($actor->jobArtV2ProgressionState()->initiativeForceFirstNextRound);
    }

    public function test_royal_formation_reserves_next_round_first_action_even_when_the_ultimate_misses(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $ultimate = $this->art(69, 9, '王戦アークフォーメーション');
        $this->current($actor, $ultimate);
        $this->beginAction($actor, $state);
        app(JobArtV2RoleEffectService::class)->beginJobArtCast($actor, $state, $ultimate);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $ultimate, HitResult::MISS);

        $this->assertTrue($actor->jobArtV2ProgressionState()->initiativeForceFirstNextRound);
        $this->assertNull(
            $actor->jobArtV2ProgressionState()->royalFormation,
            '王戦陣形はHIT報酬だが、次ラウンドの確定先攻は奥義実行そのものの報酬。',
        );
    }

    public function test_command_activation_bonus_follows_command_lineage_instead_of_the_current_job(): void
    {
        [$actor] = $this->battle(60);
        $command = $this->art(59, 5, '勝機の戦陣');
        $counter = $this->art(1, 5, '受け返し');
        $actor->jobArts = [$command, $counter];
        $actor->jobArtOrigins[(int) $command->id] = 'inherited';
        $actor->jobArtOrigins[(int) $counter->id] = 'inherited';
        $progression = $actor->jobArtV2ProgressionState();
        $progression->commandActivationBonus = 15;

        $this->assertSame(65.0, $this->progression()->activationRate($actor, $command, 50.0));
        $this->assertSame(50.0, $this->progression()->activationRate($actor, $counter, 50.0));

        $this->progression()->finishActivationAttempt($actor, $counter);
        $this->assertSame(15, $progression->commandActivationBonus);
        $this->progression()->finishActivationAttempt($actor, $command);
        $this->assertSame(0, $progression->commandActivationBonus);
    }

    public function test_royal_sword_formation_redeploys_the_five_turn_thirty_five_percent_stance(): void
    {
        [$actor, $target, $state] = $this->battle(60);
        $this->cast($actor, $target, $state, $this->art(60, 9, '王冠聖剣陣'));

        $this->assertSame(5, $actor->counterStanceState()?->remainingRounds);
        $this->assertSame(0.35, $actor->counterStanceState()?->parryRate);
        $this->assertTrue($actor->jobArtV2ProgressionState()->hasRoundState('royal_sword_formation'));
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $actorJobId): array
    {
        $actor = $this->actor('actor', true, $actorJobId);
        $target = $this->actor('target', false, 60);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(string $name, bool $isPlayer, int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 250,
            'def' => 100,
            'agi' => 100,
            'mag' => 250,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank, string $name, string $template = 'PHYSICAL_DAMAGE'): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => str_contains($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => 100,
            'power_multiplier' => 1.0,
            'hit_count' => 1,
            'activation_rate' => 100,
        ]);
        $skill->setAttribute('id', ++$this->nextSkillId);

        return $skill;
    }

    private function cast(BattleActor $actor, BattleActor $target, BattleState $state, Skill $source): Skill
    {
        $this->current($actor, $source);
        $this->beginAction($actor, $state);
        $execution = clone $source;
        app(JobArtV2RoleEffectService::class)->beginJobArtCast($actor, $state, $source);
        app(JobArtV2RoleEffectService::class)->applyForExecution($actor, $target, $state, $source, $execution);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $source, HitResult::HIT);

        return $execution;
    }

    private function current(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function beginAction(BattleActor $actor, BattleState $state): int
    {
        $sourceActionId = app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        self::assertNotNull($sourceActionId);
        app(JobArtV2RoleEffectService::class)->beginAction($actor, $state, $sourceActionId);

        return $sourceActionId;
    }

    private function timed(string $key, array $modifiers, int $rounds, int $sourceActionId): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState($key, $modifiers, 0, $rounds, $sourceActionId, $sourceActionId, true, 1.0);
    }

    private function progression(): JobArtV2ProgressionService
    {
        return app(JobArtV2ProgressionService::class);
    }
}
