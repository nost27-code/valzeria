<?php

namespace App\Services\Enemy;

use App\Models\Area;

class EnemyLevelResolver
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = config('enemy_stat_generation');
    }

    /**
     * @return array{
     *     city_index:int,
     *     dungeon_index_in_city:int,
     *     layer_key:string,
     *     layer_offset:int,
     *     surface_start_level:int,
     *     surface_end_level:int,
     *     area_start_level:int,
     *     area_end_level:int,
     *     recommended_level:int
     * }
     */
    public function resolveAreaLevels(Area $area): array
    {
        if ($this->shouldUseLockedRecommendedLevel($area)) {
            $min = max(1, (int) ($area->recommended_level_min ?? 1));
            $max = max($min, (int) ($area->recommended_level_max ?? $min));

            return [
                'city_index' => $this->resolveCityIndex($area),
                'dungeon_index_in_city' => $this->resolveDungeonIndexInCity($area),
                'layer_key' => $this->resolveLayerKey($area),
                'layer_offset' => 0,
                'surface_start_level' => $min,
                'surface_end_level' => $max,
                'area_start_level' => $min,
                'area_end_level' => $max,
                'recommended_level' => $min,
            ];
        }

        $cityIndex = $this->resolveCityIndex($area);
        $dungeonIndex = $this->resolveDungeonIndexInCity($area);

        $cityWidth = (int) $this->config['city_surface_level_width'];
        $cityStart = 1 + $cityWidth * ($cityIndex - 1);
        $cityEnd = $cityStart + $cityWidth;

        $surfaceStart = $cityStart + 2 * ($dungeonIndex - 1);
        $surfaceEnd = min($cityStart + 2 * $dungeonIndex, $cityEnd);

        $layerKey = $this->resolveLayerKey($area);
        $layerOffset = (int) ($this->config['layer_offsets'][$layerKey] ?? 0);

        return [
            'city_index' => $cityIndex,
            'dungeon_index_in_city' => $dungeonIndex,
            'layer_key' => $layerKey,
            'layer_offset' => $layerOffset,
            'surface_start_level' => $surfaceStart,
            'surface_end_level' => $surfaceEnd,
            'area_start_level' => $surfaceStart + $layerOffset,
            'area_end_level' => $surfaceEnd + $layerOffset,
            'recommended_level' => $surfaceStart + $layerOffset,
        ];
    }

    public function resolveEnemyLevel(Area $area, string $roleKey): int
    {
        $levels = $this->resolveAreaLevels($area);
        $start = $levels['area_start_level'];
        $end = $levels['area_end_level'];

        return match ($roleKey) {
            'normal_weak' => $start,
            'normal', 'strong' => $start + 1,
            'rare', 'golden', 'deep_candidate' => $end,
            'dungeon_lord' => $end + 3,
            'boss' => $end,
            'city_boss' => $end + 2,
            'otherworld_boss' => $end + 5,
            default => $start + 1,
        };
    }

    private function resolveCityIndex(Area $area): int
    {
        $city = $area->relationLoaded('city') ? $area->city : $area->city()->first();
        if ($city && (int) $city->sort_order > 0) {
            return max(1, (int) floor((int) $city->sort_order / 10));
        }

        if ((int) ($area->city_id ?? 0) > 0) {
            return (int) $area->city_id;
        }

        return max(1, (int) ceil((int) $area->id / (int) $this->config['dungeons_per_city']));
    }

    private function resolveDungeonIndexInCity(Area $area): int
    {
        $sortOrder = (int) ($area->sort_order ?? 0);
        if ($sortOrder > 0) {
            $local = (int) floor(($sortOrder % 100) / 10);
            if ($local >= 1) {
                return max(1, min((int) $this->config['dungeons_per_city'], $local));
            }
        }

        $index = (((int) $area->id - 1) % (int) $this->config['dungeons_per_city']) + 1;

        return max(1, min((int) $this->config['dungeons_per_city'], $index));
    }

    private function resolveLayerKey(Area $area): string
    {
        $default = (string) ($this->config['default_keys']['layer_key'] ?? 'surface');
        $layer = trim((string) ($area->layer_key ?? ''));

        return array_key_exists($layer, (array) ($this->config['layer_offsets'] ?? [])) ? $layer : $default;
    }

    private function shouldUseLockedRecommendedLevel(Area $area): bool
    {
        if ((bool) ($area->is_recommended_level_locked ?? false)) {
            return true;
        }

        return (int) ($area->recommended_level_min ?? 0) >= 180;
    }
}
