<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterExplorationState;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\SubArea;
use App\Models\SubAreaRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubAreaDiscoveryService
{
    public function rollDiscovery(Character $character, Area $area, CharacterExplorationState $state): ?SubAreaRoute
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $point = (int) ($state->exploration_point ?? 0);
        $danger = (int) ($state->danger_rate ?? 0);
        $level = (int) ($character->level ?? 1);

        $discoveredRouteIds = CharacterSubAreaRouteDiscovery::where('character_id', $character->id)
            ->pluck('sub_area_route_id')
            ->all();

        $routes = SubAreaRoute::with(['subArea', 'sourceArea'])
            ->where('source_area_id', $area->id)
            ->where('is_enabled', true)
            ->whereNotIn('id', $discoveredRouteIds)
            ->where('min_exploration_point', '<=', $point)
            ->where('min_danger_rate', '<=', $danger)
            ->where('min_character_level', '<=', $level)
            ->get()
            ->filter(fn (SubAreaRoute $route): bool => $route->subArea && $route->subArea->is_enabled)
            ->filter(fn (SubAreaRoute $route): bool => $this->bossRequirementMet($character, $route))
            ->values();

        if ($routes->isEmpty()) {
            return null;
        }

        foreach ($routes->shuffle() as $route) {
            if ($this->rollPercent((float) $route->discovery_chance)) {
                return $route;
            }
        }

        return null;
    }

    public function recordDiscovery(Character $character, SubAreaRoute $route, CharacterExplorationState $state): array
    {
        $route->loadMissing(['subArea', 'sourceArea']);
        $subArea = $route->subArea;
        $sourceArea = $route->sourceArea;

        return DB::transaction(function () use ($character, $route, $state, $subArea, $sourceArea) {
            $existing = CharacterSubAreaRouteDiscovery::where('character_id', $character->id)
                ->where('sub_area_route_id', $route->id)
                ->first();

            if ($existing) {
                return [
                    'discovery' => $existing,
                    'discovery_id' => $existing->id,
                    'sub_area' => $subArea,
                    'route' => $route,
                    'is_new_for_character' => false,
                    'is_world_first' => false,
                    'is_route_first' => false,
                    'message' => "地図にはすでに「{$subArea->name}」への入口が記録されています。",
                ];
            }

            $isWorldFirst = ! $subArea->world_first_character_id;
            $isRouteFirst = ! CharacterSubAreaRouteDiscovery::where('sub_area_route_id', $route->id)->exists();

            $routeDiscovery = CharacterSubAreaRouteDiscovery::create([
                'character_id' => $character->id,
                'sub_area_route_id' => $route->id,
                'discovered_at' => now(),
                'discovery_exploration_point' => (int) ($state->exploration_point ?? 0),
                'discovery_danger_rate' => (int) ($state->danger_rate ?? 0),
            ]);

            $subArea->increment('total_discoveries');
            if ($isWorldFirst) {
                $subArea->forceFill([
                    'world_first_character_id' => $character->id,
                    'world_first_discovered_at' => now(),
                ])->save();
            }

            $this->writePublicLog($character, $subArea, $sourceArea, $isWorldFirst, $isRouteFirst);

            $message = $isWorldFirst
                ? "【新領域発見】{$sourceArea->name}の奥で「{$subArea->name}」を発見した！"
                : ($isRouteFirst
                    ? "【別入口発見】{$sourceArea->name}からも「{$subArea->name}」へ続く道を見つけた！"
                    : "「{$subArea->name}」への入口を地図に記録した。");

            return [
                'discovery' => $routeDiscovery,
                'discovery_id' => $routeDiscovery->id,
                'sub_area' => $subArea->fresh(),
                'route' => $route,
                'is_new_for_character' => true,
                'is_world_first' => $isWorldFirst,
                'is_route_first' => $isRouteFirst,
                'message' => $message,
            ];
        });
    }

    public function discoveredRoutes(Character $character, ?int $cityId = null)
    {
        if (! $this->tablesReady()) {
            return collect();
        }

        $query = CharacterSubAreaRouteDiscovery::with(['route.subArea', 'route.sourceArea'])
            ->where('character_id', $character->id)
            ->orderByDesc('discovered_at');

        if ($cityId) {
            $query->whereHas('route.sourceArea', function ($areaQuery) use ($cityId): void {
                $areaQuery->where('city_id', $cityId);
            });
        }

        return $query->get();
    }

    private function bossRequirementMet(Character $character, SubAreaRoute $route): bool
    {
        if (! $route->required_boss_cleared_area_id) {
            return true;
        }

        return CharacterAreaProgress::where('character_id', $character->id)
            ->where('area_id', $route->required_boss_cleared_area_id)
            ->where('boss_defeated', true)
            ->exists();
    }

    private function writePublicLog(Character $character, SubArea $subArea, ?Area $sourceArea, bool $isWorldFirst, bool $isRouteFirst): void
    {
        if ($isWorldFirst) {
            app(PublicLogService::class)->addLog(
                'sub_area',
                "【新領域発見】冒険者{$character->name}が、{$sourceArea?->name}の奥で「{$subArea->name}」を発見した！",
                $character,
                3
            );

            return;
        }

        if ($isRouteFirst) {
            app(PublicLogService::class)->addLog(
                'sub_area',
                "【別入口発見】冒険者{$character->name}が、{$sourceArea?->name}からも「{$subArea->name}」へ続く道を見つけた！",
                $character,
                2
            );
        }
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('sub_areas')
            && Schema::hasTable('sub_area_routes')
            && Schema::hasTable('character_sub_area_route_discoveries');
    }

    private function rollPercent(float $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }

        return random_int(1, 10000) <= (int) round(min(100, $rate) * 100);
    }
}
