<?php

namespace App\Services;

use App\Models\Area;

class DungeonCardVisualService
{
    private const FERDIA_STORY_VISUAL_ORDERS = [
        1025 => 14,
        1027 => 15,
        1026 => 16,
        1028 => 17,
        1029 => 18,
    ];

    public function cardBackgroundPath(Area $area, int $fallbackOrder): ?string
    {
        $cityId = sprintf('%02d', (int) $area->city_id);
        $imageOrder = (bool) $area->is_route_area ? 10 : $fallbackOrder;
        $relativePath = $this->visualPath($area, 'card_bg')
            ?? sprintf('card_bg/dungeon_%s_%02d.webp', $cityId, $imageOrder);

        if ($this->imageExists($relativePath)) {
            return $relativePath;
        }

        $fallbackPath = ltrim((string) ($area->background_image ?? ''), '/');

        return $this->imageExists($fallbackPath) ? $fallbackPath : null;
    }

    public function visualPath(Area $area, string $directory): ?string
    {
        $areaId = (int) $area->id;
        if ($areaId <= 0) {
            return null;
        }

        $visualOrder = self::FERDIA_STORY_VISUAL_ORDERS[$areaId] ?? null;
        if ($visualOrder === null) {
            $mainAreaIds = collect(config('ferdia_world_map.nodes', []))
                ->filter(fn (array $node): bool => ($node['route_group'] ?? null) === 'main' && ! empty($node['area_id']))
                ->sortBy('sequence')
                ->pluck('area_id')
                ->values();
            $index = $mainAreaIds->search($areaId);
            if ($index === false) {
                return null;
            }

            $visualOrder = $index + 1;
        }

        $relativePath = sprintf('%s/dungeon_11_%02d.webp', $directory, $visualOrder);

        return $this->imageExists($relativePath) ? $relativePath : null;
    }

    private function imageExists(string $relativePath): bool
    {
        return $relativePath !== '' && file_exists(public_path('images/' . $relativePath));
    }
}
