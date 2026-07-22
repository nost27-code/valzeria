<?php

namespace App\Services;

use App\Models\Enemy;
use App\Models\ExplorationMap;

class MapExplorationRewardService
{
    /** @var array<int, array{power:int,experience:int,gold:int}>|null */
    private ?array $referenceEnemies = null;

    public function __construct(private readonly CharacterPowerService $power) {}

    /**
     * 地図で補正済みの敵の実戦力から、既存通常ダンジョンの最も近い敵を基準に報酬を決める。
     * Gold は通常戦の低確率・乱数・職業補正をそのまま活かすため、実際に抽選された額だけを倍率補正する。
     *
     * @return array{experience:int,gold:int}
     */
    public function rewardsFor(Enemy $enemy, ExplorationMap $map, int $rolledGold): array
    {
        $reference = $this->closestReference($enemy);
        $modifiers = $map->reward_modifiers_json ?? [];
        $premium = (float) config('exploration_maps.reward_balance.reference_premium_multiplier', 1.10);

        $baseExperience = $this->premium((int) $reference['experience'], $premium);
        $baseGold = $this->premium((int) $reference['gold'], $premium);
        $experience = $this->applyProfile($baseExperience, (float) ($modifiers['exp_multiplier'] ?? 1));
        $goldReward = $this->applyProfile($baseGold, (float) ($modifiers['gold_multiplier'] ?? 1));

        $gold = $rolledGold <= 0
            ? 0
            : max(1, (int) floor($rolledGold * ($goldReward / max(5, (int) $enemy->gold_reward))));

        return ['experience' => $experience, 'gold' => $gold];
    }

    /** @return array{power:int,experience:int,gold:int} */
    private function closestReference(Enemy $enemy): array
    {
        $targetPower = $this->power->fromEnemyStats($enemy->getAttributes());

        return collect($this->referenceEnemies())
            ->sortBy(fn (array $reference) => abs($reference['power'] - $targetPower))
            ->firstOrFail();
    }

    /** @return array<int, array{power:int,experience:int,gold:int}> */
    private function referenceEnemies(): array
    {
        if ($this->referenceEnemies !== null) {
            return $this->referenceEnemies;
        }

        $cityRange = config('exploration_maps.reward_balance.reference_city_range', [1, 10]);
        $references = Enemy::query()
            ->where('is_boss', false)
            ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [(int) $cityRange[0], (int) $cityRange[1]]))
            ->get()
            ->map(fn (Enemy $reference) => [
                'power' => $this->power->fromEnemyStats($reference->getAttributes()),
                'experience' => max(1, (int) $reference->exp_reward),
                'gold' => max(1, (int) $reference->gold_reward),
            ])
            ->all();

        if ($references === []) {
            throw new \RuntimeException('探索の地図の報酬基準となる通常モンスターが見つかりません。');
        }

        return $this->referenceEnemies = $references;
    }

    private function premium(int $reward, float $premium): int
    {
        return max(1, (int) ceil($reward * $premium));
    }

    private function applyProfile(int $baseReward, float $multiplier): int
    {
        // 地図固有の傾向は加算するが、同格の既存ダンジョンより低い報酬にはしない。
        return max($baseReward, (int) floor($baseReward * $multiplier));
    }
}
