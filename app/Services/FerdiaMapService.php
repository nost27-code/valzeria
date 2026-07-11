<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterCityDiscovery;
use App\Models\City;

class FerdiaMapService
{
    public function isEnabled(): bool
    {
        return app(ExtraContentControlService::class)->isActive($this->contentKey());
    }

    public function contentKey(): string
    {
        return (string) config('ferdia_world_map.content_key', 'ferdia_unlocked');
    }

    public function mapFor(Character $character): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $missingAreaIds = $this->missingMasterAreaIds();

        $this->ensureInitialAccess($character);
        $this->syncStoryAreaAccess($character);

        $nodes = collect($this->nodes())
            ->map(fn (array $node): array => $this->nodeView($character, $node))
            ->filter(fn (array $node): bool => $node['state'] !== 'hidden')
            ->values()
            ->all();

        $nodesByKey = collect($nodes)->keyBy('key');
        $routes = collect(config('ferdia_world_map.routes', []))
            ->map(function (array $route) use ($nodesByKey): ?array {
                $from = $nodesByKey->get($route['from'] ?? '');
                $to = $nodesByKey->get($route['to'] ?? '');
                if (!$from || !$to) {
                    return null;
                }

                $toState = (string) ($to['state'] ?? 'hidden');
                if ($toState === 'hidden') {
                    return null;
                }

                return [
                    'from' => $route['from'],
                    'to' => $route['to'],
                    'group' => (string) ($route['group'] ?? 'main'),
                    'from_x' => (float) $from['x_percent'],
                    'from_y' => (float) $from['y_percent'],
                    'to_x' => (float) $to['x_percent'],
                    'to_y' => (float) $to['y_percent'],
                    'state' => $toState === 'hinted' ? 'hinted' : 'unlocked',
                ];
            })
            ->filter()
            ->values()
            ->all();

        $current = collect($nodes)
            ->where(fn (array $node): bool => $this->isCurrentCandidate($character, $node))
            ->sortByDesc(fn (array $node): int => (int) ($node['sequence'] ?? 0))
            ->first();
        $next = collect($nodes)
            ->first(fn (array $node): bool => ($node['state'] ?? '') === 'hinted');

