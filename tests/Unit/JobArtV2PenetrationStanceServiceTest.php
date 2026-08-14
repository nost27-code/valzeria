<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\JobArtV2PenetrationStanceService;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\ResourceChangeResult;
use Tests\TestCase;

class JobArtV2PenetrationStanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableAll();
        config([
            'battle.job_art_v2.normalized_sp' => false,
            'battle.job_art_v2.fields' => false,
        ]);
    }

    public function test_stance_is_default_off_and_fails_closed_on_every_dependency(): void
    {
        $config = require base_path('config/battle.php');
        $this->assertFalse($config['job_art_v2']['penetration_stance']);

        $actor = $this->actor(62);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'penetration', 'penetration_stance'] as $disabled) {
            $this->enableAll();
            config(["battle.job_art_v2.{$disabled}" => false]);
            $this->assertFalse($this->service()->enabledFor($actor), $disabled);
        }

        $this->enableAll();
        $this->assertTrue($this->service()->enabledFor($actor));
        $this->assertFalse($this->service()->enabledFor($this->actor(70)));
        $this->assertFalse(config('battle.job_art_v2.normalized_sp'));
        $this->assertFalse(config('battle.job_art_v2.fields'));
    }

    public function test_rank_one_grants_one_non_expiring_charge_without_stacking(): void
    {
        $actor = $this->actor(62);
        $state = $this->state($actor);
        $rankOne = $this->art(1);
        $this->markCurrent($actor, $rankOne);

        $this->beginCast($actor, $state, $rankOne);
        $this->assertTrue($actor->hasPiercingStance());

        $state->turnCount = 20;
        $this->assertTrue($actor->hasPiercingStance());

        $this->beginCast($actor, $state, $rankOne);
        $this->assertTrue($actor->hasPiercingStance());
        $this->assertCount(2, array_filter(
            $state->piercingStanceEvents(),
            static fn (array $event): bool => $event['event'] === JobArtV2PenetrationStanceService::EVENT_ACQUIRED,
        ));
    }

    public function test_rank_five_without_stance_has_no_v2_penetration_then_reforms_for_the_next_cast(): void
    {
        $actor = $this->actor(62);
        $defender = $this->actor(null, 100, 80);
        $state = $this->state($actor, $defender);
        $rankFive = $this->art(5);
        $this->markCurrent($actor, $rankFive);

        $this->beginCast($actor, $state, $rankFive);
        $first = $this->service()->defenseOverrides($actor, $defender, $state, $rankFive);
        $this->assertNull($first['penetration_rate']);
        $this->assertNull($first['def']);
        $this->service()->completeCast($actor, $state, $rankFive);
        $this->assertTrue($actor->hasPiercingStance());

        $this->beginCast($actor, $state, $rankFive);
        $second = $this->service()->defenseOverrides($actor, $defender, $state, $rankFive);
        $this->assertSame(0.35, $second['penetration_rate']);
        $this->assertSame(65, $second['def']);
        $this->assertFalse($actor->hasPiercingStance());
        $this->service()->completeCast($actor, $state, $rankFive);
        $this->assertTrue($actor->hasPiercingStance());
    }

    public function test_rank_five_reforms_after_hit_miss_and_evade_without_self_applying_the_new_charge(): void
    {
        foreach (['hit', 'miss', 'evade'] as $outcome) {
            $actor = $this->actor(62);
            $defender = $this->actor(null, 100, 80);
            $state = $this->state($actor, $defender);
            $rankFive = $this->art(5);
            $this->markCurrent($actor, $rankFive);

            $this->beginCast($actor, $state, $rankFive);
            $this->assertNull(
                $this->service()->defenseOverrides($actor, $defender, $state, $rankFive)['penetration_rate'],
                $outcome,
            );
            $this->service()->completeCast($actor, $state, $rankFive);
            $this->assertTrue($actor->hasPiercingStance(), $outcome);
        }
    }

    public function test_rank_nine_uses_only_the_start_snapshot_and_never_reforms(): void
    {
        $actor = $this->actor(62);
        $defender = $this->actor(null, 101, 80);
        $rankNine = $this->art(9);
        $this->markCurrent($actor, $rankNine);

        $withoutState = $this->state($actor, $defender);
        $this->beginCast($actor, $withoutState, $rankNine);
        $this->assertNull($this->service()->defenseOverrides($actor, $defender, $withoutState, $rankNine)['penetration_rate']);
        $this->service()->completeCast($actor, $withoutState, $rankNine);
        $this->assertFalse($actor->hasPiercingStance());

        foreach (['hit', 'miss', 'evade'] as $outcome) {
            $actor->setPiercingStance(true);
            $state = $this->state($actor, $defender);
            $this->beginCast($actor, $state, $rankNine);
            $this->assertSame(0.50, $this->service()->defenseOverrides($actor, $defender, $state, $rankNine)['penetration_rate'], $outcome);
            $this->assertFalse($actor->hasPiercingStance(), $outcome);
            $this->service()->completeCast($actor, $state, $rankNine);
            $this->assertFalse($actor->hasPiercingStance(), $outcome);
        }
    }

    public function test_inherited_arts_and_other_current_jobs_never_use_the_stance(): void
    {
        $rankOne = $this->art(1);
        $rankFive = $this->art(5);

        foreach ([$this->actor(53), $this->actor(62)] as $actor) {
            $actor->jobArtOrigins[(int) $rankOne->id] = 'inherited';
            $actor->jobArtOrigins[(int) $rankFive->id] = 'inherited';
            $defender = $this->actor(null, 100, 80);
            $state = $this->state($actor, $defender);
            $actor->setPiercingStance(true);

            $this->beginCast($actor, $state, $rankOne);
            $this->assertTrue($actor->hasPiercingStance());
            $this->assertNull($this->service()->defenseOverrides($actor, $defender, $state, $rankFive)['penetration_rate']);
            $this->assertSame([], $state->piercingStanceEvents());
        }
    }

    public function test_flag_off_keeps_pr10_unconditional_penetration_and_penetration_off_keeps_legacy(): void
    {
        $actor = $this->actor(62);
        $defender = $this->actor(null, 100, 80);
        $rankFive = $this->art(5);
        $this->markCurrent($actor, $rankFive);

        config(['battle.job_art_v2.penetration_stance' => false]);
        $state = $this->state($actor, $defender);
        $this->assertSame(0.35, $this->service()->defenseOverrides($actor, $defender, $state, $rankFive)['penetration_rate']);

        config([
            'battle.job_art_v2.penetration_stance' => true,
            'battle.job_art_v2.penetration' => false,
        ]);
        $state = $this->state($actor, $defender);
        $this->beginCast($actor, $state, $rankFive);
        $legacy = $this->service()->defenseOverrides($actor, $defender, $state, $rankFive);
        $this->assertNull($legacy['penetration_rate']);
        $this->assertNull($legacy['def']);
        $this->assertFalse($actor->hasPiercingStance());
    }

    public function test_existing_ignore_is_preserved_without_stance_and_competes_by_maximum_with_stance(): void
    {
        $actor = $this->actor(62);
        $defender = $this->actor(null, 100, 80);
        $rankFive = $this->art(5, 20);
        $this->markCurrent($actor, $rankFive);

        $without = $this->state($actor, $defender);
        $this->beginCast($actor, $without, $rankFive);
        $legacy = $this->service()->defenseOverrides($actor, $defender, $without, $rankFive);
        $this->assertSame(80, $legacy['def']);
        $this->assertSame(64, $legacy['spr']);
        $this->assertNull($legacy['penetration_rate']);
        $this->service()->completeCast($actor, $without, $rankFive);

        $with = $this->state($actor, $defender);
        $this->beginCast($actor, $with, $rankFive);
        $penetrated = $this->service()->defenseOverrides($actor, $defender, $with, $rankFive);
        $this->assertSame(65, $penetrated['def']);
        $this->assertNull($penetrated['spr']);
        $this->assertSame(0.35, $penetrated['penetration_rate']);
    }

    public function test_source_action_snapshot_and_events_are_idempotent_and_consume_no_rng(): void
    {
        $actor = $this->actor(62);
        $state = $this->state($actor);
        $rankFive = $this->art(5);
        $this->markCurrent($actor, $rankFive);
        $actor->setPiercingStance(true);
        $state->beginSourceAction();

        mt_srand(62110);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(62110);
        $this->service()->beginCast($actor, $state, $rankFive);
        $this->service()->beginCast($actor, $state, $rankFive);
        $this->service()->completeCast($actor, $state, $rankFive);
        $this->service()->completeCast($actor, $state, $rankFive);
        $actual = [mt_rand(), mt_rand()];

        $this->assertSame($expected, $actual);
        $this->assertTrue($actor->hasPiercingStance());
        $this->assertCount(2, $state->piercingStanceEvents());
        $this->assertSame(
            [JobArtV2PenetrationStanceService::EVENT_CONSUMED, JobArtV2PenetrationStanceService::EVENT_REFORMED],
            array_column($state->piercingStanceEvents(), 'event'),
        );
    }

    public function test_v2_ignores_legacy_rank_five_cooldown_and_rank_nine_use_cap(): void
    {
        $actor = $this->actor(62);
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 12);
        $state = $this->state($actor);
        $rankFive = $this->art(5);
        $rankFive->cooldown_turns = 2;
        $rankFive->max_uses_per_battle = null;
        $rankNine = $this->art(9);
        $rankNine->max_uses_per_battle = 1;
        $this->markCurrent($actor, $rankFive, $rankNine);
        $selection = app(JobArtV2SelectionService::class);

        $state->jobArtUseCounts[(int) $rankFive->id] = 3;
        $this->assertTrue($selection->isEligible($actor, $state, $rankFive, (int) $rankFive->id));
        $state->jobArtCooldowns[(int) $rankFive->id] = 1;
        $this->assertTrue($selection->isEligible($actor, $state, $rankFive, (int) $rankFive->id));
        unset($state->jobArtCooldowns[(int) $rankFive->id]);
        $this->assertTrue($selection->isEligible($actor, $state, $rankFive, (int) $rankFive->id));

        $state->jobArtUseCounts[(int) $rankNine->id] = 1;
        $this->assertTrue($selection->isEligible($actor, $state, $rankNine, (int) $rankNine->id));

        $master = json_decode((string) file_get_contents(base_path('database/data/job_arts.json')), true, flags: JSON_THROW_ON_ERROR);
        $rankFiveMaster = collect($master)->first(fn (array $row): bool => (int) $row['job_id'] === 62 && (int) $row['learn_rank'] === 5);
        $rankNineMaster = collect($master)->first(fn (array $row): bool => (int) $row['job_id'] === 62 && (int) $row['learn_rank'] === 9);
        $this->assertNull($rankFiveMaster['max_uses_per_battle']);
        $this->assertSame(2, $rankFiveMaster['cooldown_turns']);
        $this->assertSame(1, $rankNineMaster['max_uses_per_battle']);
    }

    public function test_deterministic_resource_and_stance_timelines(): void
    {
        $actor = $this->actor(62);
        $defender = $this->actor(null, 100, 80);
        $state = $this->state($actor, $defender);
        $rankOne = $this->art(1);
        $rankFive = $this->art(5);
        $rankNine = $this->art(9);
        $this->markCurrent($actor, $rankOne, $rankFive, $rankNine);
        $resource = app(JobArtV2ResourceService::class);

        $rankOneResource = $this->castWithResource($resource, $actor, $state, $rankOne);
        $this->assertTrue($rankOneResource->applied, (string) $rankOneResource->blockedReason);
        $this->assertSame('dragon_force', $rankOneResource->resourceKey);
        $this->assertSame(4, $rankOneResource->after);
        $this->assertSame(4, $actor->getResource('dragon_force'));
        $this->assertTrue($actor->hasPiercingStance());

        $rankFiveResource = $this->castWithResource($resource, $actor, $state, $rankFive);
        $this->assertTrue($rankFiveResource->applied, (string) $rankFiveResource->blockedReason);
        $this->assertSame(-4, $rankFiveResource->delta);
        $this->assertSame(0, $actor->getResource('dragon_force'));
        $this->assertTrue($actor->hasPiercingStance());

        for ($i = 0; $i < 4; $i++) {
            $resource->beginAction($actor, $state);
            $resource->recordNormalAttackHit($actor, $state);
        }
        $this->assertSame(4, $actor->getResource('dragon_force'));
        $this->castWithResource($resource, $actor, $state, $rankFive);
        $this->assertSame(0, $actor->getResource('dragon_force'));
        $this->assertTrue($actor->hasPiercingStance());

        for ($i = 0; $i < 3; $i++) {
            $this->castWithResource($resource, $actor, $state, $rankOne);
        }
        $this->assertSame(12, $actor->getResource('dragon_force'));
        $this->castWithResource($resource, $actor, $state, $rankNine, complete: false);
        $this->assertSame(0, $actor->getResource('dragon_force'));
        $this->assertFalse($actor->hasPiercingStance());

        $fresh = $this->actor(62);
        $freshState = $this->state($fresh, $defender);
        $this->markCurrent($fresh, $rankFive);
        for ($i = 0; $i < 4; $i++) {
            $resource->beginAction($fresh, $freshState);
            $resource->recordNormalAttackHit($fresh, $freshState);
        }
        $resource->beginAction($fresh, $freshState);
        $resource->applyJobArtCast($fresh, $freshState, $rankFive);
        $this->service()->beginCast($fresh, $freshState, $rankFive);
        $this->assertNull($this->service()->defenseOverrides($fresh, $defender, $freshState, $rankFive)['penetration_rate']);
        $this->service()->completeCast($fresh, $freshState, $rankFive);
        $this->assertTrue($fresh->hasPiercingStance());
    }

    private function enableAll(): void
    {
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'penetration', 'penetration_stance'] as $flag) {
            config(["battle.job_art_v2.{$flag}" => true]);
        }
    }

    private function service(): JobArtV2PenetrationStanceService
    {
        return app(JobArtV2PenetrationStanceService::class);
    }

    private function beginCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $state->beginSourceAction();
        $this->service()->beginCast($actor, $state, $skill);
    }

    private function castWithResource(
        JobArtV2ResourceService $resource,
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        bool $complete = true,
    ): ResourceChangeResult {
        $resource->beginAction($actor, $state);
        $resourceResult = $resource->applyJobArtCast($actor, $state, $skill);
        $this->service()->beginCast($actor, $state, $skill);
        if ($complete) {
            $this->service()->completeCast($actor, $state, $skill);
        }

        return $resourceResult;
    }

    private function state(BattleActor $actor, ?BattleActor $defender = null): BattleState
    {
        return new BattleState($actor, $defender ?? $this->actor(null), 'pve');
    }

    private function actor(?int $jobId, int $def = 100, int $spr = 100): BattleActor
    {
        return new BattleActor('actor', true, [
            'hp' => 100_000,
            'max_hp' => 100_000,
            'mp' => 400,
            'max_mp' => 400,
            'str' => 1000,
            'def' => $def,
            'agi' => 100,
            'mag' => 100,
            'spr' => $spr,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $rank, int $existingIgnore = 0): Skill
    {
        $skill = new Skill([
            'name' => match ($rank) {
                1 => '竜冠の槍印',
                5 => '竜冠穿槍',
                default => '竜冠天穿槍',
            },
            'skill_type' => 'job_art',
            'job_id' => 62,
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => match ($rank) {
                1 => 225,
                5 => 285,
                default => 355,
            },
            'power_multiplier' => match ($rank) {
                1 => 2.25,
                5 => 2.85,
                default => 3.55,
            },
            'hit_count' => 1,
            'def_ignore_percent' => $existingIgnore,
            'cooldown_turns' => $rank === 5 ? 2 : ($rank === 9 ? 5 : 0),
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', 62_000 + $rank + $existingIgnore);

        return $skill;
    }

    private function markCurrent(BattleActor $actor, Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
        }
        $actor->jobArts = array_values(array_unique([
            ...$actor->jobArts,
            ...$skills,
        ], SORT_REGULAR));
    }
}
