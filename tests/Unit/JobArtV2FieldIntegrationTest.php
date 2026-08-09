<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\FieldEvent;
use App\Services\FieldState;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2ResourceService;
use Tests\TestCase;

class JobArtV2FieldIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableFields();
    }

    public function test_flag_off_preserves_pr8_state_logs_and_rng(): void
    {
        config(['battle.job_art_v2.fields' => false]);
        [$actor, , $state] = $this->battle(53, 62);
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        $before = serialize($state);
        mt_srand(9911);
        $expected = mt_rand();

        mt_srand(9911);
        $service = $this->fields();
        $this->assertFalse($service->deployPrimary($actor, $state, 'star_light', 531, 1)->applied);
        $this->assertSame(100, $service->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART));
        $this->assertSame([], $service->endRound($state));

        $this->assertSame($before, serialize($state));
        $this->assertSame($expected, mt_rand());
    }

    public function test_every_dependency_and_non_field_participant_fails_closed(): void
    {
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $dependency) {
            $this->enableFields();
            config(["battle.job_art_v2.{$dependency}" => false]);
            [$actor, , $state] = $this->battle(53, 62);
            $this->assertFalse($this->fields()->enabledFor($state), $dependency);
            $this->assertFalse($this->fields()->deployPrimary($actor, $state, 'star_light', 531, 1)->applied);
        }

        $this->enableFields();
        [$dragon, , $dragonOnly] = $this->battle(62, 10);
        $this->assertFalse($this->fields()->enabledFor($dragonOnly));
        $this->assertNull($dragonOnly->primaryField());
        $this->assertFalse($this->fields()->deployPrimary($dragon, $dragonOnly, 'star_light', 531, 1)->applied);
    }

    public function test_rank_one_arts_create_the_frozen_primary_fields_and_never_self_apply(): void
    {
        foreach ([[24, 'sanctuary'], [53, 'star_light'], [85, 'star_light']] as [$jobId, $fieldKey]) {
            [$actor, , $state] = $this->battle($jobId, 62);
            $state->turnCount = 1;
            $resources = app(JobArtV2ResourceService::class);
            $resources->beginAction($actor, $state);
            $skill = $this->art($jobId, 1, 'MAGICAL_DAMAGE');
            $this->fields()->markSkillAction($actor, $state, $skill);

            $result = $resources->applyJobArtCast($actor, $state, $skill);

            $this->assertSame(4, $result->delta);
            $this->assertSame($fieldKey, $state->primaryField()?->key);
            $this->assertSame(FieldEvent::CREATED, $state->fieldEvents()[0]->event);
            $this->assertSame(100, $this->fields()->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART));
            $this->assertSame(4, $actor->getResource('star_mark'));
        }
    }

    public function test_existing_star_light_and_sanctuary_apply_on_the_next_action_only(): void
    {
        [$sage, , $state] = $this->battle(53, 24);
        $state->turnCount = 1;
        $this->fields()->deployPrimary($sage, $state, 'star_light', 531, 1);
        app(JobArtV2ResourceService::class)->beginAction($sage, $state);
        $this->fields()->markSkillAction($sage, $state, $this->art(53, 9, 'MAGICAL_DAMAGE'));
        $this->assertSame(110, $this->fields()->modifyDamage($sage, $state, 100, DamageSourceType::JOB_ART));

        $this->fields()->deployPrimary($sage, $state, 'sanctuary', 241, 3);
        app(JobArtV2ResourceService::class)->beginAction($sage, $state);
        $this->assertSame(110, $this->fields()->modifyHpHeal($sage, $state, 100));
    }

    public function test_rank_five_sage_extends_on_cast_with_or_without_hit_and_is_eligible_without_a_field(): void
    {
        [$sage, , $state] = $this->battle(53, 24);
        $sage->configureResource('star_mark', 12);
        $sage->setResource('star_mark', 4);
        $rankFive = $this->art(53, 5, 'MAGICAL_DAMAGE');
        $resources = app(JobArtV2ResourceService::class);
        $this->assertNull($resources->eligibilityBlockReason($sage, $rankFive, $state));

        $resources->beginAction($sage, $state);
        $withoutField = $resources->applyJobArtCast($sage, $state, $rankFive);
        $this->assertSame(-4, $withoutField->delta);
        $this->assertNull($state->primaryField());
        $this->assertCount(0, $state->fieldEvents());

        foreach (['miss', 'evade'] as $resolution) {
            [$caseSage, , $caseState] = $this->battle(53, 24);
            $caseSage->configureResource('star_mark', 12);
            $caseSage->setResource('star_mark', 4);
            $caseState->turnCount = 1;
            $this->fields()->deployPrimary($caseSage, $caseState, 'star_light', 531, 100);
            $resources->beginAction($caseSage, $caseState);
            $resources->applyJobArtCast($caseSage, $caseState, $rankFive);
            $this->assertSame(4, $caseState->primaryField()?->remainingRounds, $resolution);
            $this->assertSame(1, $caseState->primaryField()?->extends, $resolution);
        }
    }

    public function test_rank_five_star_priest_requires_field_consumes_four_and_locks_without_damage(): void
    {
        [$priest, , $state] = $this->battle(85, 53);
        $rankFive = $this->art(85, 5, 'MAGICAL_DAMAGE_BUFF');
        $rankFive->power = 900;
        $priest->configureResource('star_mark', 12);
        $priest->setResource('star_mark', 4);
        $resources = app(JobArtV2ResourceService::class);

        $this->assertSame(JobArtV2FieldService::BLOCKED_BY_FIELD, $resources->eligibilityBlockReason($priest, $rankFive, $state));
        $this->fields()->deployPrimary($priest, $state, 'star_light', 851, 1);
        $this->assertNull($resources->eligibilityBlockReason($priest, $rankFive, $state));
        $resources->beginAction($priest, $state);
        $result = $resources->applyJobArtCast($priest, $state, $rankFive);

        $this->assertSame(-4, $result->delta);
        $this->assertSame(2, $state->primaryField()?->overwriteLockRemainingRounds);
        $this->assertTrue($this->fields()->isFieldOnlyArt($priest, $state, $rankFive));
        $execution = app(JobArtBattleSupportService::class)->skillForExecution($priest, $rankFive, $state);
        $this->assertSame(0, (int) $execution->power);
        $this->assertSame(0, (int) $execution->hit_count);
        $this->assertSame('TIME_CONTROL_CURRENT_ONLY', $execution->effect_template);
    }

    public function test_rank_nine_star_priest_creates_overlay_only_for_current_job_and_without_primary(): void
    {
        foreach ([85 => true, 24 => false] as $currentJob => $createsOverlay) {
            [$actor, , $state] = $this->battle($currentJob, 53);
            $actor->configureResource('star_mark', 12);
            $actor->setResource('star_mark', 12);
            $rankNine = $this->art(85, 9, 'MAGICAL_DAMAGE');
            $actor->jobArtOrigins[(int) $rankNine->id] = $createsOverlay ? 'current' : 'inherited';
            $resources = app(JobArtV2ResourceService::class);
            $this->assertNull($resources->eligibilityBlockReason($actor, $rankNine, $state));
            $resources->beginAction($actor, $state);
            $resources->applyJobArtCast($actor, $state, $rankNine);

            $this->assertSame(0, $actor->getResource('star_mark'));
            $this->assertSame($createsOverlay ? 'melody' : null, $state->fieldOverlay()?->key);
            $this->assertNull($state->primaryField());
        }
    }

    public function test_same_lineage_inheritance_shares_resource_without_porting_field_operations(): void
    {
        foreach ([24, 53, 85] as $currentJob) {
            foreach ([24, 53, 85] as $artJob) {
                if ($artJob === $currentJob) {
                    continue;
                }
                [$actor, , $state] = $this->battle($currentJob, 62);
                $actor->configureResource('star_mark', 12);
                $actor->setResource('star_mark', 7);
                $rankOne = $this->art($artJob, 1);
                $actor->jobArtOrigins[(int) $rankOne->id] = 'inherited';
                app(JobArtV2ResourceService::class)->beginAction($actor, $state);
                app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $rankOne);
                $this->assertNull($state->primaryField(), "current={$currentJob},art={$artJob}");
                $this->assertSame(11, $actor->getResource('star_mark'), "current={$currentJob},art={$artJob}");
            }
        }
    }

    public function test_dragon_job_can_receive_an_opponent_field_modifier_but_cannot_enable_fields_alone(): void
    {
        [$dragon, $fieldOwner, $state] = $this->battle(62, 53);
        $state->replacePrimaryField(new FieldState('silence', 'enemy', 3, 1, 1, 1));
        app(JobArtV2ResourceService::class)->beginAction($dragon, $state);

        $this->assertTrue($this->fields()->enabledFor($state));
        $this->assertSame(3, $this->fields()->modifyResourceGain($dragon, $state, 4));
        $this->assertSame(4, $this->fields()->modifyResourceGain($fieldOwner, $state, 4));

        [, , $dragonOnly] = $this->battle(62, 10);
        $this->assertFalse($this->fields()->enabledFor($dragonOnly));
    }

    public function test_six_battle_paths_share_action_snapshot_and_round_end_wiring(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $pvp = file_get_contents(base_path('app/Services/PvPBattleService.php'));
        $champ = file_get_contents(base_path('app/Services/ChampBattleService.php'));
        $arena = file_get_contents(base_path('app/Services/ArenaNpcBattleService.php'));

        $this->assertStringContainsString('beginAction($attacker, $state)', $battle);
        $this->assertStringContainsString('endRound($state)', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString('endJobArtV2Round($state)', $tower);
        $this->assertStringContainsString('beginAction($attacker, $state)', $pvp);
        $this->assertStringContainsString('endRound($state)', $pvp);
        $this->assertStringContainsString('beginAction($attacker, $jobArtState)', $champ);
        $this->assertStringContainsString('endRound($jobArtState)', $champ);
        $this->assertStringContainsString('beginAction($attacker, $state)', $arena);
        $this->assertStringContainsString('endRound($state)', $arena);
        $this->assertStringContainsString("\$battleContext = \$enemy->is_boss ? 'boss' : 'pve';", $battle);
    }

    public function test_field_state_has_no_persistence_and_only_field_service_mutates_it_in_production(): void
    {
        $service = file_get_contents(base_path('app/Services/JobArtV2FieldService.php'));
        foreach (['save(', 'DB::', 'update(', 'insert('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service);
        }

        $mutators = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $file = $fileInfo->getPathname();
            $source = file_get_contents($file);
            if (str_contains($source, 'replacePrimaryField(') || str_contains($source, 'replaceFieldOverlay(')) {
                $mutators[] = basename($file);
            }
        }
        sort($mutators);
        $this->assertSame(['BattleState.php', 'JobArtV2FieldService.php'], array_values(array_unique($mutators)));
    }

    public function test_event_deduplication_and_existing_pr6_pr7_pr8_contracts_remain_present(): void
    {
        [$actor, , $state] = $this->battle(53, 24);
        $state->turnCount = 1;
        $this->fields()->deployPrimary($actor, $state, 'star_light', 531, 9);
        $this->fields()->deployPrimary($actor, $state, 'star_light', 531, 9);
        $this->fields()->deployPrimary($actor, $state, 'star_light', 531, 9);
        $events = array_map(static fn ($result): string => $result->event->value, $state->fieldEvents());
        $this->assertSame(1, count(array_filter($events, static fn (string $event): bool => $event === 'field_refreshed')));

        $resolver = file_get_contents(base_path('app/Services/Battle/ActionResolver.php'));
        $damage = file_get_contents(base_path('app/Services/Battle/DamageApplicationService.php'));
        $resources = file_get_contents(base_path('app/Services/JobArtV2ResourceService.php'));
        $this->assertStringContainsString('HitResult::MISS', $resolver);
        $this->assertStringContainsString('HitResult::EVADE', $resolver);
        $this->assertStringContainsString('public function apply(DamageApplicationRequest $request)', $damage);
        $this->assertStringContainsString('claimResourceEvent', $resources);
    }

    private function enableFields(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    private function fields(): JobArtV2FieldService
    {
        return app(JobArtV2FieldService::class);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(?int $playerJob, ?int $enemyJob): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy)];
    }

    private function actor(string $name, bool $isPlayer, ?int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'mag' => 100,
            'agi' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank, string $template = 'MAGICAL_DAMAGE'): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'activation_rate' => 100,
            'sp_cost_fixed' => 1,
            'effect_template' => $template,
            'power' => 100,
            'power_multiplier' => 1.0,
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }
}