        return [
            'name' => (string) config('ferdia_world_map.name', 'フェルディア大陸'),
            'subtitle' => (string) config('ferdia_world_map.subtitle', ''),
            'map_image' => (string) config('ferdia_world_map.map_image'),
            'placeholder_image' => (string) config('ferdia_world_map.placeholder_image'),
            'image_exists' => is_file(public_path((string) config('ferdia_world_map.map_image'))),
            'current_node' => $current,
            'next_node' => $next,
            'nodes' => $nodes,
            'routes' => $routes,
            'setup_missing' => !empty($missingAreaIds),
            'setup_missing_area_ids' => $missingAreaIds,
        ];
    }

    public function ensureInitialAccess(Character $character): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $node = $this->nodeByKey('ferdia_south_coast');
        $areaId = (int) ($node['area_id'] ?? 0);
        if ($areaId <= 0) {
            return;
        }

        if (!Area::whereKey($areaId)->exists()) {
            return;
        }

        $progress = CharacterAreaProgress::firstOrCreate(
            ['character_id' => (int) $character->id, 'area_id' => $areaId],
            ['is_unlocked' => true, 'unlocked_at' => now()]
        );

        $changed = false;
        if (!$progress->is_unlocked) {
            $progress->is_unlocked = true;
            $progress->unlocked_at ??= now();
            $changed = true;
        }
        if (!in_array((string) $progress->discovery_state, ['discovered', 'cleared'], true)) {
            $progress->discovery_state = 'discovered';
            $progress->discovered_at ??= now();
            $changed = true;
        }
        if ($changed) {
            $progress->save();
        }
    }

    public function canAccessArea(Character $character, int $areaId): bool
    {
        if (!$this->isEnabled() || !$this->isFerdiaAreaId($areaId)) {
            return false;
        }

        if (!Area::whereKey($areaId)->exists()) {
            return false;
        }

        $this->ensureInitialAccess($character);
        $this->syncStoryAreaAccess($character);

        return CharacterAreaProgress::where('character_id', (int) $character->id)
            ->where('area_id', $areaId)
            ->where(function ($query) {
                $query->where('is_unlocked', true)
                    ->orWhereIn('discovery_state', ['discovered', 'cleared']);
            })
            ->exists();
    }

    public function canTravelCity(Character $character, City $city): bool
    {
        if (!$this->isFerdiaCityId((int) $city->id)) {
            return false;
        }

        if (!$this->isEnabled()) {
            return false;
        }

        return CharacterCityDiscovery::where('character_id', (int) $character->id)
            ->where('city_id', (int) $city->id)
            ->where('discovery_state', 'discovered')
            ->exists();
    }

    public function nextTravelCityFor(Character $character, ?City $currentCity): ?City
    {
        if (!$currentCity || !$this->isEnabled() || !$this->isFerdiaCityId((int) $currentCity->id)) {
            return null;
        }

        $discoveredCityIds = CharacterCityDiscovery::where('character_id', (int) $character->id)
            ->where('discovery_state', 'discovered')
            ->pluck('city_id')
            ->map(fn (mixed $cityId): int => (int) $cityId)
            ->filter(fn (int $cityId): bool => $this->isFerdiaCityId($cityId))
            ->all();

        if (empty($discoveredCityIds)) {
            return null;
        }

        return City::whereIn('id', $discoveredCityIds)
            ->where('id', '<>', (int) $currentCity->id)
            ->where('sort_order', '>', (int) $currentCity->sort_order)
            ->orderBy('sort_order')
            ->first();
    }

    public function relocateFromDisabledRegion(Character $character): bool
    {
        if ($this->isEnabled() || !$this->isFerdiaCityId((int) $character->current_city_id)) {
            return false;
        }

        $highestOrder = (int) ($character->highestCity?->sort_order ?? 10);
        $fallback = City::whereNotIn('id', $this->ferdiaCityIds())
            ->where('sort_order', '<=', min($highestOrder, 100))
            ->orderByDesc('sort_order')
            ->first()
            ?: City::where('is_initial', true)->first();

        if (!$fallback) {
            return false;
        }

        $character->current_city_id = (int) $fallback->id;
        $character->save();
        $character->refresh();

        return true;
    }

    public function isFerdiaAreaId(int $areaId): bool
    {
        return $this->nodeForArea($areaId) !== null;
    }

    public function storyRecordForArea(Character $character, Area $area): ?string
    {
        $areaNode = $this->nodeForArea((int) $area->id);
        if ((string) ($areaNode['route_group'] ?? '') !== 'story'
            || !$this->nodeCompleted($character, $areaNode)) {
            return null;
        }

        $requiredNodeKeys = (array) config('ferdia_world_map.story_final_unlock.required_node_keys', []);
        if ($this->allNodesCompleted($character, $requiredNodeKeys)) {
            return null;
        }

        $maxPoint = (int) ($areaNode['max_development_point'] ?? 100);
        $record = (array) ($areaNode['story_record'] ?? []);

        return (string) (($record['text'] ?? '') ?: (($areaNode['events'][$maxPoint] ?? '') ?: '記録を書き留めた。'));
    }

    public function isFerdiaCityId(int $cityId): bool
    {
        return in_array($cityId, $this->ferdiaCityIds(), true);
    }

    public function developmentGainForArea(Area $area): ?int
    {
        if (!$this->isFerdiaAreaId((int) $area->id)) {
            return null;
        }

        $gain = config('ferdia_world_map.development_gain', []);
        $base = max(0, (int) ($gain['base'] ?? 1));
        $bonus = max(0, (int) ($gain['bonus'] ?? 0));
        $chance = max(0, min(100, (int) ($gain['bonus_chance_percent'] ?? 0)));

        return $base + ($bonus > 0 && $chance > 0 && random_int(1, 100) <= $chance ? $bonus : 0);
    }

    public function maxDevelopmentPointForArea(Area $area): ?int
    {
        $node = $this->nodeForArea((int) $area->id);

        return $node ? (int) ($node['max_development_point'] ?? 100) : null;
    }

    public function hasBossForArea(Area $area): bool
    {
        return array_key_exists((int) $area->id, (array) config('ferdia_world_map.bosses', []));
    }

    public function canChallengeBoss(Character $character, Area $area): bool
    {
        if (!$this->hasBossForArea($area)) {
            return false;
        }

        $progress = CharacterAreaProgress::where('character_id', (int) $character->id)
            ->where('area_id', (int) $area->id)
            ->first();

        return (int) ($progress?->development_point ?? 0) >= $this->maxDevelopmentPointForArea($area);
    }

    public function crossedDevelopmentEvents(Area $area, int $before, int $after): array
    {
        $node = $this->nodeForArea((int) $area->id);
        if (!$node) {
            return [];
        }

        return collect($node['events'] ?? [])
            ->filter(fn (string $text, int|string $point): bool => $before < (int) $point && $after >= (int) $point)
            ->map(fn (string $text, int|string $point): array => [
                'point' => (int) $point,
                'text' => $text,
            ])
            ->values()
            ->all();
    }

    public function nodeForArea(int $areaId): ?array
    {
        return collect($this->nodes())->first(fn (array $node): bool => (int) ($node['area_id'] ?? 0) === $areaId);
    }

    public function nodeForCity(int $cityId): ?array
    {
        return collect($this->nodes())->first(fn (array $node): bool => (int) ($node['city_id'] ?? 0) === $cityId);
    }

    /** @param array<int, string> $nodeKeys */
    public function allNodesCompleted(Character $character, array $nodeKeys): bool
    {
        $nodeKeys = array_values(array_filter(array_map('strval', $nodeKeys)));

        return $nodeKeys !== [] && collect($nodeKeys)->every(function (string $nodeKey) use ($character): bool {
            $node = $this->nodeByKey($nodeKey);

            return $node !== null && $this->nodeCompleted($character, $node);
        });
    }

    public function nodeByKey(string $key): ?array
    {
        return collect($this->nodes())->first(fn (array $node): bool => (string) ($node['key'] ?? '') === $key);
    }

    private function nodeView(Character $character, array $node): array
    {
        $state = $this->stateForNode($character, $node);
        $area = !empty($node['area_id']) ? Area::find((int) $node['area_id']) : null;
        $city = !empty($node['city_id']) ? City::find((int) $node['city_id']) : null;
        if (!empty($node['area_id']) && !$area) {
            return $node + [
                'state' => 'hidden',
                'is_clickable' => false,
                'action_label' => '準備中',
                'description' => '',
                'development_point' => 0,
                'development_max' => (int) ($node['max_development_point'] ?? 100),
            ];
        }

        $progress = $area
            ? CharacterAreaProgress::where('character_id', (int) $character->id)->where('area_id', (int) $area->id)->first()
            : null;

        return $node + [
            'state' => $state,
            'is_clickable' => in_array($state, ['unlocked', 'completed'], true),
            'action_label' => $city ? '街へ入る' : '探索する',
            'description' => (string) (($area?->description) ?: ($city?->description) ?: ''),
            'development_point' => (int) ($progress?->development_point ?? 0),
            'development_max' => (int) ($node['max_development_point'] ?? 100),
        ];
    }

    private function stateForNode(Character $character, array $node): string
    {
        if ($this->nodeCompleted($character, $node)) {
            return 'completed';
        }

        if ($this->conditionMet($character, $node['unlock'] ?? [])) {
            return 'unlocked';
        }

        if (!empty($node['reveal']) && $this->conditionMet($character, $node['reveal'])) {
            return 'hinted';
        }

        return 'hidden';
    }

    private function isCurrentCandidate(Character $character, array $node): bool
    {
        if (!in_array((string) ($node['state'] ?? ''), ['unlocked', 'completed'], true)) {
            return false;
        }

        if (!empty($node['area_id'])) {
            return true;
        }

        if (empty($node['city_id'])) {
            return false;
        }

        return (int) $character->current_city_id === (int) $node['city_id']
            && $this->nodeCompleted($character, $node);
    }

    private function nodeCompleted(Character $character, array $node): bool
    {
        if (!empty($node['city_id'])) {
            return CharacterCityDiscovery::where('character_id', (int) $character->id)
                ->where('city_id', (int) $node['city_id'])
                ->where('discovery_state', 'discovered')
                ->exists();
        }

        if (empty($node['area_id'])) {
            return false;
        }

        $progress = CharacterAreaProgress::where('character_id', (int) $character->id)
            ->where('area_id', (int) $node['area_id'])
            ->first();

        $area = Area::find((int) $node['area_id']);
        if ($area && $this->hasBossForArea($area)) {
            return (bool) ($progress?->boss_defeated ?? false);
        }

        return (int) ($progress?->development_point ?? 0) >= (int) ($node['max_development_point'] ?? 100)
            || (string) ($progress?->discovery_state ?? '') === 'cleared';
    }

    private function conditionMet(Character $character, array $condition): bool
    {
        return match ((string) ($condition['type'] ?? '')) {
            'region_unlocked' => $this->isEnabled(),
            'node_development' => $this->nodeDevelopmentMet($character, $condition),
            'node_boss_defeated' => $this->nodeBossDefeated($character, $condition),
            'city_discovered' => $this->cityNodeDiscovered($character, (string) ($condition['node_key'] ?? '')),
            'all_nodes_completed' => $this->allNodesCompleted($character, (array) ($condition['node_keys'] ?? [])),
            default => false,
        };
    }

    private function syncStoryAreaAccess(Character $character): void
    {
        foreach ($this->nodes() as $node) {
            $condition = (array) ($node['unlock'] ?? []);
            if (!in_array((string) ($node['route_group'] ?? ''), ['story', 'story_final'], true)
                || !$this->conditionMet($character, $condition)) {
                continue;
            }

            $areaId = (int) ($node['area_id'] ?? 0);
            if ($areaId <= 0 || !Area::whereKey($areaId)->exists()) {
                continue;
            }

            $progress = CharacterAreaProgress::firstOrCreate(
                ['character_id' => (int) $character->id, 'area_id' => $areaId],
                ['is_unlocked' => true, 'unlocked_at' => now()]
            );

            $changed = false;
            if (!$progress->is_unlocked) {
                $progress->is_unlocked = true;
                $progress->unlocked_at ??= now();
                $changed = true;
            }
            if (!in_array((string) $progress->discovery_state, ['discovered', 'cleared'], true)) {
                $progress->discovery_state = 'discovered';
                $progress->discovered_at ??= now();
                $changed = true;
            }
            if ($changed) {
                $progress->save();
            }
        }
    }

    private function nodeDevelopmentMet(Character $character, array $condition): bool
    {
        $node = $this->nodeByKey((string) ($condition['node_key'] ?? ''));
        $areaId = (int) ($node['area_id'] ?? 0);
        if ($areaId <= 0) {
            return false;
        }

        $point = (int) CharacterAreaProgress::where('character_id', (int) $character->id)
            ->where('area_id', $areaId)
            ->value('development_point');

        return $point >= (int) ($condition['required_point'] ?? 0);
    }

    private function nodeBossDefeated(Character $character, array $condition): bool
    {
        $node = $this->nodeByKey((string) ($condition['node_key'] ?? ''));
        $areaId = (int) ($node['area_id'] ?? 0);

        return $areaId > 0 && CharacterAreaProgress::where('character_id', (int) $character->id)
            ->where('area_id', $areaId)
            ->where('boss_defeated', true)
            ->exists();
    }

    private function cityNodeDiscovered(Character $character, string $nodeKey): bool
    {
        $node = $this->nodeByKey($nodeKey);
        $cityId = (int) ($node['city_id'] ?? 0);
        if ($cityId <= 0) {
            return false;
        }

        return CharacterCityDiscovery::where('character_id', (int) $character->id)
            ->where('city_id', $cityId)
            ->where('discovery_state', 'discovered')
            ->exists();
    }

    private function nodes(): array
    {
        return config('ferdia_world_map.nodes', []);
    }

    private function missingMasterAreaIds(): array
    {
        $areaIds = collect($this->nodes())
            ->pluck('area_id')
            ->filter()
            ->map(fn (mixed $areaId): int => (int) $areaId)
            ->unique()
            ->values();

        if ($areaIds->isEmpty()) {
            return [];
        }

        $existingIds = Area::whereIn('id', $areaIds->all())
            ->pluck('id')
            ->map(fn (mixed $areaId): int => (int) $areaId)
            ->all();

        return $areaIds
            ->diff($existingIds)
            ->values()
            ->all();
    }

    private function ferdiaCityIds(): array
    {
        return array_map(
            fn (array $city): int => (int) ($city['id'] ?? 0),
            config('ferdia_world_map.cities', [])
        );
    }
}
