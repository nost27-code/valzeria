<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Tests\TestCase;

final class JobArtV2CDesignSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.c_design_prototype' => true,
        ]);
    }

    public function test_cursor_rotates_all_five_slots_and_never_retries_within_the_same_turn(): void
    {
        $random = $this->random([100, 1, 1, 1, 1, 1]);
        $service = new JobArtV2SelectionService(
            $random,
            new JobArtV2FinisherConditionProvider(),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
        );
        [$actor, $state] = $this->battle();

        $first = $service->selectForTurn($actor, $state);
        $this->assertFalse($first->activated);
        $this->assertSame(1, $first->candidateSkillId);
        $this->assertSame(1, $random->calls);

        $attempted = [];
        for ($i = 0; $i < 5; $i++) {
            $attempted[] = $service->selectForTurn($actor, $state)->candidateSkillId;
        }

        $this->assertSame([2, 3, 4, 5, 1], $attempted);
        $this->assertSame(6, $random->calls);
    }

    /** @return array{BattleActor, BattleState} */
    private function battle(): array
    {
        $skills = [
            $this->art(1, 62, '主始動A'),
            $this->art(2, 52, '主始動B'),
            $this->art(3, 45, '主始動C'),
            $this->art(4, 1, '見切りの呼吸'),
            $this->art(5, 5, '気合拳'),
        ];
        $actor = new BattleActor('player', true, [
            'hp' => 1000,
            'max_hp' => 1000,
            'mp' => 1000,
            'max_mp' => 1000,
            'current_job_id' => 62,
        ]);
        $actor->jobArts = $skills;
        $actor->jobArtActivationPolicy = 'aggressive';
        foreach ($skills as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = (int) $skill->job_id === 62 ? 'current' : 'inherited';
            $actor->jobArtRates[(int) $skill->id] = 1.0;
        }
        $enemy = new BattleActor('enemy', false, ['hp' => 1000, 'max_hp' => 1000]);

        return [$actor, new BattleState($actor, $enemy)];
    }

    private function art(int $id, int $jobId, string $name): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => 1,
            'art_cost' => 1,
            'activation_rate' => 100,
            'sp_cost_fixed' => 0,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'power' => 100,
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function random(array $rolls): JobArtV2RandomSource
    {
        return new class($rolls) extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 1;
            }
        };
    }
}
