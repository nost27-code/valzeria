<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MonsterPrefix;
use Illuminate\Support\Str;

class ExplorationMapGenerator
{
    public function __construct(private readonly ExplorationMapSeedService $seeds, private readonly ExplorationMapDifficultyService $difficulty) {}

    public function generate(Character $owner, Area $area, Enemy $sourceMonster, string $dropEventUuid): ExplorationMap
    {
        $uuid = (string) Str::uuid();
        $root = $this->seeds->createRootSeed($uuid, $dropEventUuid, $owner->id, $area->id, $sourceMonster->id);
        $grade = $this->grade($root);
        $targetArea = $this->targetArea($root, $area);
        $targetMonster = $this->targetMonster($root, $grade);
        $type = $this->dungeonType($root, $targetArea);
        $level = min(255, max(1, (int) $targetMonster->level + $this->levelOffset($root, $grade, 'map:v2:map_level_offset')));
        $limit = $this->limit($root, $grade);
        $profile = $this->seeds->weightedPick($root, 'map:v1:reward_profile', collect($this->profiles())->map(fn ($value, $key) => ['value' => $key, 'weight' => 100])->values()->all())['value'];
        $effects = $this->effects($root, $type, $grade);
        $normal = $this->variants($root, $targetArea, $targetMonster, $type, $level, $grade, false);
        $boss = $this->variants($root, $targetArea, $targetMonster, $type, $level, $grade, true);
        $parts = $this->nameParts($root, $type, $effects, $profile);
        $name = implode('', [$parts['prefix'], $parts['proper'], $parts['place'], 'の地図']);
        $recommendedTownId = (int) ($targetArea->city_id ?? 0) ?: null;

        return ExplorationMap::create([
            'uuid' => $uuid, 'owner_character_id' => $owner->id, 'source_area_id' => $targetArea->id, 'source_monster_id' => $targetMonster->id, 'source_drop_event_uuid' => $dropEventUuid,
            'seed_version' => 1, 'seed_encrypted' => $this->seeds->encrypt($root), 'seed_hash' => $this->seeds->hash($root), 'generation_version' => 3,
            'map_grade' => $grade, 'map_level' => $level, 'dungeon_type' => $type, 'reward_profile' => $profile, 'exploration_limit' => $limit,
            'name' => $name, 'name_parts_json' => $parts, 'normal_monster_variants_json' => $normal, 'boss_monster_variants_json' => $boss,
            'environment_effects_json' => $effects, 'reward_modifiers_json' => $this->profiles()[$profile],
            'generation_payload_json' => ['grade' => $grade, 'dungeon_type' => $type, 'map_level' => $level, 'exploration_limit' => $limit, 'origin_area_id' => $area->id, 'origin_monster_id' => $sourceMonster->id, 'target_area_id' => $targetArea->id],
            'recommended_town_id' => $recommendedTownId, 'status' => 'uninvestigated',
        ]);
    }

    private function grade(string $root): string
    {
        return match (true) { ($roll = $this->seeds->int($root, 'map:v1:grade', 1, 10000)) <= 6000 => 'normal', $roll <= 8500 => 'rare', $roll <= 9700 => 'hero', default => 'legend' };
    }
    private function limit(string $root, string $grade): int
    {
        $range = config("exploration_maps.grade_limits.{$grade}");
        return (int) $range['min'] + $this->seeds->int($root, 'map:v1:exploration_limit', 0, intdiv((int) $range['max'] - (int) $range['min'], 10)) * 10;
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
    private function targetMonster(string $root, string $grade): Enemy
    {
        $range = $this->baseMonsterLevelRange($grade);
        $monsters = Enemy::query()
            ->where('is_boss', false)
            ->whereBetween('level', [$range['min'], $range['max']])
            ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]))
            ->orderBy('id')
            ->get();

        if ($monsters->isEmpty()) {
            throw new \RuntimeException('地図用の通常モンスターが見つかりません。');
        }

        return $monsters[$this->seeds->int($root, 'map:v3:target_monster', 0, $monsters->count() - 1)];
    }
    private function sourceBonus(Enemy $enemy): int { return $enemy->is_boss ? 5 : ((bool) ($enemy->is_elite ?? false) ? 3 : 0); }
    private function gradeBonus(string $grade): int { return ['normal' => 0, 'rare' => 5, 'hero' => 10, 'legend' => 15][$grade]; }
    private function profiles(): array
    {
        return collect(config('exploration_maps.reward_profiles', []))
            ->map(fn (array $profile) => $profile['modifiers'] ?? [])
            ->all();
    }
    private function effects(string $root, string $type, string $grade): array
    {
        $count = $this->seeds->int($root, 'map:v1:environment_count', 0, $grade === 'legend' ? 3 : 2);
        $pool = str_contains($type, 'ice') || str_contains($type, 'snow') ? ['極寒', '氷晶を纏う'] : (str_contains($type, 'mine') || str_contains($type, 'forge') ? ['灼熱', '豊かな鉱脈'] : ['濃霧', '精霊の祝福', '宝物庫']);
        $effects = [];
        for ($i = 0; $i < $count; $i++) $effects[] = $pool[$this->seeds->int($root, "map:v1:environment:{$i}", 0, count($pool) - 1)];
        return array_values(array_unique($effects));
    }
    private function variants(string $root, Area $area, Enemy $referenceEnemy, string $type, int $level, string $grade, bool $boss): array
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
        $candidates = $query->get();
        if ($candidates->isEmpty() && $boss) return [];
        if ($candidates->isEmpty()) {
            $candidates = Enemy::query()
                ->where('is_boss', false)
                ->whereBetween('level', [$baseLevelRange['min'], $baseLevelRange['max']])
                ->whereHas('area', fn ($query) => $query->whereBetween('city_id', [1, 10]))
                ->get();
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
