<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtService;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2DeckRole;
use App\Services\JobArtV2DeckRoleResolver;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ResourceCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SpPressureService;
use Tests\TestCase;

/**
 * 旧C案の主／副／出張分類を廃止した後の互換層を固定する。
 */
class JobArtV2CDesignPrototypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
            // この旧flagを切っても、カードの全効果は変化しない。
            'battle.job_art_v2.c_design_prototype' => false,
        ]);
    }

    public function test_every_equipped_lineage_is_formal_without_main_secondary_or_tech(): void
    {
        $counter = $this->art(1, 1, '見切りの呼吸');
        $eclipse = $this->art(19, 5, 'スピリットスティール');
        $guard = $this->art(15, 5, 'ガーディアンブロウ');
        $resolution = app(JobArtV2DeckRoleResolver::class)->resolveSkills(68, [
            $counter,
            $eclipse,
            $guard,
        ]);

        $this->assertTrue($resolution->active);
        $this->assertTrue($resolution->isValid());
        $this->assertSame(JobArtV2DeckRole::MAIN, $resolution->roleFor($counter));
        $this->assertSame(JobArtV2DeckRole::MAIN, $resolution->roleFor($eclipse));
        $this->assertSame(JobArtV2DeckRole::MAIN, $resolution->roleFor($guard));
        $this->assertNull($resolution->secondaryLineage);
        $this->assertEqualsCanonicalizing(
            ['counter', 'eclipse', 'guard'],
            $resolution->formalLineages(),
        );
        $this->assertSame(4, app(JobArtV2DeckRoleResolver::class)->secondaryProducerGain());
    }

    public function test_each_card_uses_its_own_lineage_resource_at_full_speed(): void
    {
        $counterStarter = $this->art(1, 1, '見切りの呼吸');
        $eclipseStarter = $this->art(19, 1, 'マナピック');
        $eclipseChain = $this->art(19, 5, 'スピリットスティール');
        $actor = $this->actor(68, [$counterStarter, $eclipseStarter, $eclipseChain]);
        $target = $this->actor(66, []);
        $state = new BattleState($actor, $target, 'pve');
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $this->assertSame(4, $resources->applyJobArtCast($actor, $state, $counterStarter)->delta);
        $resources->finishAction($actor, $state);

        $resources->beginAction($actor, $state);
        $this->assertSame(0, $resources->applyJobArtCast($actor, $state, $eclipseStarter)->delta);
        $this->assertSame(4, $resources->recordJobArtHit($actor, $state, $eclipseStarter)->delta);
        $resources->finishAction($actor, $state);

        $this->assertSame(4, $actor->getResource('sword_momentum'));
        $this->assertSame(4, $actor->getResource('eclipse'));
        $this->assertNull($resources->eligibilityBlockReason($actor, $eclipseChain));

        $resources->beginAction($actor, $state);
        $this->assertSame(-4, $resources->applyJobArtCast($actor, $state, $eclipseChain)->delta);
        $this->assertSame(0, $actor->getResource('eclipse'));
        $this->assertSame(4, $actor->getResource('sword_momentum'));
    }

    public function test_foreign_ultimate_uses_its_own_twelve_point_resource(): void
    {
        $starter = $this->art(19, 1, 'マナピック');
        $ultimate = $this->art(19, 9, 'ルーン強奪');
        $actor = $this->actor(68, [$starter, $ultimate]);
        $target = $this->actor(66, []);
        $state = new BattleState($actor, $target, 'pvp');
        $resources = app(JobArtV2ResourceService::class);

        foreach ([4, 8, 12] as $points) {
            $resources->beginAction($actor, $state);
            $this->assertSame(0, $resources->applyJobArtCast($actor, $state, $starter)->delta);
            $this->assertSame(4, $resources->recordJobArtHit($actor, $state, $starter)->delta);
            $resources->finishAction($actor, $state);
            $this->assertSame($points, $actor->getResource('eclipse'));
        }

        $this->assertTrue($resources->isFinisherReady($actor, $ultimate));
        $resources->beginAction($actor, $state);
        $this->assertSame(-12, $resources->applyJobArtCast($actor, $state, $ultimate)->delta);
    }

    public function test_cross_lineage_field_card_keeps_its_full_written_effect(): void
    {
        $fieldStarter = $this->art(6, 1, '魔力の火種');
        $actor = $this->actor(68, [$fieldStarter]);
        $target = $this->actor(66, []);
        $state = new BattleState($actor, $target, 'pve');
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);

        $resources->beginAction($actor, $state);
        $fields->markSkillAction($actor, $state, $fieldStarter);
        $result = $fields->applyJobArtCast($actor, $state, $fieldStarter);

        $this->assertTrue($result->applied);
        $this->assertSame('star_light', $state->primaryField()?->key);
    }

    public function test_presenter_never_labels_a_card_as_secondary_or_tech(): void
    {
        $skill = $this->art(1, 1, '見切りの呼吸');
        $loadout = [$skill, $this->art(19, 1, 'マナピック')];
        $display = app(JobArtV2LoadoutPresenter::class)->forArt(68, $skill, $loadout);

        $this->assertSame('full_effect', $display['deck_role_key']);
        $this->assertSame('全効果', $display['deck_role_label']);
        $this->assertSame('全職で使用可', $display['portability_label']);
        $this->assertSame('反撃', $display['source_lineage_name']);
        $this->assertStringNotContainsString('主系譜', $display['deck_role_note']);
        $this->assertStringNotContainsString('副系譜', $display['deck_role_note']);
        $this->assertStringNotContainsString('出張', $display['deck_role_note']);
    }

    public function test_cost_is_fixed_only_by_starter_chain_and_ultimate(): void
    {
        $service = app(JobArtService::class);
        $character = new \App\Models\Character(['current_job_id' => 68]);

        $this->assertSame(1, $service->effectiveArtCostFor($character, $this->art(1, 1, '見切りの呼吸')));
        $this->assertSame(2, $service->effectiveArtCostFor($character, $this->art(19, 5, 'スピリットスティール')));
        $this->assertSame(3, $service->effectiveArtCostFor($character, $this->art(60, 9, '王冠聖剣陣')));
    }

    public function test_obsolete_c_design_flag_does_not_change_card_resource_resolution(): void
    {
        $skill = $this->art(19, 1, 'マナピック');
        $actor = $this->actor(68, [$skill]);
        $catalog = app(JobArtV2ResourceCatalog::class);
        $before = $catalog->forActorArt($actor, $skill);

        config(['battle.job_art_v2.c_design_prototype' => true]);
        $after = $catalog->forActorArt($actor, $skill);

        $this->assertSame($before, $after);
        $this->assertSame('eclipse', $after['resource_key'] ?? null);
        $this->assertSame(4, $after['resource_gain_points'] ?? null);
    }

    public function test_mana_pick_and_spirit_steal_reduce_enemy_sp_without_recovering_self_sp(): void
    {
        $manaPick = $this->art(19, 1, 'マナピック');
        $spiritSteal = $this->art(19, 5, 'スピリットスティール');
        $actor = $this->actor(68, [$manaPick, $spiritSteal]);
        $target = $this->actor(66, []);
        $actor->mp = 2_000;
        $target->mp = 10_000;
        $target->maxMp = 10_000;
        $state = new BattleState($actor, $target, 'pve');
        $resources = app(JobArtV2ResourceService::class);
        $pressure = app(JobArtV2SpPressureService::class);
        $balance = app(JobArtV2CrownBalanceCatalog::class);

        $manaExecution = $balance->applyToExecution($manaPick);
        $this->assertSame(0, (int) $manaExecution->mp_recover_percent);
        $resources->beginAction($actor, $state);
        $manaResult = $pressure->applyOnHit($actor, $target, $state, $manaPick, HitResult::HIT);
        $this->assertSame(200, $manaResult->actualLoss);
        $this->assertSame(9_800, $target->mp);
        $this->assertSame(2_000, $actor->mp);
        $resources->finishAction($actor, $state);

        $spiritExecution = $balance->applyToExecution($spiritSteal);
        $this->assertSame(0, (int) $spiritExecution->mp_recover_percent);
        $this->assertSame(0.30, (float) $spiritExecution->drain_hp_rate);
        $this->assertSame(12, (int) $spiritExecution->enemy_spr_down_percent);
        $resources->beginAction($actor, $state);
        $spiritResult = $pressure->applyOnHit($actor, $target, $state, $spiritSteal, HitResult::HIT);
        $this->assertSame(300, $spiritResult->actualLoss);
        $this->assertSame(9_500, $target->mp);
        $this->assertSame(2_000, $actor->mp);
    }

    private function art(int $jobId, int $rank, string $name): Skill
    {
        return new Skill([
            'id' => ($jobId * 100) + $rank,
            'job_id' => $jobId,
            'name' => $name,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => match ($rank) { 1 => 100, 5 => 165, default => 255 },
            'hit_count' => 1,
        ]);
    }

    /** @param list<Skill> $arts */
    private function actor(int $jobId, array $arts): BattleActor
    {
        $actor = new BattleActor('actor-'.$jobId, true, [
            'current_job_id' => $jobId,
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => 10_000,
            'max_mp' => 10_000,
            'str' => 1_000,
            'def' => 1_000,
            'agi' => 1_000,
            'mag' => 1_000,
            'spr' => 1_000,
            'luk' => 100,
        ]);
        $actor->jobArts = $arts;
        foreach ($arts as $art) {
            $actor->jobArtOrigins[(int) $art->id] = (int) $art->job_id === $jobId ? 'current' : 'inherited';
            $actor->jobArtRates[(int) $art->id] = 1.0;
        }

        return $actor;
    }
}
