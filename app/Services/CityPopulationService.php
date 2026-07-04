<?php

namespace App\Services;

use App\Models\Character;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CityPopulationService
{
    public function countsByCity(): Collection
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'current_city_id')) {
            return collect();
        }

        return Character::query()
            ->whereNotNull('current_city_id')
            ->selectRaw('current_city_id, COUNT(*) as total')
            ->groupBy('current_city_id')
            ->pluck('total', 'current_city_id')
            ->map(fn ($total): int => (int) $total);
    }

    public function iconSamplesByCity(int $limitPerCity = 6): Collection
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'current_city_id')) {
            return collect();
        }

        $limitPerCity = max(1, min(12, $limitPerCity));

        $query = Character::query()
            ->select([
                'characters.current_city_id',
                'characters.icon_path',
                'characters.name',
                'characters.profile_comment',
            ])
            ->whereNotNull('characters.current_city_id')
            ->orderByDesc(Schema::hasColumn('characters', 'last_seen_at') ? 'characters.last_seen_at' : 'characters.updated_at');

        if (Schema::hasTable('cities')) {
            $query
                ->leftJoin('cities as current_city', 'current_city.id', '=', 'characters.current_city_id')
                ->addSelect('current_city.name as current_city_name');
        }

        if (Schema::hasTable('character_exploration_states') && Schema::hasColumn('character_exploration_states', 'area_id')) {
            $query
                ->leftJoin('character_exploration_states as ces', 'ces.character_id', '=', 'characters.id')
                ->addSelect('ces.area_id as active_area_id')
                ->addSelect('ces.started_at as active_started_at');

            if (Schema::hasTable('areas')) {
                $query
                    ->leftJoin('areas as active_area', 'active_area.id', '=', 'ces.area_id')
                    ->addSelect('active_area.name as active_area_name');
            }
        }

        $cityCoordinates = $this->cityCoordinatesById();
        $dungeonCoordinates = collect(config('valzeria_world_map.dungeons', []));

        return $query
            ->get()
            ->groupBy('current_city_id')
            ->map(function (Collection $characters) use ($limitPerCity, $cityCoordinates, $dungeonCoordinates): array {
                return $characters
                    ->take($limitPerCity)
                    ->map(function (Character $character) use ($cityCoordinates, $dungeonCoordinates): array {
                        $cityId = (int) $character->current_city_id;
                        $areaId = (int) ($character->active_area_id ?? 0);
                        $isExploring = $areaId > 0 && !empty($character->active_started_at);
                        $coordinates = $isExploring
                            ? ($dungeonCoordinates[$areaId] ?? null)
                            : null;
                        $coordinates = $coordinates ?: ($cityCoordinates[$cityId] ?? ['x_percent' => 50, 'y_percent' => 50]);

                        return [
                            'icon' => CharacterIconCatalog::normalize($character->icon_path),
                            'name' => (string) $character->name,
                            'comment' => trim((string) ($character->profile_comment ?: 'よろしくお願いします')),
                            'x' => (float) ($coordinates['x_percent'] ?? 50),
                            'y' => (float) ($coordinates['y_percent'] ?? 50),
                            'area_id' => $isExploring ? $areaId : null,
                            'location_name' => $isExploring
                                ? (string) ($character->active_area_name ?: '探索中')
                                : (string) ($character->current_city_name ?: '街'),
                        ];
                    })
                    ->values()
                    ->all();
            });
    }

    private function cityCoordinatesById(): Collection
    {
        if (!Schema::hasTable('cities')) {
            return collect();
        }

        $configByName = collect(config('valzeria_world_map.cities', []))->keyBy('city_name');

        return \App\Models\City::query()
            ->get(['id', 'name'])
            ->mapWithKeys(function ($city) use ($configByName): array {
                $config = $configByName->get($city->name);

                return [
                    (int) $city->id => [
                        'x_percent' => (float) ($config['x_percent'] ?? 50),
                        'y_percent' => (float) ($config['y_percent'] ?? 50),
                    ],
                ];
            });
    }
}
