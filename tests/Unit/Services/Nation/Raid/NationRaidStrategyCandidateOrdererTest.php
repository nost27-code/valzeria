<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidStrategyCandidateOrderer;
use Tests\TestCase;

class NationRaidStrategyCandidateOrdererTest extends TestCase
{
    public function test_boss_set_mode_preserves_candidates_without_extra_eligibility_evaluation(): void
    {
        $candidates = [$this->art(1, 'GUARD_BARRIER'), $this->art(2, 'PHYSICAL_DAMAGE'), $this->art(3, 'HEAL')];
        $actor = new BattleActor('player', true, ['hp' => 50, 'max_hp' => 100]);
        $mustNotRun = static fn (): never => throw new \LogicException('Disabled strategy must not reorder candidates.');
        $this->assertSame($candidates, app(NationRaidStrategyCandidateOrderer::class)->order(
            'boss_set', $actor, $candidates, $mustNotRun, $mustNotRun, $mustNotRun,
        ));
    }

    public function test_three_strategies_stably_prioritize_only_equipped_and_eligible_candidates(): void
    {
        $guard = $this->art(1, 'GUARD_BARRIER');
        $damage = $this->art(2, 'PHYSICAL_DAMAGE');
        $heal = $this->art(3, 'HEAL');
        $response = $this->art(4, 'SELF_BUFF');
        $ineligibleDamage = $this->art(5, 'PHYSICAL_DAMAGE');
        $candidates = [$guard, $damage, $heal, $response, $ineligibleDamage];
        $actor = new BattleActor('player', true, [
            'hp' => 50,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'current_job_id' => 1,
        ]);
        $actor->jobArts = $candidates;
        $orderer = app(NationRaidStrategyCandidateOrderer::class);
        $eligible = static fn (Skill $skill): bool => (int) $skill->id !== 5;
        $readyUltimate = static fn (Skill $skill): bool => (int) $skill->id === 4;
        $responseCandidate = static fn (Skill $skill): bool => (int) $skill->id === 4;

        $this->assertSame([2, 4, 1, 3, 5], $this->ids($orderer->order(
            NationRaidRules::STRATEGY_ASSAULT,
            $actor,
            $candidates,
            $eligible,
            $readyUltimate,
            $responseCandidate,
        )));
        $this->assertSame([4, 1, 2, 3, 5], $this->ids($orderer->order(
            NationRaidRules::STRATEGY_INTERCEPT,
            $actor,
            $candidates,
            $eligible,
            $readyUltimate,
            $responseCandidate,
        )));
        $this->assertSame([1, 3, 2, 4, 5], $this->ids($orderer->order(
            NationRaidRules::STRATEGY_FORTIFY,
            $actor,
            $candidates,
            $eligible,
            $readyUltimate,
            $responseCandidate,
        )));
    }

    private function art(int $id, string $template): Skill
    {
        $skill = new Skill([
            'name' => "raid-strategy-art-{$id}",
            'job_id' => 1,
            'skill_type' => 'job_art',
            'learn_rank' => 1,
            'activation_rate' => 100,
            'effect_template' => $template,
            'damage_type' => 'physical',
            'power' => $template === 'PHYSICAL_DAMAGE' ? 100 : 0,
            'heal_percent' => $template === 'HEAL' ? 20 : 0,
            'damage_reduction_percent' => $template === 'GUARD_BARRIER' ? 25 : 0,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    /** @param list<Skill> $skills @return list<int> */
    private function ids(array $skills): array
    {
        return array_map(static fn (Skill $skill): int => (int) $skill->id, $skills);
    }
}
