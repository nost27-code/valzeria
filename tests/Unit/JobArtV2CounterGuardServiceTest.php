<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ParryRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use Tests\TestCase;

class JobArtV2CounterGuardServiceTest extends TestCase
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

    public function test_counter_stance_keeps_two_rounds_skips_cast_round_and_refreshes(): void
    {
        [$counter, , $state] = $this->battle(60);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $state->turnCount = 1;

        $this->cast($counter, $state, $this->art(60, 1), $defense);
        $this->assertSame(4, $counter->getResource('sword_momentum'));
        $this->assertSame(2, $counter->counterStanceState()?->remainingRounds);

        $defense->endRound($state);
        $this->assertSame(2, $counter->counterStanceState()?->remainingRounds);

        $state->turnCount = 2;
        $defense->endRound($state);
        $this->assertSame(1, $counter->counterStanceState()?->remainingRounds);

        $this->cast($counter, $state, $this->art(60, 1), $defense);
        $this->assertSame(8, $counter->getResource('sword_momentum'));
        $this->assertSame(2, $counter->counterStanceState()?->remainingRounds);
        $defense->endRound($state);
        $this->assertSame(2, $counter->counterStanceState()?->remainingRounds);

        $state->turnCount = 3;
        $defense->endRound($state);
        $this->assertSame(1, $counter->counterStanceState()?->remainingRounds);
        $state->turnCount = 4;
        $defense->endRound($state);
        $this->assertNull($counter->counterStanceState());
        $this->assertSame(JobArtV2DefenseService::COUNTER_EVENT_EXPIRED, collect($state->counterStanceEvents())->last()['event']);
    }

    public function test_parry_rolls_once_for_a_multihit_action_and_allows_both_counter_events(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([20]);
        $defense = $this->defense($random);
        $application = new DamageApplicationService($defense);
        $this->cast($counter, $state, $this->art(60, 1), $defense);

        $this->resources()->beginAction($attacker, $state);
        $first = $this->applyDirect($application, $state, $attacker, $counter, 120, 'physical', HitResult::HIT, 1, 2);
        $second = $this->applyDirect($application, $state, $attacker, $counter, 80, 'physical', HitResult::HIT, 2, 2);
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame([0, 0], [$first->requestedDamage, $second->requestedDamage]);
        $this->assertSame(10_000, $counter->hp);
        $this->assertSame(1, $random->calls);
        $this->assertSame(6, $counter->getResource('sword_momentum'));
        $this->assertNotNull($counter->counterStanceState());

        $parry = collect($state->parryResults())->last();
        $this->assertTrue($parry->success);
        $this->assertSame([200, 0], [$parry->damageBeforeParry, $parry->damageAfterParry]);

        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $defense->resolveDamage($state, $resolution, 10));
        $this->assertSame(HitResult::HIT, $resolution->hitResult);

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $incoming = collect($hud['actions'])->firstWhere('action_kind', 'incoming_attack');
        $this->assertNotNull($incoming);
        $this->assertSame(2, collect($incoming['changes'])->firstWhere('type', 'resource')['delta']);
        $this->assertTrue(collect($incoming['changes'])->firstWhere('type', 'parry')['success']);
    }

    public function test_counter_received_event_is_hit_only_direct_physical_and_deduplicated_per_action(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([100, 100]);
        $defense = $this->defense($random);

        $this->resources()->beginAction($attacker, $state);
        $sourceActionId = $state->currentSourceActionId();
        $physical = new DirectAttackResolution($sourceActionId, $attacker, $counter, HitResult::HIT, 'physical', true, BattleActionType::NORMAL_ATTACK);
        $this->assertSame(100, $defense->resolveDamage($state, $physical, 100));
        $this->assertSame(100, $defense->resolveDamage($state, $physical, 100));
        $this->assertSame(1, $counter->getResource('sword_momentum'));
        $this->resources()->finishAction($attacker, $state);

        foreach ([
            [HitResult::MISS, 'physical', true, BattleActionType::NORMAL_ATTACK],
            [HitResult::EVADE, 'physical', true, BattleActionType::NORMAL_ATTACK],
            [HitResult::HIT, 'magical', true, BattleActionType::NORMAL_ATTACK],
            [HitResult::HIT, 'physical', false, BattleActionType::CURRENT_JOB_SKILL],
        ] as [$hitResult, $category, $direct, $actionType]) {
            $this->resources()->beginAction($attacker, $state);
            $excluded = new DirectAttackResolution(
                $state->currentSourceActionId(),
                $attacker,
                $counter,
                $hitResult,
                $category,
                $direct,
                $actionType,
            );
            $this->assertSame(100, $defense->resolveDamage($state, $excluded, 100));
            $this->resources()->finishAction($attacker, $state);
        }

        $this->assertSame(1, $counter->getResource('sword_momentum'));
        $this->assertSame(0, $random->calls);
    }

    public function test_parry_uses_the_twenty_percent_boundary_without_reclassifying_a_failed_hit(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([21]);
        $defense = $this->defense($random);
        $this->cast($counter, $state, $this->art(60, 1), $defense);

        $this->resources()->beginAction($attacker, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );

        $this->assertSame(100, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(HitResult::HIT, $resolution->hitResult);
        $this->assertSame(1, $random->calls);
        $this->assertFalse($state->parryResult($counter, $state->currentSourceActionId())?->success);
        $this->assertSame(5, $counter->getResource('sword_momentum'));
    }

    public function test_guard_reduces_an_entire_multihit_action_once_and_records_actual_prevention(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);
        $this->cast($guard, $state, $this->art(66, 1, 'MAGICAL_DAMAGE_BUFF'), $defense);

        $this->resources()->beginAction($attacker, $state);
        $first = $this->applyDirect($application, $state, $attacker, $guard, 100, 'physical', HitResult::HIT, 1, 2);
        $second = $this->applyDirect($application, $state, $attacker, $guard, 50, 'physical', HitResult::HIT, 2, 2);
        $sourceActionId = $state->currentSourceActionId();
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame([80, 40], [$first->requestedDamage, $second->requestedDamage]);
        $this->assertSame(9_880, $guard->hp);
        $this->assertNull($guard->jobArtV2GuardState());
        $this->assertSame(5, $guard->getResource('holy_guard'));
        $trace = $state->damageTrace($guard, $sourceActionId);
        $this->assertSame([150, 120, 30], [
            $trace->damageBeforeActiveGuard,
            $trace->damageAfterActiveGuard,
            $trace->preventedDamage,
        ]);

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $incoming = collect($hud['actions'])->firstWhere('action_kind', 'incoming_attack');
        $guardChange = collect($incoming['changes'])->firstWhere('type', 'active_guard');
        $this->assertSame([150, 120, 30], [$guardChange['damage_before'], $guardChange['damage_after'], $guardChange['prevented_damage']]);
    }

    public function test_guard_handles_magical_damage_stronger_priority_zero_prevention_and_parry_order(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);

        $guard->configureResource('holy_guard', 12);
        $guard->setResource('holy_guard', 12);
        $this->cast($guard, $state, $this->art(66, 9, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0.25, $guard->jobArtV2GuardState()?->rate);
        $guard->setResource('holy_guard', 4);
        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0.25, $guard->jobArtV2GuardState()?->rate);

        $this->resources()->beginAction($attacker, $state);
        $magical = $this->applyDirect($application, $state, $attacker, $guard, 100, 'magical');
        $this->assertSame(75, $magical->requestedDamage);

        $guard->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        $beforeResource = $guard->getResource('holy_guard');
        $this->resources()->beginAction($attacker, $state);
        $oneDamage = $this->applyDirect($application, $state, $attacker, $guard, 1, 'physical');
        $this->assertSame(1, $oneDamage->requestedDamage);
        $this->assertSame($beforeResource, $guard->getResource('holy_guard'));

        [$counter, $other, $counterState] = $this->battle(60);
        $successDefense = $this->defense(new FixedParryRandomSource([1]));
        $this->cast($counter, $counterState, $this->art(60, 1), $successDefense);
        $counter->replaceJobArtV2GuardState(new JobArtV2GuardState(0.25));
        $this->resources()->beginAction($other, $counterState);
        $resolution = new DirectAttackResolution(
            $counterState->currentSourceActionId(),
            $other,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $successDefense->resolveDamage($counterState, $resolution, 100));
        $this->assertSame(0.25, $counter->jobArtV2GuardState()?->rate);
        $this->assertNull($counterState->damageTrace($counter, $counterState->currentSourceActionId()));
    }

    public function test_guard_ignores_indirect_recoil_and_dot_and_container_injects_the_shared_service(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $guard->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        $this->resources()->beginAction($attacker, $state);

        $indirect = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $guard,
            HitResult::HIT,
            'physical',
            false,
            BattleActionType::CURRENT_JOB_SKILL,
        );
        foreach ([DamageSourceType::DOT, DamageSourceType::RECOIL, DamageSourceType::SELF_DAMAGE] as $sourceType) {
            $result = app(DamageApplicationService::class)->apply(new DamageApplicationRequest(
                sourceActor: $attacker,
                targetActor: $guard,
                resolvedDamage: 10,
                sourceType: $sourceType,
                sourceId: null,
                battleType: 'pve',
                hitResult: HitResult::HIT,
                battleState: $state,
                directAttackResolution: $indirect,
            ));
            $this->assertSame(10, $result->requestedDamage);
        }
        $this->assertSame(0.20, $guard->jobArtV2GuardState()?->rate);

        $this->resources()->beginAction($attacker, $state);
        $direct = $this->applyDirect(app(DamageApplicationService::class), $state, $attacker, $guard, 100, 'physical');
        $this->assertSame(80, $direct->requestedDamage);
        $this->assertNull($guard->jobArtV2GuardState());
    }

    public function test_rank_five_cleanse_is_structured_all_at_once_and_gains_once(): void
    {
        [$guard, , $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        foreach (JobArtV2DefenseService::CLEANSABLE_STATES as $key) {
            $guard->conditions[$key] = ['turns' => 3, 'rate' => 0.1];
        }
        $guard->conditions['stun'] = ['turns' => 1];
        $guard->configureResource('holy_guard', 12);
        $guard->setResource('holy_guard', 4);

        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(1, $guard->getResource('holy_guard'));
        $this->assertSame(['stun'], array_keys($guard->conditions));
        $result = collect($state->cleanseResults())->last();
        $this->assertTrue($result->success);
        $this->assertSame(6, $result->removedCount);
        $this->assertSame(JobArtV2DefenseService::CLEANSABLE_STATES, $result->removedStates);
        $this->assertSame(0.20, $guard->jobArtV2GuardState()?->rate);

        $guard->setResource('holy_guard', 4);
        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0, $guard->getResource('holy_guard'));
        $this->assertFalse(collect($state->cleanseResults())->last()->success);
    }

    public function test_rank_costs_clamp_resources_and_rank_nine_stays_once_per_battle(): void
    {
        foreach ([60 => 'sword_momentum', 66 => 'holy_guard'] as $jobId => $resourceKey) {
            [$actor, , $state] = $this->battle($jobId);
            $defense = $this->defense(new FixedParryRandomSource([100]));
            foreach (range(1, 4) as $_) {
                $this->cast($actor, $state, $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE'), $defense);
            }
            $this->assertSame(12, $actor->getResource($resourceKey));

            $rankFive = $this->art($jobId, 5, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $this->cast($actor, $state, $rankFive, $defense);
            $this->assertSame(8, $actor->getResource($resourceKey));

            $actor->setResource($resourceKey, 12);
            $rankNine = $this->art($jobId, 9, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $this->cast($actor, $state, $rankNine, $defense);
            $this->assertSame(0, $actor->getResource($resourceKey));
            $state->jobArtUseCounts[(int) $rankNine->id] = 1;
            $this->assertSame(1, (int) $rankNine->max_uses_per_battle);
            $this->assertSame(1, $state->jobArtUseCounts[(int) $rankNine->id]);
        }
    }

    public function test_guard_semantics_are_current_job_only_and_legacy_master_remains_intact(): void
    {
        $support = app(JobArtBattleSupportService::class);
        $actor = $this->actor('guard', true, 66);
        $skill = $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF');
        $actor->jobArtOrigins[(int) $skill->id] = 'current';

        $execution = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $skill->effect_template);

        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $inherited = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $inherited->effect_template);

        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        config(['battle.job_art_v2.resources' => false]);
        $legacy = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $legacy->effect_template);
    }

    public function test_guard_display_suppresses_legacy_buff_copy_only_for_current_v2_semantics(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $skill = $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF');

        $current = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE', $current['effect_template']);
        $this->assertTrue($current['legacy_effect_copy_suppressed']);

        $skill->setAttribute('job_art_origin', 'inherited');
        $inherited = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $inherited['effect_template']);
        $this->assertFalse($inherited['legacy_effect_copy_suppressed']);

        $skill->setAttribute('job_art_origin', 'current');
        config(['battle.job_art_v2.resources' => false]);
        $legacy = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $legacy['effect_template']);
        $this->assertFalse($legacy['legacy_effect_copy_suppressed']);
    }

    public function test_counter_guard_states_do_not_leak_into_inherited_or_flag_off_paths(): void
    {
        $defense = $this->defense(new FixedParryRandomSource([1]));

        foreach ([60, 66] as $jobId) {
            [$actor, , $state] = $this->battle($jobId);
            $skill = $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
            $this->resources()->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);

            $this->assertNull($actor->counterStanceState());
            $this->assertNull($actor->jobArtV2GuardState());
        }

        config(['battle.job_art_v2.resources' => false]);
        foreach ([60, 66] as $jobId) {
            [$actor, , $state] = $this->battle($jobId);
            $skill = $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
            $this->resources()->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);

            $this->assertNull($actor->counterStanceState());
            $this->assertNull($actor->jobArtV2GuardState());
        }
    }

    public function test_loadout_hud_preset_and_six_routes_use_shared_counter_guard_metadata(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $counterOne = $presenter->forArt(60, $this->art(60, 1));
        $guardFive = $presenter->forArt(66, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'));
        $guardNine = $presenter->forArt(66, $this->art(66, 9, 'MAGICAL_DAMAGE_BUFF'));

        $this->assertSame('剣勢 +4', $counterOne['resource_text']);
        $this->assertStringContainsString('20%で受け流し', implode('|', $counterOne['stance_texts']));
        $this->assertStringContainsString('直接物理攻撃', implode('|', $counterOne['effect_texts']));
        $this->assertSame('聖護 4消費', $guardFive['resource_text']);
        $this->assertStringContainsString('全浄化', implode('|', $guardFive['effect_texts']));
        $this->assertSame('MAGICAL_DAMAGE', $guardFive['effect_template']);
        $this->assertSame('攻撃', $guardFive['effect_label']);
        $this->assertTrue($guardFive['legacy_effect_copy_suppressed']);
        $this->assertNotContains(
            '自己強化 主+20% / 副+10%',
            $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF')->jobArtNumericEffectLabels(285, $guardFive['effect_template']),
        );
        $this->assertSame('聖護 12消費', $guardNine['resource_text']);
        $this->assertStringContainsString('25%軽減', implode('|', $guardNine['effect_texts']));

        $counterRecommendations = $presenter->recommendationsForCurrentJob(60, [$this->art(60, 1), $this->art(60, 5), $this->art(60, 9)]);
        $guardRecommendations = $presenter->recommendationsForCurrentJob(66, [$this->art(66, 1), $this->art(66, 5), $this->art(66, 9)]);
        $this->assertStringContainsString('被物理攻撃', $counterRecommendations[0]['job_note']);
        $this->assertStringContainsString('浄化と20%加護', $guardRecommendations[1]['job_note']);

        $blade = file_get_contents(resource_path('views/battle/partials/job-art-v2-hud.blade.php'));
        foreach (["'parry'", "'active_guard'", "'cleanse'", '受け流し', '直接ダメージ軽減'] as $needle) {
            $this->assertStringContainsString($needle, $blade);
        }

        foreach ([
            'app/Services/BattleService.php',
            'app/Services/PvPBattleService.php',
            'app/Services/ChampBattleService.php',
            'app/Services/ArenaNpcBattleService.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString('DirectAttackResolution::fromDamageSource', $source, $path);
        }
        $this->assertStringContainsString('extends BattleService', file_get_contents(base_path('app/Services/TowerBattleService.php')));
        $this->assertStringContainsString("$"."battleContext = $"."enemy->is_boss ? 'boss' : 'pve'", file_get_contents(base_path('app/Services/BattleService.php')));
        $this->assertStringNotContainsString('job_art_presets', file_get_contents(base_path('app/Services/BattleService.php')));
    }

    private function cast(BattleActor $actor, BattleState $state, Skill $skill, JobArtV2DefenseService $defense): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $this->resources()->beginAction($actor, $state);
        $this->resources()->applyJobArtCast($actor, $state, $skill);
        $defense->applyJobArtCast($actor, $state, $skill);
        $this->resources()->finishAction($actor, $state);
    }

    private function applyDirect(
        DamageApplicationService $service,
        BattleState $state,
        BattleActor $attacker,
        BattleActor $target,
        int $damage,
        string $category,
        HitResult $hitResult = HitResult::HIT,
        int $hitIndex = 1,
        int $hitCount = 1,
    ): DamageApplicationResult {
        $sourceActionId = $state->currentSourceActionId();
        $this->assertNotNull($sourceActionId);

        return $service->apply(new DamageApplicationRequest(
            sourceActor: $attacker,
            targetActor: $target,
            resolvedDamage: $damage,
            sourceType: DamageSourceType::NORMAL_ATTACK,
            sourceId: null,
            battleType: $state->battleType,
            hitResult: $hitResult,
            hitIndex: $hitIndex,
            hitCount: $hitCount,
            battleState: $state,
            directAttackResolution: new DirectAttackResolution(
                sourceActionId: $sourceActionId,
                attacker: $attacker,
                target: $target,
                hitResult: $hitResult,
                damageCategory: $category,
                direct: true,
                actionType: BattleActionType::NORMAL_ATTACK,
            ),
        ));
    }

    private function defense(FixedParryRandomSource $random): JobArtV2DefenseService
    {
        return new JobArtV2DefenseService(
            app(JobArtV2FeatureGate::class),
            app(JobArtV2PrototypeCatalog::class),
            app(JobArtV2ResourceService::class),
            $random,
        );
    }

    private function resources(): JobArtV2ResourceService
    {
        return app(JobArtV2ResourceService::class);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $playerJob, ?int $enemyJob = null, string $battleType = 'pve'): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy, $battleType)];
    }

    private function actor(string $name, bool $player, ?int $jobId): BattleActor
    {
        return new BattleActor($name, $player, [
            'hp' => 10_000,
            'max_hp' => 10_000,
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

    private function art(int $jobId, int $rank, string $template = 'PHYSICAL_DAMAGE'): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => str_starts_with($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => [1 => 225, 5 => 285, 9 => 355][$rank],
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        $skill->setAttribute('job_art_origin', 'current');

        return $skill;
    }
}

final class FixedParryRandomSource extends JobArtV2ParryRandomSource
{
    public int $calls = 0;

    /** @param list<int> $rolls */
    public function __construct(private array $rolls)
    {
    }

    public function percentRoll(): int
    {
        $this->calls++;

        return array_shift($this->rolls) ?? 100;
    }
}
