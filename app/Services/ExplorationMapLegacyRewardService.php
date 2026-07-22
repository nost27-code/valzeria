<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\Material;
use Illuminate\Support\Collection;

class ExplorationMapLegacyRewardService
{
    private ?Collection $ancientFragments = null;

    public function __construct(
        private readonly ExplorationMapDifficultyService $difficulty,
        private readonly ExplorationMapSeedService $seeds,
    ) {}

    public function ancientFragmentFor(ExplorationMap $map): ?Material
    {
        if (!$this->hasPlainFallbackReward($map)) {
            return null;
        }

        $levels = $this->difficulty->enemyLevels($map);
        if ($levels === [] || min($levels) < (int) config('exploration_maps.legacy_fallback_rewards.ancient_fragment_min_enemy_level', 142)) {
            return null;
        }

        $fragments = $this->ancientFragments();
        if ($fragments->isEmpty()) {
            return null;
        }

        $hash = hash('sha256', (string) $map->seed_hash . ':map:legacy-ancient-fragment');
        $index = hexdec(substr($hash, 0, 8)) % $fragments->count();

        return $fragments->values()->get($index);
    }

    public function tryDrop(Character $character, ExplorationMap $map, Enemy $enemy, string $rewardSeed): ?array
    {
        $fragment = $this->ancientFragmentFor($map);
        $rate = max(0, min(10000, (int) config('exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points', 100)));
        if (!$fragment || $rate === 0 || $this->seeds->int($rewardSeed, 'map:legacy:ancient-fragment', 1, 10000) > $rate) {
            return null;
        }

        return app(DropService::class)->grantMaterialReward($character, $fragment, 'map_ancient_fragment', $enemy);
    }

    private function hasPlainFallbackReward(ExplorationMap $map): bool
    {
        $modifiers = $map->reward_modifiers_json ?? [];
        $profile = config('exploration_maps.reward_profiles.' . $map->reward_profile, []);
        $isCurrentProfile = ($profile['label'] ?? null) !== null && ($profile['modifiers'] ?? []) == $modifiers;

        return !$isCurrentProfile && $modifiers === [];
    }

    /** @return Collection<int, Material> */
    private function ancientFragments(): Collection
    {
        if ($this->ancientFragments !== null) {
            return $this->ancientFragments;
        }

        return $this->ancientFragments = Material::query()
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_ANCIENT')
            ->where('main_use', '!=', '廃止済み')
            ->orderBy('id')
            ->get();
    }
}
