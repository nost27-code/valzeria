<?php

namespace App\Services;

use App\Models\Area;
use App\Models\AreaDiscoveryLink;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterCityDiscovery;
use App\Models\City;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DiscoveryService
{
    private const DEVELOPMENT_GAIN_PER_VICTORY = 10;
    private const DEFAULT_MAX_DEVELOPMENT_POINT = 100;

    public function ensureInitialDiscoveries(Character $character): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $highestCity = $character->highestCity ?: $character->currentCity;
        if ($highestCity) {
            City::where('sort_order', '<=', (int) $highestCity->sort_order)
                ->orderBy('sort_order')
                ->get()
                ->each(fn (City $city) => $this->discoverCity($character, $city));
        }

        CharacterAreaProgress::where('character_id', $character->id)
            ->where('is_unlocked', true)
            ->get()
            ->each(function (CharacterAreaProgress $progress): void {
                if (!in_array($progress->discovery_state, ['discovered', 'cleared'], true)) {
                    $progress->discovery_state = $progress->boss_defeated ? 'cleared' : 'discovered';
                    $progress->discovered_at ??= $progress->unlocked_at ?: now();
                }
                if ($progress->boss_defeated) {
                    $progress->cleared_at ??= $progress->boss_defeated_at ?: now();
                }
                $progress->save();
            });

        if ($character->currentCity) {
            $this->discoverCityEntranceAreas($character, $character->currentCity);
        }

        AreaDiscoveryLink::where('condition_type', 'initial')
            ->orderBy('sort_order')
            ->get()
            ->each(fn (AreaDiscoveryLink $link) => $this->applyDiscoveryLink($character, $link));
    }

    public function canAccessArea(Character $character, int $areaId): bool
    {
        if (!$this->isAvailable()) {
            return true;
        }

        $this->ensureInitialDiscoveries($character);

        $progress = CharacterAreaProgress::where('character_id', $character->id)
            ->where('area_id', $areaId)
            ->first();

        return (bool) $progress && (
            $progress->is_unlocked
            || in_array($progress->discovery_state, ['discovered', 'cleared'], true)
        );
    }

    public function visibleRumors(Character $character, ?int $cityId = null): Collection
    {
        if (!$this->isAvailable()) {
            return collect();
        }

        $this->ensureInitialDiscoveries($character);
        $discoveredAreaIds = CharacterAreaProgress::where('character_id', $character->id)
            ->whereIn('discovery_state', ['discovered', 'cleared'])
            ->pluck('area_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $discoveredCityIds = CharacterCityDiscovery::where('character_id', $character->id)
            ->where('discovery_state', 'discovered')
            ->pluck('city_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($discoveredAreaIds) && empty($discoveredCityIds)) {
            return collect();
        }

        $links = AreaDiscoveryLink::query()
            ->whereNotNull('rumor_text')
            ->where('rumor_text', '<>', '')
            ->where('rumor_text', '<>', 'なし')
            ->where(function ($query) use ($discoveredAreaIds, $discoveredCityIds) {
                if (!empty($discoveredAreaIds)) {
                    $query->orWhere(function ($sub) use ($discoveredAreaIds) {
                        $sub->whereIn('from_type', ['area', 'route_area'])
                            ->whereIn('from_id', $discoveredAreaIds);
                    });
                }

                if (!empty($discoveredCityIds)) {
                    $query->orWhere(function ($sub) use ($discoveredCityIds) {
                        $sub->where('from_type', 'city')
                            ->whereIn('from_id', $discoveredCityIds);
                    });
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (AreaDiscoveryLink $link) => $this->isLinkTargetPublished($link))
            ->filter(fn (AreaDiscoveryLink $link) => !$this->isTargetDiscovered($character, $link));

        if ($cityId) {
            $links = $links->filter(function (AreaDiscoveryLink $link) use ($cityId) {
                if (in_array($link->to_type, ['area', 'route_area'], true)) {
                    $area = Area::find((int) $link->to_id);

                    return !$area || (int) $area->city_id === (int) $cityId;
                }

                return true;
            });
        }

        return $links->values();
    }

    public function valmonHintForArea(Character $character, Area $area): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $this->ensureInitialDiscoveries($character);

        $link = AreaDiscoveryLink::query()
            ->where('from_type', $area->is_route_area ? 'route_area' : 'area')
            ->where('from_id', (int) $area->id)
            ->whereNotNull('rumor_text')
            ->where('rumor_text', '<>', '')
            ->where('rumor_text', '<>', 'なし')
            ->orderBy('sort_order')
            ->get()
            ->first(function (AreaDiscoveryLink $link) use ($character) {
                return $this->isLinkTargetPublished($link)
                    && !$this->isTargetDiscovered($character, $link);
            });

        return $link ? ['text' => (string) $link->rumor_text] : null;
    }

    public function checkAfterExplore(Character $character, Area $area, bool $applyDiscoveries = true): array
    {
        if (!$this->isAvailable()) {
            return ['development' => null, 'discoveries' => []];
        }

        $progress = $this->progressFor($character, $area);
        $before = (int) $progress->development_point;
        $maxDevelopmentPoint = $this->maxDevelopmentPointFor($area);
        $gain = $this->developmentGainFor($area);
        $after = min($maxDevelopmentPoint, $before + $gain);

        if ($after !== $before) {
            $progress->development_point = $after;
            $progress->save();
        }

        $discoveries = $applyDiscoveries
            ? $this->applyDiscoveriesFrom($character, $area->is_route_area ? 'route_area' : 'area', (int) $area->id, $progress)
            : [];

        return [
            'development' => [
                'area_id' => (int) $area->id,
                'area_name' => $area->name,
                'before' => $before,
                'after' => $after,
                'gained' => max(0, $after - $before),
                'max' => $maxDevelopmentPoint,
                'events' => app(FerdiaMapService::class)->crossedDevelopmentEvents($area, $before, $after),
            ],
            'discoveries' => $discoveries,
        ];
    }

    public function checkAfterBoss(Character $character, Area $area): array
    {
        if (!$this->isAvailable()) {
            return ['discoveries' => []];
        }

        $progress = $this->progressFor($character, $area);
        $progress->boss_defeated = true;
        $progress->boss_defeated_at ??= now();
        $progress->discovery_state = 'cleared';
        $progress->cleared_at ??= $progress->boss_defeated_at;
        $progress->save();

        return [
            'discoveries' => $this->applyDiscoveriesFrom($character, $area->is_route_area ? 'route_area' : 'area', (int) $area->id, $progress),
        ];
    }

    public function rebuildCharacter(Character $character): array
    {
        if (!$this->isAvailable()) {
            return ['areas' => 0, 'cities' => 0];
        }

        $this->ensureInitialDiscoveries($character);

        $areas = CharacterAreaProgress::where('character_id', $character->id)
            ->where('is_unlocked', true)
            ->count();
        $cities = CharacterCityDiscovery::where('character_id', $character->id)
            ->where('discovery_state', 'discovered')
            ->count();

        return ['areas' => $areas, 'cities' => $cities];
    }

    private function applyDiscoveriesFrom(Character $character, string $fromType, int $fromId, CharacterAreaProgress $sourceProgress): array
    {
        return AreaDiscoveryLink::where('from_type', $fromType)
            ->where('from_id', $fromId)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (AreaDiscoveryLink $link) => $this->linkConditionMet($character, $link, $sourceProgress))
            ->map(fn (AreaDiscoveryLink $link) => $this->applyDiscoveryLink($character, $link))
            ->filter()
            ->values()
            ->all();
    }

    private function applyDiscoveryLink(Character $character, AreaDiscoveryLink $link): ?array
    {
        if ($this->isTargetDiscovered($character, $link)) {
            return null;
        }

        if (in_array($link->to_type, ['area', 'route_area'], true)) {
            $area = Area::find((int) $link->to_id);
            if (!$area || !app(DungeonPublicationService::class)->isPublished($area)) {
                return null;
            }

            $this->discoverArea($character, $area);
            $this->writeAreaDiscoveryPublicLog($character, $area, $link);

            return ['type' => 'area', 'id' => (int) $area->id, 'name' => $area->name];
        }

        if ($link->to_type === 'city') {
            $city = City::find((int) $link->to_id);
            if (!$city) {
                return null;
            }

            $this->discoverCity($character, $city);
            $this->writeCityDiscoveryPublicLog($character, $city);
            $this->discoverCityEntranceAreas($character, $city);

            return ['type' => 'city', 'id' => (int) $city->id, 'name' => $city->name];
        }

        return null;
    }

    private function discoverArea(Character $character, Area $area): CharacterAreaProgress
    {
        $progress = CharacterAreaProgress::firstOrCreate(
            ['character_id' => $character->id, 'area_id' => $area->id],
            ['is_unlocked' => true, 'unlocked_at' => now()]
        );

        $progress->is_unlocked = true;
        $progress->unlocked_at ??= now();
        if (!in_array($progress->discovery_state, ['discovered', 'cleared'], true)) {
            $progress->discovery_state = 'discovered';
            $progress->discovered_at ??= now();
        }
        $progress->save();

        return $progress;
    }

    private function discoverCity(Character $character, City $city): void
    {
        CharacterCityDiscovery::updateOrCreate(
            ['character_id' => $character->id, 'city_id' => $city->id],
            ['discovery_state' => 'discovered', 'discovered_at' => now()]
        );

        $highestCity = $character->highestCity;
        if (!$highestCity || (int) $city->sort_order > (int) $highestCity->sort_order) {
            $character->highest_city_id = $city->id;
            $character->save();
            $character->refresh();
        }
    }

    private function discoverCityEntranceAreas(Character $character, City $city): void
    {
        AreaDiscoveryLink::where('from_type', 'city')
            ->where('from_id', $city->id)
            ->whereIn('condition_type', ['initial', 'city_discovered'])
            ->orderBy('sort_order')
            ->get()
            ->each(fn (AreaDiscoveryLink $link) => $this->applyDiscoveryLink($character, $link));

        $firstArea = app(DungeonPublicationService::class)->publishedAreas()
            ->where('city_id', $city->id)
            ->where('id', '<=', 70)
            ->orderBy('sort_order')
            ->first();
        if ($firstArea) {
            $this->discoverArea($character, $firstArea);
        }
    }

    private function writeCityDiscoveryPublicLog(Character $character, City $city): void
    {
        if (app(FerdiaMapService::class)->isFerdiaCityId((int) $city->id)) {
            $message = match ((string) $city->name) {
                '辺境の町ルヴァン' => "【発見】{$character->name}さんが「辺境の町ルヴァン」を発見しました！",
                '王都グランフォード' => "【街発見】{$character->name}さんが「王都グランフォード」に到達しました！",
                '港町アーヴェン' => "【街発見】{$character->name}さんが「港町アーヴェン」に到達しました！",
                default => "【街発見】{$character->name}さんが「{$city->name}」に到達しました！",
            };

            app(PublicLogService::class)->addLog('area', $message, $character, 3);
            return;
        }

        app(PublicLogService::class)->addLog(
            'area',
            "【街発見】{$character->name}さんが新たな街「{$city->name}」を発見しました！",
            $character,
            3
        );
    }

    private function writeAreaDiscoveryPublicLog(Character $character, Area $area, AreaDiscoveryLink $link): void
    {
        $node = app(FerdiaMapService::class)->nodeForArea((int) $area->id);
        if ($node) {
            $message = match ((string) ($node['key'] ?? '')) {
                'daiju_seijo' => "【発見】{$character->name}さんが「大樹の聖城」への道を見つけました！",
                'elvan_peak' => "【踏破】{$character->name}さんが「北境の霊峰エルヴァン」に到達しました！",
                'old_supply_depot' => "【発見】{$character->name}さんが「古王国の補給廠」への入口を見つけました！",
                'sunken_supply_ship' => "【発見】{$character->name}さんが「沈んだ補給船」を発見しました！",
                default => null,
            };

            if ($message) {
                app(PublicLogService::class)->addLog('area', $message, $character, 3);
            }
            return;
        }

        // 隠し/裏などの探索先は、種別や存在を公開チャットに出さない。
    }

    private function progressFor(Character $character, Area $area): CharacterAreaProgress
    {
        return CharacterAreaProgress::firstOrCreate(
            ['character_id' => $character->id, 'area_id' => $area->id],
            [
                'is_unlocked' => true,
                'unlocked_at' => now(),
                'discovery_state' => 'discovered',
                'discovered_at' => now(),
            ]
        );
    }

    private function linkConditionMet(Character $character, AreaDiscoveryLink $link, CharacterAreaProgress $sourceProgress): bool
    {
        $conditionMet = match ($link->condition_type) {
            'initial', 'city_discovered' => true,
            'development_point' => (int) $sourceProgress->development_point >= (int) $link->required_development_point,
            'boss_defeated' => (bool) $sourceProgress->boss_defeated,
            'boss_or_development' => (bool) $sourceProgress->boss_defeated
                || (int) $sourceProgress->development_point >= (int) $link->required_development_point,
            'all_story_branches_cleared' => app(FerdiaMapService::class)->allNodesCompleted(
                $character,
                (array) config('ferdia_world_map.story_final_unlock.required_node_keys', [])
            ),
            default => false,
        };

        return $conditionMet && (!$link->requires_boss_defeated || (bool) $sourceProgress->boss_defeated);
    }

    private function maxDevelopmentPointFor(Area $area): int
    {
        $ferdiaMax = app(FerdiaMapService::class)->maxDevelopmentPointForArea($area);
        if ($ferdiaMax !== null) {
            return $ferdiaMax;
        }

        $fromType = $area->is_route_area ? 'route_area' : 'area';
        $linkRequiredPoint = (int) AreaDiscoveryLink::where('from_type', $fromType)
            ->where('from_id', (int) $area->id)
            ->whereIn('condition_type', ['development_point', 'boss_or_development'])
            ->max('required_development_point');

        return max(
            self::DEFAULT_MAX_DEVELOPMENT_POINT,
            (int) ($area->development_required_point ?? 0),
            $linkRequiredPoint
        );
    }

    private function developmentGainFor(Area $area): int
    {
        return app(FerdiaMapService::class)->developmentGainForArea($area)
            ?? self::DEVELOPMENT_GAIN_PER_VICTORY;
    }

    private function isTargetDiscovered(Character $character, AreaDiscoveryLink $link): bool
    {
        if (in_array($link->to_type, ['area', 'route_area'], true)) {
            return CharacterAreaProgress::where('character_id', $character->id)
                ->where('area_id', (int) $link->to_id)
                ->whereIn('discovery_state', ['discovered', 'cleared'])
                ->exists();
        }

        if ($link->to_type === 'city') {
            return CharacterCityDiscovery::where('character_id', $character->id)
                ->where('city_id', (int) $link->to_id)
                ->where('discovery_state', 'discovered')
                ->exists();
        }

        return true;
    }

    private function isLinkTargetPublished(AreaDiscoveryLink $link): bool
    {
        if (!in_array($link->to_type, ['area', 'route_area'], true)) {
            return true;
        }

        $area = Area::find((int) $link->to_id);

        return $area !== null && app(DungeonPublicationService::class)->isPublished($area);
    }

    private function isAvailable(): bool
    {
        return Schema::hasTable('area_discovery_links')
            && Schema::hasTable('character_city_discoveries')
            && Schema::hasColumn('character_area_progresses', 'discovery_state')
            && Schema::hasColumn('character_area_progresses', 'development_point')
            && Schema::hasColumn('areas', 'area_kind');
    }
}
