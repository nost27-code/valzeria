<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MapExplorationResult;
use App\Models\Material;
use Illuminate\Support\Collection;

class ExplorationMapLegacyRewardService
{
    private const ACCESSORY_ANCIENT_FRAGMENT_CODE = 'ACC0004';

    private ?Collection $ancientFragments = null;

    private ?Collection $ancientFragmentsWithAccessory = null;

    public function __construct(
        private readonly ExplorationMapDifficultyService $difficulty,
        private readonly ExplorationMapSeedService $seeds,
    ) {}

    public function ancientFragmentFor(ExplorationMap $map): ?Material
    {
        $isAncientFragmentProfile = $map->reward_profile === 'ancient_fragment';
        if (!$isAncientFragmentProfile && !$this->hasPlainFallbackReward($map)) {
            return null;
        }

        $levels = $this->difficulty->enemyLevels($map);
        if ($levels === [] || min($levels) < (int) config('exploration_maps.legacy_fallback_rewards.ancient_fragment_min_enemy_level', 142)) {
            return null;
        }

        if ($isAncientFragmentProfile) {
            $materialCode = (string) data_get($map->generation_payload_json, 'ancient_fragment_material_code', '');
            if ($materialCode !== '') {
                return $this->ancientFragmentsWithAccessory()->firstWhere('material_code', $materialCode);
            }
        }

        return $this->ancientFragmentForSeedHash((string) $map->seed_hash);
    }

    public function ancientFragmentForSeedHash(string $seedHash, bool $includeAccessoryFragment = false): ?Material
    {
        $fragments = $includeAccessoryFragment
            ? $this->ancientFragmentsWithAccessory()
            : $this->ancientFragments();
        if ($fragments->isEmpty()) {
            return null;
        }

        $hash = hash('sha256', $seedHash . ':map:legacy-ancient-fragment');
        $index = hexdec(substr($hash, 0, 8)) % $fragments->count();

        return $fragments->values()->get($index);
    }

    public function gradeBonusAncientFragmentFor(ExplorationMap $map): ?Material
    {
        $levels = $this->difficulty->enemyLevels($map);
        if ($levels === [] || min($levels) < (int) config('exploration_maps.legacy_fallback_rewards.ancient_fragment_min_enemy_level', 142)) {
            return null;
        }

        if ($map->reward_profile === 'ancient_fragment') {
            return $this->ancientFragmentFor($map);
        }

        return $this->ancientFragmentForSeedHash((string) $map->seed_hash);
    }

    public function tryDrop(Character $character, ExplorationMap $map, Enemy $enemy, string $rewardSeed): ?array
    {
        $fragment = $this->ancientFragmentFor($map);
        $rateKey = $map->reward_profile === 'ancient_fragment'
            ? 'exploration_maps.reward_profiles.ancient_fragment.drop_rate_basis_points'
            : 'exploration_maps.legacy_fallback_rewards.ancient_fragment_drop_rate_basis_points';
        $rate = max(0, min(10000, (int) config($rateKey, 38)));
        if (!$fragment) {
            return null;
        }

        $randomDrop = $rate > 0 && $this->seeds->int($rewardSeed, 'map:legacy:ancient-fragment', 1, 10000) <= $rate;
        $guaranteedDrop = !$randomDrop && $this->shouldGuaranteeFragment($character, $map, $fragment);
        if (!$randomDrop && !$guaranteedDrop) {
            return null;
        }

        return app(DropService::class)->grantMaterialReward(
            $character,
            $fragment,
            'map_ancient_fragment',
            $enemy,
        );
    }

    private function shouldGuaranteeFragment(Character $character, ExplorationMap $map, Material $fragment): bool
    {
        if ($map->reward_profile !== 'ancient_fragment') {
            return false;
        }

        $threshold = max(0, (int) config('exploration_maps.reward_profiles.ancient_fragment.guaranteed_after_wins_without_fragment', 0));
        if ($threshold === 0 || !$map->exists) {
            return false;
        }

        $previousWins = MapExplorationResult::query()
            ->where('map_id', $map->id)
            ->where('character_id', $character->id)
            ->where('battle_result', 'victory')
            ->orderByDesc('global_exploration_index')
            ->limit($threshold - 1)
            ->get(['drops_json']);

        if ($previousWins->count() !== $threshold - 1) {
            return false;
        }

        foreach ($previousWins as $win) {
            foreach (($win->drops_json['materials'] ?? []) as $drop) {
                if ((int) ($drop['material_id'] ?? 0) === (int) $fragment->id) {
                    return false;
                }
            }
        }

        return true;
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

    /** @return Collection<int, Material> */
    private function ancientFragmentsWithAccessory(): Collection
    {
        if ($this->ancientFragmentsWithAccessory !== null) {
            return $this->ancientFragmentsWithAccessory;
        }

        return $this->ancientFragmentsWithAccessory = $this->ancientFragments()
            ->concat(Material::query()
                ->where('material_code', self::ACCESSORY_ANCIENT_FRAGMENT_CODE)
                ->where('main_use', '!=', '廃止済み')
                ->get())
            ->sortBy('id')
            ->values();
    }
}
