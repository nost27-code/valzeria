<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MonsterPrefix;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ExplorationMapGenerator
{
    public function __construct(
        private readonly ExplorationMapSeedService $seeds,
        private readonly ExplorationMapDifficultyService $difficulty,
        private readonly ExplorationMapLegacyRewardService $legacyRewards,
        private readonly ExplorationMapRewardProfileService $rewardProfiles,
    ) {}

    public function generate(Character $owner, Area $area, Enemy $sourceMonster, string $dropEventUuid): ExplorationMap
    {
        $uuid = (string) Str::uuid();
        $root = $this->seeds->createRootSeed($uuid, $dropEventUuid, $owner->id, $area->id, $sourceMonster->id);
        $grade = $this->grade($root);
        $targetArea = $this->targetArea($root, $area);
        $profile = $this->seeds->weightedPick(
            $root,
            'map:v1:reward_profile',
            collect(config('exploration_maps.reward_profiles', []))
                ->map(fn (array $value, string $key) => ['value' => $key, 'weight' => (int) ($value['weight'] ?? 100)])
                ->values()
                ->all()
        )['value'];
        $levelOffset = $this->levelOffset($root, $grade, 'map:v2:map_level_offset');
        $targetMonsters = $this->targetMonsters($grade, $profile, $levelOffset);
        $singleSpeciesTarget = $this->singleSpeciesTarget($root, $targetMonsters, $grade, $levelOffset);
        $targetMonster = $singleSpeciesTarget['enemy'] ?? $this->targetMonster($root, $targetMonsters);
        $singleSpeciesKey = $singleSpeciesTarget['species_key'] ?? null;
        $type = $this->dungeonType($root, $targetArea);
        $level = min(255, max(1, (int) $targetMonster->level + $levelOffset));
        $limit = $this->limit($root, $grade, $profile);
        $effects = $this->effects($root, $type, $grade);
        $normal = $this->variants($root, $targetArea, $targetMonster, $type, $level, $grade, false, $singleSpeciesKey);
        $boss = $this->variants($root, $targetArea, $targetMonster, $type, $level, $grade, true, $singleSpeciesKey);
        $parts = $this->nameParts($root, $type, $effects, $profile);
        $name = implode('', [$parts['prefix'], $parts['proper'], $parts['place'], 'の地図']);
        $recommendedTownId = (int) ($targetArea->city_id ?? 0) ?: null;
        $seedHash = $this->seeds->hash($root);
        $generationPayload = ['grade' => $grade, 'dungeon_type' => $type, 'map_level' => $level, 'exploration_limit' => $limit, 'origin_area_id' => $area->id, 'origin_monster_id' => $sourceMonster->id, 'target_area_id' => $targetArea->id];

        if ($singleSpeciesKey !== null) {
            $generationPayload['enemy_composition'] = [
                'mode' => 'single_species',
                'species_key' => $singleSpeciesKey,
            ];
        }

        if ($profile === 'ancient_fragment') {
            $fragment = $this->legacyRewards->ancientFragmentForSeedHash($seedHash);
            if (!$fragment) {
                throw new \RuntimeException('地図用の古代片素材が見つかりません。');
            }
            $generationPayload['ancient_fragment_material_code'] = $fragment->material_code;
        }

        return ExplorationMap::create([
            'uuid' => $uuid, 'owner_character_id' => $owner->id, 'source_area_id' => $targetArea->id, 'source_monster_id' => $targetMonster->id, 'source_drop_event_uuid' => $dropEventUuid,
            'seed_version' => 1, 'seed_encrypted' => $this->seeds->encrypt($root), 'seed_hash' => $seedHash, 'generation_version' => 6,
            'map_grade' => $grade, 'map_level' => $level, 'dungeon_type' => $type, 'reward_profile' => $profile, 'exploration_limit' => $limit,
            'name' => $name, 'name_parts_json' => $parts, 'normal_monster_variants_json' => $normal, 'boss_monster_variants_json' => $boss,
            'environment_effects_json' => $effects, 'reward_modifiers_json' => $this->rewardProfiles->modifiers($profile, $grade),
            'generation_payload_json' => $generationPayload,
            'recommended_town_id' => $recommendedTownId, 'status' => 'uninvestigated',
        ]);
    }

    private function grade(string $root): string
    {
        return match (true) { ($roll = $this->seeds->int($root, 'map:v1:grade', 1, 10000)) <= 6000 => 'normal', $roll <= 8500 => 'rare', $roll <= 9700 => 'hero', default => 'legend' };
    }
    private function limit(string $root, string $grade, string $profile): int
    {
        $range = config("exploration_maps.grade_limits.{$grade}");
        $limit = (int) $range['min'] + $this->seeds->int($root, 'map:v1:exploration_limit', 0, intdiv((int) $range['max'] - (int) $range['min'], 10)) * 10;
        $multiplier = $this->rewardProfiles->explorationLimitMultiplier($profile, $grade);

        return max(10, (int) (round(($limit * $multiplier) / 10) * 10));
    }
    private function dungeonType(string $root, Area $area): string
    {
        $types = config('exploration_maps.town_biomes.' . (int) $area->city_id, ['ruins']);
        return $this->seeds->weightedPick($root, 'map:v1:dungeon_type', array_map(fn ($type) => ['value' => $type, 'weight' => 100], $types))['value'];
    }
    private function targetArea(string $root, Area $originArea): Area
    {
        $weights = config('exploration_maps.target_city_weights', []);
        $availableCities = Area::query()
            ->whereBetween('city_id', [1, 10])
            ->whereHas('enemies', fn ($query) => $query->where('is_boss', false))
            ->pluck('city_id')
            ->unique()
            ->values()
            ->all();
        $choices = collect($weights)
            ->filter(fn ($weight, $cityId) => in_array((int) $cityId, $availableCities, true))
            ->map(fn ($weight, $cityId) => ['value' => (int) $cityId, 'weight' => (int) $weight])
            ->values()
            ->all();
        $targetCityId = $choices === []
            ? (int) $originArea->city_id
            : (int) $this->seeds->weightedPick($root, 'map:v3:target_city', $choices)['value'];
        $areas = Area::query()
            ->where('city_id', $targetCityId)
            ->whereHas('enemies', fn ($query) => $query->where('is_boss', false))
            ->orderBy('id')
            ->get();

        if ($areas->isEmpty()) {
            return $originArea;
        }

        return $areas[$this->seeds->int($root, 'map:v3:target_area', 0, $areas->count() - 1)];
    }
    private function targetMonsters(string $grade, string $profile, int $levelOffset): Collection
    {
        $range = $this->baseMonsterLevelRange($grade);
        if ($profile === 'ancient_fragment') {
            $minimumEnemyLevel = (int) config('exploration_maps.reward_profiles.ancient_fragment.minimum_enemy_level', 142);
            $range = [
                'min' => max(1, $minimumEnemyLevel - $levelOffset),
                'max' => max(1, 255 - $levelOffset),
            ];
        }
        $monsters = Enemy::query()
            ->where('is_boss', false)
            ->whereBetween('level', [$range['min'], $range['max']])
            ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]))
            ->orderBy('id')
            ->get();

        if ($monsters->isEmpty()) {
            throw new \RuntimeException('地図用の通常モンスターが見つかりません。');
        }

        return $monsters;
    }
    private function targetMonster(string $root, Collection $monsters): Enemy
    {
        return $monsters[$this->seeds->int($root, 'map:v3:target_monster', 0, $monsters->count() - 1)];
    }
    /**
     * @return array{enemy: Enemy, species_key: string}|null
     */
    private function singleSpeciesTarget(string $root, Collection $targetMonsters, string $grade, int $levelOffset): ?array
    {
        $rate = min(10000, max(0, (int) config('exploration_maps.single_species.rate_basis_points', 0)));
        if ($rate === 0 || $this->seeds->int($root, 'map:v6:single_species:roll', 1, 10000) > $rate) {
            return null;
        }

        $flavors = config('exploration_maps.single_species.surroundings', []);
        $speciesKeys = array_keys(is_array($flavors) ? $flavors : []);
        if ($speciesKeys === []) {
            return null;
        }

        $minimumEnemies = max(1, (int) config('exploration_maps.single_species.minimum_distinct_enemies', 4));
        $offsetRange = $this->difficulty->levelOffsetRange($grade);
        $variantPool = Enemy::query()
            ->where('is_boss', false)
            ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]))
            ->orderBy('id')
            ->get(['id', 'level', 'species_key', 'family_key']);
        $variantsBySpecies = $variantPool
            ->filter(fn (Enemy $enemy) => in_array($this->speciesKey($enemy), $speciesKeys, true))
            ->groupBy(fn (Enemy $enemy) => $this->speciesKey($enemy));
        $eligibleTargets = $targetMonsters->filter(function (Enemy $target) use ($levelOffset, $minimumEnemies, $offsetRange, $variantsBySpecies): bool {
            $speciesKey = $this->speciesKey($target);
            $mapLevel = min(255, max(1, (int) $target->level + $levelOffset));
            $minimumLevel = max(1, $mapLevel - (int) $offsetRange['max']);

            return $variantsBySpecies
                ->get($speciesKey, collect())
                ->filter(fn (Enemy $enemy) => (int) $enemy->level >= $minimumLevel && (int) $enemy->level <= $mapLevel)
                ->count() >= $minimumEnemies;
        });
        $targetsBySpecies = $eligibleTargets
            ->groupBy(fn (Enemy $enemy) => $this->speciesKey($enemy))
            ->sortKeys();

        if ($targetsBySpecies->isEmpty()) {
            return null;
        }

        $eligibleSpeciesKeys = $targetsBySpecies->keys()->values();
        $speciesKey = (string) $eligibleSpeciesKeys[
            $this->seeds->int($root, 'map:v6:single_species:species', 0, $eligibleSpeciesKeys->count() - 1)
        ];
        $speciesTargets = $targetsBySpecies->get($speciesKey)->sortBy('id')->values();
        $enemy = $speciesTargets[
            $this->seeds->int($root, 'map:v6:single_species:target_monster', 0, $speciesTargets->count() - 1)
        ];

        return ['enemy' => $enemy, 'species_key' => $speciesKey];
    }
    private function sourceBonus(Enemy $enemy): int { return $enemy->is_boss ? 5 : ((bool) ($enemy->is_elite ?? false) ? 3 : 0); }
    private function gradeBonus(string $grade): int { return ['normal' => 0, 'rare' => 5, 'hero' => 10, 'legend' => 15][$grade]; }
    private function effects(string $root, string $type, string $grade): array
    {
        $count = $this->seeds->int($root, 'map:v1:environment_count', 0, $grade === 'legend' ? 3 : 2);
        $pool = str_contains($type, 'ice') || str_contains($type, 'snow') ? ['極寒', '氷晶を纏う'] : (str_contains($type, 'mine') || str_contains($type, 'forge') ? ['灼熱', '豊かな鉱脈'] : ['濃霧', '精霊の祝福', '宝物庫']);
        $effects = [];
        for ($i = 0; $i < $count; $i++) $effects[] = $pool[$this->seeds->int($root, "map:v1:environment:{$i}", 0, count($pool) - 1)];
        return array_values(array_unique($effects));
    }
    private function variants(string $root, Area $area, Enemy $referenceEnemy, string $type, int $level, string $grade, bool $boss, ?string $speciesKey = null): array
    {
        $offsetRange = $this->difficulty->levelOffsetRange($grade);
        $baseLevelRange = [
            'min' => max(1, $level - $offsetRange['max']),
            'max' => $level,
        ];
        $query = Enemy::query()
            ->where('is_boss', $boss)
            ->whereBetween('level', [$baseLevelRange['min'], $baseLevelRange['max']])
            ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]));
        $this->applySpeciesConstraint($query, $speciesKey);
        $candidates = $query->get();
        if ($candidates->isEmpty() && $boss) return [];
        if ($candidates->isEmpty()) {
            $fallbackQuery = Enemy::query()
                ->where('is_boss', false)
                ->whereBetween('level', [$baseLevelRange['min'], $baseLevelRange['max']])
                ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]));
            $this->applySpeciesConstraint($fallbackQuery, $speciesKey);
            $candidates = $fallbackQuery->get();
        }
        $candidates = $this->powerBalancedCandidates($candidates, $referenceEnemy, $level);
        $targetCount = min($candidates->count(), $boss ? min(3, max(1, $this->seeds->int($root, 'map:v1:boss_count', 1, 3))) : min(7, max(4, $this->seeds->int($root, 'map:v1:monster_count', 4, 7))));
        $picked = [];
        for ($i = 0; $i < $targetCount; $i++) {
            $remaining = $candidates->reject(fn (Enemy $enemy) => isset($picked[$enemy->id]));
            if ($remaining->isEmpty()) break;
            $enemy = $remaining->sortBy('id')->values()[$this->seeds->int($root, ($boss ? 'map:v1:boss:' : 'map:v1:monster:') . $i, 0, $remaining->count() - 1)];
            $picked[$enemy->id] = $this->variant($root, $enemy, $type, $level, $boss);
        }
        return array_values($picked);
    }
    private function applySpeciesConstraint($query, ?string $speciesKey): void
    {
        if ($speciesKey === null) {
            return;
        }

        $query->where(function ($query) use ($speciesKey): void {
            $query->where('species_key', $speciesKey)
                ->orWhere(function ($query) use ($speciesKey): void {
                    $query->where(function ($query): void {
                        $query->whereNull('species_key')->orWhere('species_key', '');
                    })->where('family_key', $speciesKey);
                });
        });
    }
    private function speciesKey(Enemy $enemy): string
    {
        return (string) ($enemy->species_key ?: $enemy->family_key ?: '');
    }
    private function variant(string $root, Enemy $enemy, string $type, int $mapLevel, bool $boss): array
    {
        $prefixes = MonsterPrefix::query()->where('is_active', true)->where($boss ? 'boss_eligible' : 'normal_eligible', true)->get();
        $prefix = $prefixes->sortBy('id')->first();
        if ($prefixes->isNotEmpty()) $prefix = $prefixes->sortBy('id')->values()[$this->seeds->int($root, "map:v1:monster_prefix:{$enemy->id}", 0, $prefixes->count() - 1)];
        $name = (($prefix?->display_name ?? '異界の') . $enemy->name);
        $enemyLevel = max((int) $enemy->level, $mapLevel);
        $levelOffset = $enemyLevel - (int) $enemy->level;

        return ['base_monster_id' => $enemy->id, 'prefix_id' => $prefix?->id, 'display_name' => mb_substr($name, 0, $boss ? 30 : 20), 'stat_modifiers' => $prefix?->stat_modifiers_json ?? [], 'reward_modifiers' => $prefix?->reward_modifiers_json ?? [], 'biome' => $type, 'enemy_level' => $enemyLevel, 'level_offset' => $levelOffset];
    }

    private function levelOffset(string $root, string $grade, string $key): int
    {
        $range = $this->difficulty->levelOffsetRange($grade);

        return $this->seeds->int($root, $key, $range['min'], $range['max']);
    }

    private function powerBalancedCandidates($candidates, Enemy $referenceEnemy, int $mapLevel)
    {
        if ($candidates->count() <= 4) {
            return $candidates;
        }

        $referencePower = $this->powerAtLevel($referenceEnemy, $mapLevel);
        $range = config('exploration_maps.variant_power_ratio', ['min' => 0.75, 'max' => 1.25]);
        $minPower = $referencePower * (float) ($range['min'] ?? 0.75);
        $maxPower = $referencePower * (float) ($range['max'] ?? 1.25);
        $balanced = $candidates
            ->filter(fn (Enemy $candidate) => ($power = $this->powerAtLevel($candidate, $mapLevel)) >= $minPower && $power <= $maxPower)
            ->values();

        if ($balanced->count() >= 4) {
            return $balanced;
        }

        return $candidates
            ->sortBy(fn (Enemy $candidate) => abs($this->powerAtLevel($candidate, $mapLevel) - $referencePower))
            ->take(min(7, $candidates->count()))
            ->values();
    }

    private function powerAtLevel(Enemy $enemy, int $level): int
    {
        $preview = clone $enemy;
        $this->difficulty->applyToEnemy($preview, $level);

        return app(CharacterPowerService::class)->fromEnemyStats($preview->toArray());
    }

    /** @return array{min: int, max: int} */
    private function baseMonsterLevelRange(string $grade): array
    {
        $target = config('exploration_maps.target_enemy_level_range', ['min' => 45, 'max' => 140]);
        $offset = $this->difficulty->levelOffsetRange($grade);

        return [
            'min' => max(1, (int) $target['min'] - (int) $offset['min']),
            'max' => max(1, (int) $target['max'] - (int) $offset['max']),
        ];
    }
    private function nameParts(string $root, string $type, array $effects, string $profile): array
    {
        $kind = str_contains($type, 'mine') || str_contains($type, 'forge') ? 'mine' : (str_contains($type, 'ice') || str_contains($type, 'snow') ? 'ice' : (str_contains($type, 'forest') || str_contains($type, 'tree') ? 'forest' : (str_contains($type, 'desert') || str_contains($type, 'tomb') ? 'desert' : (str_contains($type, 'abyss') || str_contains($type, 'demon') ? 'abyss' : 'magic'))));
        $sets = config('exploration_maps.map_name_parts', []);
        if (!isset($sets[$kind])) {
            $sets[$kind] = [['未知に包まれた'], ['異界'], ['探索地']];
        }
        [$prefixes, $propers, $places] = $sets[$kind];
        return ['prefix' => $prefixes[$this->seeds->int($root, 'map:v1:name:prefix', 0, count($prefixes) - 1)], 'proper' => $propers[$this->seeds->int($root, 'map:v1:name:proper', 0, count($propers) - 1)], 'place' => $places[$this->seeds->int($root, 'map:v1:name:place', 0, count($places) - 1)]];
    }
}
