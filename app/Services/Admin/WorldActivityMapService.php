<?php

namespace App\Services\Admin;

use App\Models\City;
use App\Services\ExplorationDepthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorldActivityMapService
{
    public function __construct(
        private readonly ExplorationDepthService $depthService,
    ) {
    }

    public function activityMap(?string $selectedCityName = null): array
    {
        $configCities = collect(config('valzeria_world_map.cities', []));
        $imagePath = (string) config('valzeria_world_map.image_path', 'images/maps/valzeria_world_clean.png');
        $activeWindowMinutes = (int) config('valzeria_world_map.active_window_minutes', 30);
        $activeSince = now()->subMinutes(max(1, $activeWindowMinutes));

        $citiesByName = $this->citiesByName($configCities);
        $cityIdByName = $citiesByName->mapWithKeys(fn (City $city): array => [$city->name => (int) $city->id]);
        $cityNamesById = $citiesByName->mapWithKeys(fn (City $city): array => [(int) $city->id => $city->name]);

        $cityCounts = $this->cityCounts();
        $activeCityCounts = $this->activeCityCounts($activeSince);
        $dungeonRowsByCity = $this->dungeonRowsByCity($activeSince);
        $depthRowsByCity = $this->depthRowsByCity();

        $markers = $configCities->map(function (array $cityConfig) use ($cityIdByName, $cityCounts, $activeCityCounts, $dungeonRowsByCity): array {
            $cityName = (string) ($cityConfig['city_name'] ?? $cityConfig['label'] ?? '');
            $cityId = (int) ($cityIdByName[$cityName] ?? 0);
            $total = $cityId > 0 ? (int) ($cityCounts[$cityId] ?? 0) : 0;
            $active = $cityId > 0 ? (int) ($activeCityCounts[$cityId] ?? 0) : 0;
            $dungeonTotal = $cityId > 0
                ? (int) collect($dungeonRowsByCity[$cityId] ?? [])->sum('total')
                : 0;

            return [
                'city_id' => $cityId,
                'name' => $cityName,
                'label' => (string) ($cityConfig['label'] ?? $cityName),
                'short_label' => (string) ($cityConfig['short_label'] ?? $cityConfig['label'] ?? $cityName),
                'x_percent' => (float) ($cityConfig['x_percent'] ?? 50),
                'y_percent' => (float) ($cityConfig['y_percent'] ?? 50),
                'total' => $total,
                'active' => $active,
                'dungeon_total' => $dungeonTotal,
                'is_configured' => $cityId > 0,
            ];
        })->values()->all();

        $selectedCityName = $selectedCityName ?: (string) ($markers[0]['name'] ?? '');
        $selectedCity = collect($markers)->firstWhere('name', $selectedCityName) ?: ($markers[0] ?? null);
        $selectedCityId = (int) ($selectedCity['city_id'] ?? 0);

        return [
            'imagePath' => $imagePath,
            'imageExists' => file_exists(public_path($imagePath)),
            'activeWindowMinutes' => max(1, $activeWindowMinutes),
            'generatedAt' => now(),
            'markers' => $markers,
            'selectedCity' => $selectedCity,
            'selectedDetail' => $this->detailForCity(
                $selectedCity,
                $selectedCityId,
                $cityNamesById,
                $dungeonRowsByCity,
                $depthRowsByCity,
                $cityCounts,
                $activeCityCounts,
            ),
            'sourceStatus' => $this->sourceStatus(),
        ];
    }

    private function citiesByName(Collection $configCities): Collection
    {
        if (!Schema::hasTable('cities')) {
            return collect();
        }

        $names = $configCities
            ->pluck('city_name')
            ->filter()
            ->values()
            ->all();

        if ($names === []) {
            return collect();
        }

        return City::query()
            ->whereIn('name', $names)
            ->get(['id', 'name', 'sort_order'])
            ->keyBy('name');
    }

    private function cityCounts(): Collection
    {
        if (!$this->hasCharacterCitySource()) {
            return collect();
        }

        return $this->characterQuery()
            ->whereNotNull('characters.current_city_id')
            ->selectRaw('characters.current_city_id as city_id, COUNT(*) as total')
            ->groupBy('characters.current_city_id')
            ->pluck('total', 'city_id')
            ->map(fn ($count): int => (int) $count);
    }

    private function activeCityCounts($activeSince): Collection
    {
        if (!$this->hasCharacterCitySource() || !Schema::hasColumn('characters', 'last_seen_at')) {
            return collect();
        }

        return $this->characterQuery()
            ->whereNotNull('characters.current_city_id')
            ->where('characters.last_seen_at', '>=', $activeSince)
            ->selectRaw('characters.current_city_id as city_id, COUNT(*) as total')
            ->groupBy('characters.current_city_id')
            ->pluck('total', 'city_id')
            ->map(fn ($count): int => (int) $count);
    }

    private function dungeonRowsByCity($activeSince): Collection
    {
        if (!$this->hasExplorationSource()) {
            return collect();
        }

        $activeCountExpression = Schema::hasColumn('characters', 'last_seen_at')
            ? 'SUM(CASE WHEN characters.last_seen_at >= ? THEN 1 ELSE 0 END) as active_total'
            : '0 as active_total';
        $activeBindings = Schema::hasColumn('characters', 'last_seen_at') ? [$activeSince] : [];

        $query = DB::table('character_exploration_states')
            ->join('areas', 'character_exploration_states.area_id', '=', 'areas.id')
            ->leftJoin('characters', 'character_exploration_states.character_id', '=', 'characters.id')
            ->whereNotNull('character_exploration_states.area_id')
            ->whereNotNull('character_exploration_states.started_at')
            ->whereNotNull('areas.city_id')
            ->selectRaw('areas.city_id as city_id')
            ->selectRaw('areas.id as area_id')
            ->selectRaw('areas.name as area_name')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw($activeCountExpression, $activeBindings)
            ->groupBy('areas.city_id', 'areas.id', 'areas.name')
            ->orderBy('areas.city_id')
            ->orderBy('areas.sort_order')
            ->orderBy('areas.id');

        if ($this->canJoinNonAdminUsers()) {
            $query->join('users', 'characters.user_id', '=', 'users.id')
                ->where('users.role', '!=', 'admin');
        }

        return $query->get()
            ->groupBy(fn ($row): int => (int) $row->city_id)
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'area_id' => (int) $row->area_id,
                'name' => (string) $row->area_name,
                'total' => (int) $row->total,
                'active' => (int) $row->active_total,
            ])->values()->all());
    }

    private function depthRowsByCity(): Collection
    {
        if (!$this->hasExplorationSource()) {
            return collect();
        }

        $pointExpression = Schema::hasColumn('character_exploration_states', 'exploration_point')
            ? 'character_exploration_states.exploration_point'
            : '0';
        $dangerExpression = Schema::hasColumn('character_exploration_states', 'danger_rate')
            ? 'character_exploration_states.danger_rate'
            : '0';

        $query = DB::table('character_exploration_states')
            ->join('areas', 'character_exploration_states.area_id', '=', 'areas.id')
            ->leftJoin('characters', 'character_exploration_states.character_id', '=', 'characters.id')
            ->whereNotNull('character_exploration_states.area_id')
            ->whereNotNull('character_exploration_states.started_at')
            ->whereNotNull('areas.city_id')
            ->select('areas.city_id')
            ->selectRaw("{$pointExpression} as exploration_point")
            ->selectRaw("{$dangerExpression} as danger_rate")
            ->get();

        if ($this->canJoinNonAdminUsers()) {
            $query = DB::table('character_exploration_states')
                ->join('areas', 'character_exploration_states.area_id', '=', 'areas.id')
                ->join('characters', 'character_exploration_states.character_id', '=', 'characters.id')
                ->join('users', 'characters.user_id', '=', 'users.id')
                ->where('users.role', '!=', 'admin')
                ->whereNotNull('character_exploration_states.area_id')
                ->whereNotNull('character_exploration_states.started_at')
                ->whereNotNull('areas.city_id')
                ->select('areas.city_id')
                ->selectRaw("{$pointExpression} as exploration_point")
                ->selectRaw("{$dangerExpression} as danger_rate")
                ->get();
        }

        return $query
            ->groupBy(fn ($row): int => (int) $row->city_id)
            ->map(function (Collection $rows): array {
                return $rows
                    ->map(function ($row): string {
                        $tier = $this->depthService->tierFor(
                            (int) ($row->exploration_point ?? 0),
                            (int) ($row->danger_rate ?? 0),
                        );

                        return (string) ($tier['label'] ?? '表層');
                    })
                    ->countBy()
                    ->map(fn (int $count, string $label): array => [
                        'label' => $label,
                        'total' => $count,
                    ])
                    ->values()
                    ->all();
            });
    }

    private function detailForCity(
        ?array $selectedCity,
        int $selectedCityId,
        Collection $cityNamesById,
        Collection $dungeonRowsByCity,
        Collection $depthRowsByCity,
        Collection $cityCounts,
        Collection $activeCityCounts,
    ): array {
        if (!$selectedCity) {
            return [
                'name' => '取得不可',
                'total' => 0,
                'active' => 0,
                'dungeons' => [],
                'depths' => [],
                'notes' => ['街座標の設定がありません。'],
            ];
        }

        $notes = [];
        if ($selectedCityId <= 0) {
            $notes[] = 'cities.name と座標定義の対応が見つかりません。';
        }
        if (!$this->hasCharacterCitySource()) {
            $notes[] = '現所在地データ未特定: characters.current_city_id を確認できません。';
        }
        if (!Schema::hasColumn('characters', 'last_seen_at')) {
            $notes[] = '直近アクティブ判定に使う characters.last_seen_at を確認できません。';
        }
        if (!$this->hasExplorationSource()) {
            $notes[] = 'ダンジョン別人数の取得元を確認できません。';
        }

        return [
            'name' => (string) ($cityNamesById[$selectedCityId] ?? $selectedCity['label'] ?? $selectedCity['name']),
            'total' => $selectedCityId > 0 ? (int) ($cityCounts[$selectedCityId] ?? 0) : 0,
            'active' => $selectedCityId > 0 ? (int) ($activeCityCounts[$selectedCityId] ?? 0) : 0,
            'dungeons' => $selectedCityId > 0 ? ($dungeonRowsByCity[$selectedCityId] ?? []) : [],
            'depths' => $selectedCityId > 0 ? ($depthRowsByCity[$selectedCityId] ?? []) : [],
            'notes' => $notes,
        ];
    }

    private function sourceStatus(): array
    {
        return [
            'city' => $this->hasCharacterCitySource()
                ? 'characters.current_city_id'
                : '現所在地データ未特定',
            'active' => Schema::hasColumn('characters', 'last_seen_at')
                ? 'characters.last_seen_at'
                : '取得不可',
            'dungeon' => $this->hasExplorationSource()
                ? 'character_exploration_states.area_id + areas.city_id'
                : '取得不可',
            'depth' => $this->hasExplorationSource()
                ? 'character_exploration_states.exploration_point / danger_rate からの仮判定'
                : '取得不可',
        ];
    }

    private function characterQuery(): Builder
    {
        $query = \App\Models\Character::query();

        if ($this->canJoinNonAdminUsers()) {
            $query->join('users', 'characters.user_id', '=', 'users.id')
                ->where('users.role', '!=', 'admin');
        }

        return $query;
    }

    private function hasCharacterCitySource(): bool
    {
        return Schema::hasTable('characters')
            && Schema::hasColumn('characters', 'current_city_id');
    }

    private function hasExplorationSource(): bool
    {
        return Schema::hasTable('character_exploration_states')
            && Schema::hasTable('areas')
            && Schema::hasColumn('character_exploration_states', 'area_id')
            && Schema::hasColumn('character_exploration_states', 'started_at')
            && Schema::hasColumn('areas', 'city_id');
    }

    private function canJoinNonAdminUsers(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasColumn('users', 'role')
            && Schema::hasColumn('characters', 'user_id');
    }
}
