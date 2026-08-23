<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2PenetrationStanceService;
use App\Services\JobArtV2ResourceService;
use Tests\TestCase;

class JobArtV2BattleHudServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_flag_off_and_unsupported_jobs_never_create_the_hud(): void
    {
        [$actor, $enemy, $state] = $this->battle(24, 23);
        config(['battle.job_art_v2.resources' => false]);

        $this->resources()->beginAction($actor, $state);
        $this->assertNull($this->hud()->present($state));

        config(['battle.job_art_v2.resources' => true]);
        [$unsupported, $other, $unsupportedState] = $this->battle(39, 40);
        $this->resources()->beginAction($unsupported, $unsupportedState);
        $this->assertNull($this->hud()->present($unsupportedState));
        $this->assertSame([], $unsupportedState->jobArtV2HudActions());
        $this->assertSame(39, $unsupported->currentJobId);
        $this->assertSame(40, $other->currentJobId);
        $this->assertSame(23, $actor->currentJobId === 24 ? $enemy->currentJobId : null);
    }

    public function test_resource_changes_and_final_gauge_are_observed_once_without_duplicate_actions(): void
    {
        [$actor, , $state] = $this->battle(53);
        $rankOne = $this->art(53, 1);
        $rankFive = $this->art(53, 5);
        $rankNine = $this->art(53, 9);

        $this->cast($actor, $state, $rankOne, HitResult::HIT);
        $this->normal($actor, $state);
        $this->cast($actor, $state, $rankFive, HitResult::MISS);
        $actor->setResource('star_mark', 12);
        $this->cast($actor, $state, $rankNine, HitResult::EVADE);

        $hud = $this->hud()->present($state);
        $this->assertNotNull($hud);
        $this->assertSame('星印', $hud['actors'][0]['resource']['name']);
        $this->assertSame(0, $hud['actors'][0]['resource']['points']);
        $this->assertSame(12, $hud['actors'][0]['resource']['remaining']);
        $this->assertFalse($hud['actors'][0]['resource']['is_full']);
        $this->assertCount(4, $hud['actions']);
        $this->assertSame(['HIT', 'HIT', 'MISS', 'EVADE'], array_column($hud['actions'], 'hit_result'));
        $this->assertSame(['始動', null, '連携', '奥義'], array_column($hud['actions'], 'rank_label'));
        $this->assertSame([4, 1, -4, -12], array_map(
            static fn (array $action): int => (int) collect($action['changes'])->firstWhere('type', 'resource')['delta'],
            $hud['actions'],
        ));
        $this->assertCount(4, array_unique(array_column($hud['actions'], 'source_action_id')));
    }

    public function test_eclipse_and_hunt_resources_use_the_shared_hud_without_lineage_specific_views(): void
    {
        foreach ([[61, 'eclipse', '冥蝕', true], [64, 'hunt', '狩猟印', false]] as [$jobId, $resourceKey, $resourceName, $gainOnHit]) {
            [$actor, , $state] = $this->battle($jobId);
            $rankOne = $this->art($jobId, 1);
            $this->resources()->beginAction($actor, $state);
            $this->resources()->applyJobArtCast($actor, $state, $rankOne);
            if ($gainOnHit) {
                $this->resources()->recordJobArtHit($actor, $state, $rankOne);
            }
            $this->hud()->recordHitResult($actor, $state, HitResult::HIT);
            $this->hud()->finishAction($actor, $state);

            $hud = $this->hud()->present($state);
            $this->assertSame($resourceName, $hud['actors'][0]['resource']['name']);
            $this->assertSame(4, $actor->getResource($resourceKey));
            $this->assertSame(4, $hud['actors'][0]['resource']['points']);
            $this->assertSame(4, collect($hud['actions'][0]['changes'])->firstWhere('type', 'resource')['delta']);
            $this->assertNull($hud['actors'][0]['field']);
            $this->assertNull($hud['actors'][0]['stance']);
        }
    }

    public function test_current_and_inherited_source_resources_are_displayed_and_traced_independently(): void
    {
        [$actor, , $state] = $this->battle(62);
        $currentProducer = $this->art(62, 1);
        $foreignProducer = $this->art(3, 1);
        $foreignFinisher = $this->art(3, 9);
        $foreignProducer->name = '影狩りの構え';
        $foreignFinisher->name = 'ファントムロブ';
        $actor->jobArts = [$currentProducer, $foreignProducer, $foreignFinisher];
        $actor->jobArtOrigins[(int) $currentProducer->id] = 'current';
        $actor->jobArtOrigins[(int) $foreignProducer->id] = 'inherited';
        $actor->jobArtOrigins[(int) $foreignFinisher->id] = 'inherited';

        foreach (range(1, 3) as $_) {
            $this->cast($actor, $state, $foreignProducer, HitResult::HIT);
        }
        $this->cast($actor, $state, $foreignFinisher, HitResult::HIT);

        $hud = $this->hud()->present($state);
        $this->assertSame(['竜気', '狩猟印'], array_column($hud['actors'][0]['resources'], 'name'));
        $this->assertSame([false, false], array_column($hud['actors'][0]['resources'], 'is_primary'));
        $this->assertSame([0, 0], array_column($hud['actors'][0]['resources'], 'points'));
        $this->assertSame([4, 4, 4, -12], array_map(
            static fn (array $action): int => (int) collect($action['changes'])->firstWhere('type', 'resource')['delta'],
            $hud['actions'],
        ));
        $this->assertSame(0, $actor->getResource('dragon_force'));
        $this->assertSame(0, $actor->getResource('hunt'));
    }

    public function test_twelve_points_is_presented_as_full_but_never_as_castable(): void
    {
        [$actor, , $state] = $this->battle(24);
        foreach (range(1, 3) as $_) {
            $this->cast($actor, $state, $this->art(24, 1), null);
        }

        $hud = $this->hud()->present($state);
        $this->assertSame(12, $hud['actors'][0]['resource']['points']);
        $this->assertSame(0, $hud['actors'][0]['resource']['remaining']);
        $this->assertTrue($hud['actors'][0]['resource']['is_full']);

        $blade = file_get_contents(base_path('resources/views/battle/partials/job-art-v2-hud.blade.php'));
        $this->assertStringContainsString('奥義ゲージ満タン', $blade);
        $this->assertStringNotContainsString('発動可能', $blade);
    }

    public function test_fields_lock_overlay_and_expiration_use_the_existing_structured_state(): void
    {
        [$actor, , $state] = $this->battle(85, 53);
        $state->turnCount = 1;
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 12);

        $this->cast($actor, $state, $this->art(85, 1), HitResult::HIT);
        $this->cast($actor, $state, $this->art(85, 5), null);
        $actor->setResource('star_mark', 12);
        $this->cast($actor, $state, $this->art(85, 9), HitResult::HIT);

        $hud = $this->hud()->present($state);
        $this->assertSame('星光の場', $hud['actors'][0]['field']['name']);
        $this->assertSame('自分', $hud['actors'][0]['field']['owner_label']);
        $this->assertSame(2, $hud['actors'][0]['field']['lock_remaining_rounds']);
        $this->assertSame('旋律の場', $hud['actors'][0]['overlay']['name']);

        $state->turnCount = 2;
        $this->fields()->endRound($state);
        $this->assertNull($state->fieldOverlay());
        $this->assertStringContainsString('副場：旋律の場が消滅', collect($this->hud()->present($state)['round_events'])->pluck('label')->implode('|'));
    }

    public function test_dragon_stance_and_actual_penetration_are_shown_only_for_the_cast_that_used_them(): void
    {
        [$actor, , $state] = $this->battle(62);
        $rankOne = $this->art(62, 1);
        $rankFive = $this->art(62, 5);
        $rankNine = $this->art(62, 9);
        foreach ([$rankOne, $rankFive, $rankNine] as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
        }

        $this->stanceCast($actor, $state, $rankOne, HitResult::HIT);
        $actor->setResource('dragon_force', 4);
        $this->stanceCast($actor, $state, $rankFive, HitResult::HIT);
        $actor->setResource('dragon_force', 12);
        $this->stanceCast($actor, $state, $rankNine, HitResult::MISS);

        $hud = $this->hud()->present($state);
        $this->assertFalse($hud['actors'][0]['stance']['active']);
        $this->assertSame([null, 35, null], array_column($hud['actions'], 'penetration_percent'));
        $this->assertStringContainsString(
            '貫通構えを利用 → 再形成',
            collect($hud['actions'][1]['changes'])->pluck('label')->filter()->implode('|'),
        );
        $this->assertStringContainsString(
            '貫通構え ON → OFF',
            collect($hud['actions'][2]['changes'])->pluck('label')->filter()->implode('|'),
        );

        config(['battle.job_art_v2.penetration_stance' => false]);
        [$legacyStanceActor, , $legacyStanceState] = $this->battle(62);
        $legacyStanceActor->jobArtOrigins[(int) $rankFive->id] = 'current';
        $legacyStanceActor->configureResource('dragon_force', 12);
        $legacyStanceActor->setResource('dragon_force', 4);
        $this->cast($legacyStanceActor, $legacyStanceState, $rankFive, HitResult::HIT);
        $this->assertSame(35, $this->hud()->present($legacyStanceState)['actions'][0]['penetration_percent']);
    }

    public function test_support_actions_have_no_hit_badge_and_both_supported_pvp_actors_are_presented(): void
    {
        [$player, $enemy, $state] = $this->battle(24, 62, 'pvp');
        $this->cast($player, $state, $this->art(24, 1), null);
        $this->normal($enemy, $state);

        $hud = $this->hud()->present($state);
        $this->assertSame(['player', 'enemy'], array_column($hud['actors'], 'actor_key'));
        $this->assertNull($hud['actions'][0]['hit_result']);
        $this->assertSame('HIT', $hud['actions'][1]['hit_result']);
        $this->assertSame(['星印', '竜気'], array_column(array_column($hud['actors'], 'resource'), 'name'));
    }

    public function test_observer_and_presenter_do_not_mutate_battle_state_or_consume_rng(): void
    {
        [$actor, , $state] = $this->battle(53);
        $this->resources()->beginAction($actor, $state);
        $beforeActor = serialize($actor);
        $beforeField = serialize([$state->primaryField(), $state->fieldOverlay()]);

        mt_srand(160016);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(160016);
        $this->hud()->recordHitResult($actor, $state, HitResult::MISS);
        $this->hud()->finishAction($actor, $state);
        $first = $this->hud()->present($state);
        $second = $this->hud()->present($state);
        $actual = [mt_rand(), mt_rand()];

        $this->assertSame($expected, $actual);
        $this->assertSame($beforeActor, serialize($actor));
        $this->assertSame($beforeField, serialize([$state->primaryField(), $state->fieldOverlay()]));
        $this->assertSame($first, $second);
        $source = file_get_contents(base_path('app/Services/JobArtV2BattleHudService.php'));
        $this->assertStringNotContainsString('rand(', $source);
        $this->assertStringNotContainsString('random_int(', $source);
    }

    public function test_result_views_hide_the_summary_panel_while_six_routes_keep_the_structured_payload(): void
    {
        $blade = file_get_contents(base_path('resources/views/battle/partials/job-art-v2-hud.blade.php'));
        $this->assertStringContainsString('space-y-3', $blade);
        $this->assertStringNotContainsString('overflow-x-auto', $blade);
        $this->assertStringNotContainsString('grid-cols-3', $blade);
        $this->assertStringContainsString('EVADE：回避された', $blade);

        foreach ([
            'resources/views/battle/result.blade.php',
            'resources/views/battle/pvp_result.blade.php',
            'resources/views/champ/result.blade.php',
            'resources/views/tower/star-tree/result.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString('battle.partials.job-art-v2-hud', file_get_contents(base_path($view)), $view);
        }

        foreach ([
            'app/Services/BattleService.php' => 'jobArtV2Hud',
            'app/Services/TowerBattleService.php' => 'job_art_v2_hud',
            'app/Services/PvPBattleService.php' => 'jobArtV2Hud',
            'app/Services/ChampBattleService.php' => 'job_art_v2_hud',
            'app/Services/ArenaNpcBattleService.php' => 'jobArtV2Hud',
        ] as $service => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(base_path($service)), $service);
        }
        $this->assertStringContainsString("'job_art_v2_hud'", file_get_contents(base_path('app/Http/Controllers/BattleController.php')));
        $this->assertStringNotContainsString('JobArtV2BattleHudService', file_get_contents(base_path('app/Services/Battle/ActionResolver.php')));
        $this->assertStringNotContainsString('JobArtV2BattleHudService', file_get_contents(base_path('app/Services/Battle/DamageCalculator.php')));
    }

    private function cast(BattleActor $actor, BattleState $state, Skill $skill, ?HitResult $result): void
    {
        $this->resources()->beginAction($actor, $state);
        $this->resources()->applyJobArtCast($actor, $state, $skill);
        $this->hud()->recordHitResult($actor, $state, $result);
        $this->hud()->finishAction($actor, $state);
    }

    private function normal(BattleActor $actor, BattleState $state): void
    {
        $this->resources()->beginAction($actor, $state);
        $this->resources()->recordNormalAttackHit($actor, $state);
        $this->hud()->finishAction($actor, $state);
    }

    private function stanceCast(BattleActor $actor, BattleState $state, Skill $skill, HitResult $result): void
    {
        $this->resources()->beginAction($actor, $state);
        $this->resources()->applyJobArtCast($actor, $state, $skill);
        $this->stance()->beginCast($actor, $state, $skill);
        $this->hud()->recordHitResult($actor, $state, $result);
        $this->stance()->completeCast($actor, $state, $skill);
        $this->hud()->finishAction($actor, $state);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $playerJob, ?int $enemyJob = null, string $battleType = 'pve'): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy, $battleType)];
    }

    private function actor(string $name, bool $isPlayer, ?int $jobId): BattleActor
    {
        $actor = new BattleActor($name, $isPlayer, [
            'hp' => 10000,
            'max_hp' => 10000,
            'mp' => 400,
            'max_mp' => 400,
            'str' => 1000,
            'def' => 100,
            'agi' => 100,
            'mag' => 1000,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
        if (in_array($jobId, [24, 53, 61, 62, 64, 85], true)) {
            $actor->jobArts = [$this->art((int) $jobId, 1), $this->art((int) $jobId, 5), $this->art((int) $jobId, 9)];
            foreach ($actor->jobArts as $art) {
                $actor->jobArtOrigins[(int) $art->id] = 'current';
                $actor->jobArtRates[(int) $art->id] = 1.0;
            }
        }

        return $actor;
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => match ([$jobId, $rank]) {
                [24, 1] => '浄化の光',
                [24, 5] => 'セイクリッドライト',
                [24, 9] => '大聖堂の奇跡',
                [53, 1] => '星読の瞬き',
                [53, 5] => '星詠みの光',
                [53, 9] => '星天グランドスペル',
                [61, 1] => '黒冠契約',
                [61, 5] => '黒冠魔剣',
                [61, 9] => '黒冠アビスブレイク',
                [62, 1] => '竜冠の槍印',
                [62, 5] => '竜冠穿槍',
                [62, 9] => '竜冠天穿槍',
                [64, 1] => '影冠追跡',
                [64, 5] => '影冠狙撃',
                [64, 9] => '影冠終葬射',
                [85, 1] => '星律の祈祷',
                [85, 5] => '星律神裁',
                [85, 9] => '星律大聖堂',
                default => "job-{$jobId}-rank-{$rank}",
            },
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $jobId === 24 ? 'HEAL' : ($jobId === 62 ? 'PHYSICAL_DAMAGE' : 'MAGICAL_DAMAGE'),
            'damage_type' => $jobId === 62 ? 'physical' : 'magical',
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function hud(): JobArtV2BattleHudService
    {
        return app(JobArtV2BattleHudService::class);
    }

    private function resources(): JobArtV2ResourceService
    {
        return app(JobArtV2ResourceService::class);
    }

    private function fields(): JobArtV2FieldService
    {
        return app(JobArtV2FieldService::class);
    }

    private function stance(): JobArtV2PenetrationStanceService
    {
        return app(JobArtV2PenetrationStanceService::class);
    }
}
