<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterSubAreaExplorationState;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\Enemy;

class SubAreaExplorationStateService
{
    public function getOrStart(Character $character, CharacterSubAreaRouteDiscovery $discovery): CharacterSubAreaExplorationState
    {
        $discovery->loadMissing('route.subArea');
        $route = $discovery->route;
        $subArea = $route?->subArea;

        $state = CharacterSubAreaExplorationState::firstOrCreate(
            ['character_id' => $character->id],
            [
                'sub_area_id' => $subArea?->id,
                'sub_area_route_id' => $route?->id,
                'exploration_point' => 0,
                'chain_count' => 0,
                'danger_rate' => 0,
                'sub_area_lord_encountered' => false,
                'started_at' => now(),
            ]
        );

        if ((int) $state->sub_area_route_id !== (int) ($route?->id ?? 0)) {
            $state->forceFill([
                'sub_area_id' => $subArea?->id,
                'sub_area_route_id' => $route?->id,
                'exploration_point' => 0,
                'chain_count' => 0,
                'danger_rate' => 0,
                'sub_area_lord_encountered' => false,
                'started_at' => now(),
            ])->save();
        }

        if (!$discovery->first_entered_at) {
            $discovery->forceFill(['first_entered_at' => now()])->save();
        }

        return $state->fresh();
    }

    public function recordVictory(Character $character, CharacterSubAreaRouteDiscovery $discovery, Enemy $enemy): array
    {
        $state = $this->getOrStart($character, $discovery);
        $beforePoint = (int) $state->exploration_point;
        $beforeChain = (int) $state->chain_count;
        $beforeDanger = (int) $state->danger_rate;
        $addedPoint = $this->pointForEnemy($enemy);
        $afterPoint = $beforePoint + $addedPoint;
        $danger = $this->rollDangerIncrease($character, $enemy, $beforeDanger);

        $state->forceFill([
            'exploration_point' => $afterPoint,
            'chain_count' => $beforeChain + 1,
            'danger_rate' => $danger['after'],
        ])->save();

        if ($afterPoint >= 300 && !$discovery->first_cleared_at) {
            $discovery->forceFill(['first_cleared_at' => now()])->save();
            $discovery->route?->subArea?->increment('total_clears');
        }

        return [
            'state' => $state->fresh(),
            'added_point' => $addedPoint,
            'before_point' => $beforePoint,
            'before_chain' => $beforeChain,
            'danger' => $danger,
            'milestones' => $this->crossedMilestones($beforePoint, $afterPoint),
            'next_milestone' => $this->nextMilestone($afterPoint),
        ];
    }

    public function summary(Character $character, CharacterSubAreaRouteDiscovery $discovery): array
    {
        $discovery->loadMissing('route.subArea');
        $state = CharacterSubAreaExplorationState::where('character_id', $character->id)->first();
        $isCurrent = $state && (int) $state->sub_area_route_id === (int) ($discovery->sub_area_route_id ?? 0);
        $point = $isCurrent ? (int) $state->exploration_point : 0;
        $chain = $isCurrent ? (int) $state->chain_count : 0;
        $danger = $isCurrent ? (int) $state->danger_rate : 0;
        $subArea = $discovery->route?->subArea;

        return [
            'exploration_point' => $point,
            'chain_count' => $chain,
            'danger_rate' => $danger,
            'danger_label' => app(ExplorationStateService::class)->dangerLabel($danger),
            'depth' => [
                'label' => $subArea?->layer_type === 'otherworld' ? '異界層' : '共有深層',
                'key' => $subArea?->layer_type === 'otherworld' ? 'otherworld' : 'deep',
                'recommended_level_min' => (int) ($subArea?->recommended_level_min ?? 1),
                'recommended_level_max' => (int) ($subArea?->recommended_level_max ?? 1),
            ],
            'next_milestone' => $this->nextMilestone($point),
        ];
    }

    public function reset(Character $character): void
    {
        CharacterSubAreaExplorationState::where('character_id', $character->id)->delete();
    }

    private function pointForEnemy(Enemy $enemy): int
    {
        $level = max(1, (int) ($enemy->level ?? 1));

        return max(8, min(28, (int) floor($level / 3) + random_int(6, 12)));
    }

    private function rollDangerIncrease(Character $character, Enemy $enemy, int $beforeDanger): array
    {
        $chance = 12;
        $amount = 5;
        $increased = random_int(1, 100) <= $chance;
        $after = $increased ? min(150, $beforeDanger + $amount) : $beforeDanger;

        return [
            'before' => $beforeDanger,
            'after' => $after,
            'increased' => $increased,
            'increase' => $increased ? $after - $beforeDanger : 0,
            'chance' => $chance,
            'amount' => $amount,
            'label' => app(ExplorationStateService::class)->dangerLabel($after),
        ];
    }

    private function nextMilestone(int $point): ?array
    {
        foreach ($this->milestones() as $milestone) {
            if ($point < $milestone['point']) {
                $milestone['remaining'] = $milestone['point'] - $point;
                return $milestone;
            }
        }

        return null;
    }

    private function crossedMilestones(int $before, int $after): array
    {
        return array_values(array_filter(
            $this->milestones(),
            fn (array $milestone) => $before < $milestone['point'] && $after >= $milestone['point']
        ));
    }

    private function milestones(): array
    {
        return [
            ['point' => 100, 'name' => '深部調査', 'message' => 'サブエリアの奥へ進めるようになりました。'],
            ['point' => 200, 'name' => '希少素材', 'message' => '希少素材を発見しやすくなりました。'],
            ['point' => 300, 'name' => '踏破記録', 'message' => 'このサブエリアの踏破記録が地図に残ります。'],
        ];
    }
}
