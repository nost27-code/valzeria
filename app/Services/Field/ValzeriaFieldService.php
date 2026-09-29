<?php

namespace App\Services\Field;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterFieldPosition;
use App\Models\City;
use App\Services\AreaService;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Collection;

/**
 * ヴァルゼリア大陸フィールド。
 * 地形はクライアント（public/js/field）がマクロ地図と config/valzeria_field.php から毎回同じ形に作る。
 * サーバーは「どこに何があるか」を配り、位置の保存・周りの冒険者・施設や入口を使えるかを判定する。
 */
class ValzeriaFieldService
{
    public const PLANE_LAND = 'land';

    public const PLANE_SKY = 'sky';

    public function __construct(private readonly AreaService $areaService) {}

    public function enabled(): bool
    {
        return (bool) config('valzeria_field.enabled');
    }

    // ---- 座標 ------------------------------------------------------------------------

    /**
     * 設定の座標（本土は参照画像の px、浮遊島は島の左上からのマス）を絶対マス座標にする。
     *
     * @param  array{0: float|int, 1: float|int}  $at
     * @return array{0: int, 1: int}
     */
    public function toTile(string $plane, array $at): array
    {
        if ($plane === self::PLANE_SKY) {
            [$ox, $oy] = config('valzeria_field.sky.origin');

            return [(int) round($ox + $at[0]), (int) round($oy + $at[1])];
        }

        $scale = (float) config('valzeria_field.map_scale');

        return [(int) round($at[0] * $scale), (int) round($at[1] * $scale)];
    }

    public function tilePx(): int
    {
        return (int) config('valzeria_field.tile');
    }

    /** その層の中か（px） */
    public function inBounds(string $plane, int $x, int $y): bool
    {
        $tile = $this->tilePx();
        if ($plane === self::PLANE_SKY) {
            [$ox, $oy] = config('valzeria_field.sky.origin');
            [$w, $h] = config('valzeria_field.sky.size');

            return $x >= $ox * $tile && $y >= $oy * $tile && $x < ($ox + $w) * $tile && $y < ($oy + $h) * $tile;
        }
        if ($plane !== self::PLANE_LAND) {
            return false;
        }
        [$w, $h] = config('valzeria_field.world_tiles');

        return $x >= 0 && $y >= 0 && $x < $w * $tile && $y < $h * $tile;
    }

    // ---- 世界の定義（クライアントへ配る） ---------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function worldDefinition(bool $includeGameplay = true): array
    {
        $areaIds = collect(config('valzeria_field.entrances'))->pluck('area_id')->all();
        $areas = Area::query()->whereIn('id', $areaIds)->get(['id', 'name', 'city_id', 'recommended_level_min', 'recommended_level_max'])->keyBy('id');
        $encounters = config('valzeria_field.encounters');
        $gathering = collect(config('valzeria_field.gathering'))->only(['cell_tiles', 'gather_chance', 'chest_chance'])->all();
        if (! $includeGameplay) {
            $encounters['spawn_chance'] = 0;
            $encounters['second_chance'] = 0;
            $gathering['gather_chance'] = 0;
            $gathering['chest_chance'] = 0;
        }

        return [
            'seed' => (int) config('valzeria_field.seed'),
            'tile' => $this->tilePx(),
            'chunk_tiles' => (int) config('valzeria_field.chunk_tiles'),
            'world_tiles' => config('valzeria_field.world_tiles'),
            'map_scale' => (float) config('valzeria_field.map_scale'),
            'macro' => [
                'url' => asset(config('valzeria_field.macro.path')).'?v='.$this->macroVersion(),
                'size' => config('valzeria_field.macro.size'),
            ],
            'sky' => $this->skyDefinition(),
            'movement' => config('valzeria_field.movement'),
            'sync_interval_seconds' => (int) config('valzeria_field.sync.interval_seconds'),
            'presence_seconds' => (int) config('valzeria_field.sync.presence_seconds'),
            'cities' => $this->cityDefinitions()->values()->all(),
            'waypoints' => $this->waypointDefinitions(),
            'roads' => $this->roadDefinitions(),
            'rivers' => $this->riverDefinitions(),
            'entrances' => collect(config('valzeria_field.entrances'))->map(function (array $e) use ($areas): array {
                $plane = $e['plane'] ?? self::PLANE_LAND;
                [$tx, $ty] = $this->toTile($plane, $e['at']);
                $area = $areas->get($e['area_id']);

                return [
                    'area_id' => (int) $e['area_id'],
                    'name' => $area?->name ?? "未登録の探索地 #{$e['area_id']}",
                    'city_id' => (int) ($area?->city_id ?? 0),
                    'level' => $area ? [(int) $area->recommended_level_min, (int) $area->recommended_level_max] : null,
                    'kind' => $e['kind'],
                    'plane' => $plane,
                    'road' => $e['road'] ?? null,
                    'tx' => $tx,
                    'ty' => $ty,
                ];
            })->values()->all(),
            'barriers' => collect(config('valzeria_field.barriers'))->map(function (array $b): array {
                [$tx, $ty] = $this->toTile(self::PLANE_LAND, $b['at']);

                return ['key' => $b['key'], 'name' => $b['name'], 'road' => $b['road'], 'tx' => $tx, 'ty' => $ty, 'unlock_city_id' => (int) $b['unlock_city_id']];
            })->values()->all(),
            'teleporters' => $this->teleporterDefinitions()->values()->all(),
            'encounters' => $encounters,
            'gathering' => $gathering,
            'monster_looks' => $includeGameplay ? app(FieldEncounterService::class)->monsterLooks($areaIds) : [],
            'regions' => collect(config('valzeria_field.regions'))->map(function (array $r): array {
                $plane = $r['plane'] ?? self::PLANE_LAND;
                [$tx, $ty] = $this->toTile($plane, $r['at']);

                return ['name' => $r['name'], 'plane' => $plane, 'tx' => $tx, 'ty' => $ty];
            })->values()->all(),
        ];
    }

    private function macroVersion(): string
    {
        $path = public_path(config('valzeria_field.macro.path'));

        return is_file($path) ? (string) filemtime($path) : '0';
    }

    /** @return array<string, mixed> */
    private function skyDefinition(): array
    {
        $sky = config('valzeria_field.sky');

        return [
            'origin' => $sky['origin'],
            'size' => $sky['size'],
            'center' => $this->toTile(self::PLANE_SKY, $sky['center']),
            'radius' => $sky['radius'],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function cityDefinitions(): Collection
    {
        $standard = config('valzeria_field.standard_facilities');
        $facilities = config('valzeria_field.facilities');

        return collect(config('valzeria_field.cities'))->map(function (array $c, int $id) use ($standard, $facilities): array {
            $plane = $c['plane'] ?? self::PLANE_LAND;
            [$cx, $cy] = $this->toTile($plane, $c['at']);
            [$w, $h] = $c['size'];
            $slugs = $c['facilities'] ?? $standard;

            return [
                'id' => $id,
                'key' => $c['key'],
                'name' => $c['name'],
                'style' => $c['style'],
                'shape' => $c['shape'] ?? 'rect',
                'plane' => $plane,
                'landmark' => $c['landmark'] ?? null,
                'harbor' => $c['harbor'] ?? null,
                'gates' => $c['gates'] ?? ['n', 's', 'e', 'w'],
                'tx' => $cx - intdiv($w, 2),
                'ty' => $cy - intdiv($h, 2),
                'w' => $w,
                'h' => $h,
                'facilities' => collect($slugs)
                    ->filter(fn (string $slug): bool => isset($facilities[$slug]))
                    ->map(fn (string $slug): array => ['slug' => $slug, 'label' => $facilities[$slug]['label'], 'size' => $facilities[$slug]['size']])
                    ->values()
                    ->all(),
            ];
        });
    }

    /** @return array<string, array{plane: string, tx: int, ty: int}> */
    private function waypointDefinitions(): array
    {
        $out = [];
        foreach (config('valzeria_field.waypoints') as $key => $wp) {
            $plane = is_array($wp) && isset($wp['plane']) ? $wp['plane'] : self::PLANE_LAND;
            $at = isset($wp['at']) ? $wp['at'] : $wp;
            [$tx, $ty] = $this->toTile($plane, $at);
            $out[$key] = ['plane' => $plane, 'tx' => $tx, 'ty' => $ty];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function roadDefinitions(): array
    {
        return collect(config('valzeria_field.roads'))->map(function (array $r): array {
            $plane = $r['plane'] ?? self::PLANE_LAND;

            return [
                'key' => $r['key'],
                'plane' => $plane,
                'from' => $r['from'],
                'to' => $r['to'],
                'restricted' => (bool) ($r['restricted'] ?? false),
                'paved' => (bool) ($r['paved'] ?? false),
                'via' => array_map(fn (array $p): array => $this->toTile($plane, $p), $r['via'] ?? []),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function riverDefinitions(): array
    {
        return collect(config('valzeria_field.rivers'))->map(fn (array $r): array => [
            'key' => $r['key'],
            'name' => $r['name'],
            'width' => $r['width'],
            'points' => array_map(fn (array $p): array => $this->toTile(self::PLANE_LAND, $p), $r['points']),
        ])->values()->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function teleporterDefinitions(): Collection
    {
        $waypoints = $this->waypointDefinitions();

        return collect(config('valzeria_field.teleporters'))->map(function (array $t) use ($waypoints): array {
            $wp = $waypoints[$t['waypoint']];

            return [
                'key' => $t['key'],
                'name' => $t['name'],
                'plane' => $wp['plane'],
                'tx' => $wp['tx'],
                'ty' => $wp['ty'],
                'to' => $t['to'],
                'unlock_city_id' => (int) $t['unlock_city_id'],
            ];
        });
    }

    // ---- キャラクターの状態 ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function characterState(Character $character, bool $includeGameplay = true): array
    {
        $position = $this->positionFor($character);
        $unlockedCityIds = $this->unlockedCityIds($character);

        return [
            'id' => (int) $character->id,
            'name' => (string) $character->name,
            'level' => (int) ($character->level ?? 1),
            'icon' => CharacterIconCatalog::versionedFieldAsset($character->icon_path ?? CharacterIconCatalog::DEFAULT_ICON),
            'current_city_id' => (int) ($character->current_city_id ?? 0),
            'unlocked_city_ids' => $unlockedCityIds,
            'enterable_area_ids' => $this->enterableAreaIds($character),
            'position' => [
                'plane' => $position->plane,
                'x' => $position->x,
                'y' => $position->y,
                'facing' => $position->facing,
            ],
            'vitals' => $includeGameplay ? app(FieldEncounterService::class)->vitals($character) : null,
            'claimed_spots' => $includeGameplay ? app(FieldEncounterService::class)->claimedSpotKeys($character) : [],
        ];
    }

    /** @return list<int> */
    public function unlockedCityIds(Character $character): array
    {
        $highestOrder = (int) ($character->highestCity?->sort_order ?? $character->currentCity?->sort_order ?? 0);
        $fieldCityIds = array_keys(config('valzeria_field.cities'));

        return City::query()
            ->whereIn('id', $fieldCityIds)
            ->where('sort_order', '<=', $highestOrder)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function canUseCity(Character $character, int $cityId): bool
    {
        return in_array($cityId, $this->unlockedCityIds($character), true);
    }

    /** @return list<int> */
    public function enterableAreaIds(Character $character): array
    {
        $unlockedCityIds = $this->unlockedCityIds($character);
        $fieldAreaIds = collect(config('valzeria_field.entrances'))->pluck('area_id')->map(fn ($id): int => (int) $id);

        return collect($this->areaService->getAreasWithProgress($character))
            ->filter(fn ($area): bool => $fieldAreaIds->contains((int) $area->id)
                && (bool) ($area->is_unlocked ?? false)
                && (bool) ($area->meets_job_requirements ?? true)
                && in_array((int) $area->city_id, $unlockedCityIds, true))
            ->map(fn ($area): int => (int) $area->id)
            ->values()
            ->all();
    }

    public function canEnterArea(Character $character, Area $area): bool
    {
        return $this->entranceFor((int) $area->id) !== null
            && $this->canUseCity($character, (int) $area->city_id)
            && $this->areaService->canEnterArea($character, (int) $area->id);
    }

    /** @return array<string, mixed>|null */
    public function entranceFor(int $areaId): ?array
    {
        return collect(config('valzeria_field.entrances'))->first(fn (array $e): bool => (int) $e['area_id'] === $areaId);
    }

    // ---- 位置 ------------------------------------------------------------------------------

    public function positionFor(Character $character): CharacterFieldPosition
    {
        $position = CharacterFieldPosition::query()->where('character_id', $character->id)->first();
        if ($position && $this->inBounds($position->plane, $position->x, $position->y)) {
            return $position;
        }

        [$plane, $x, $y] = $this->spawnPoint((int) ($character->current_city_id ?? 1));

        return CharacterFieldPosition::query()->updateOrCreate(
            ['character_id' => $character->id],
            ['plane' => $plane, 'x' => $x, 'y' => $y, 'facing' => 0, 'moved_at' => null],
        );
    }

    /**
     * 都市の中央広場の南（噴水の手前）。
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public function spawnPoint(int $cityId): array
    {
        $city = $this->cityDefinitions()->get($cityId) ?? $this->cityDefinitions()->first();
        $tile = $this->tilePx();
        $tx = $city['tx'] + intdiv($city['w'], 2);
        $ty = $city['ty'] + intdiv($city['h'], 2) + 20;

        return [$city['plane'], $tx * $tile + intdiv($tile, 2), $ty * $tile + intdiv($tile, 2)];
    }

    public function savePosition(Character $character, string $plane, int $x, int $y, int $facing): CharacterFieldPosition
    {
        $position = CharacterFieldPosition::query()->firstOrCreate(
            ['character_id' => $character->id],
            ['plane' => $plane, 'x' => $x, 'y' => $y, 'facing' => max(0, min(3, $facing)), 'moved_at' => now()],
        );
        $position->fill(['plane' => $plane, 'x' => $x, 'y' => $y, 'facing' => max(0, min(3, $facing))]);
        $heartbeat = max(1, (int) config('valzeria_field.sync.stationary_heartbeat_seconds', 4));
        if (! $position->exists || $position->isDirty() || ! $position->moved_at || $position->moved_at->lte(now()->subSeconds($heartbeat))) {
            $position->moved_at = now();
            $position->save();
        }

        return $position;
    }

    /**
     * 周りで最近動いた冒険者。
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(Character $character, CharacterFieldPosition $self): array
    {
        $radius = (int) config('valzeria_field.sync.presence_radius_tiles') * $this->tilePx();

        return CharacterFieldPosition::query()
            ->with('character:id,name,level,icon_path')
            ->where('plane', $self->plane)
            ->where('character_id', '!=', $character->id)
            ->where('moved_at', '>=', now()->subSeconds((int) config('valzeria_field.sync.presence_seconds')))
            ->whereBetween('x', [max(0, $self->x - $radius), $self->x + $radius])
            ->whereBetween('y', [max(0, $self->y - $radius), $self->y + $radius])
            ->orderByDesc('moved_at')
            ->limit((int) config('valzeria_field.sync.presence_limit'))
            ->get()
            ->filter(fn (CharacterFieldPosition $p): bool => $p->character !== null)
            ->map(fn (CharacterFieldPosition $p): array => [
                'id' => (int) $p->character_id,
                'name' => (string) $p->character->name,
                'level' => (int) ($p->character->level ?? 1),
                'icon' => CharacterIconCatalog::versionedFieldAsset($p->character->icon_path ?? CharacterIconCatalog::DEFAULT_ICON),
                'x' => $p->x,
                'y' => $p->y,
                'facing' => $p->facing,
            ])
            ->values()
            ->all();
    }

    /**
     * 管理者の観察画面向け。フィールドで直近に動いた全冒険者を返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activePlayers(): Collection
    {
        return CharacterFieldPosition::query()
            ->with('character:id,name,level,icon_path')
            ->where('moved_at', '>=', now()->subSeconds((int) config('valzeria_field.sync.presence_seconds')))
            ->orderByDesc('moved_at')
            ->limit(500)
            ->get()
            ->filter(fn (CharacterFieldPosition $position): bool => $position->character !== null)
            ->map(fn (CharacterFieldPosition $position): array => [
                'id' => (int) $position->character_id,
                'name' => (string) $position->character->name,
                'level' => (int) ($position->character->level ?? 1),
                'icon' => CharacterIconCatalog::versionedFieldAsset($position->character->icon_path ?? CharacterIconCatalog::DEFAULT_ICON),
                'plane' => $position->plane,
                'x' => $position->x,
                'y' => $position->y,
                'facing' => $position->facing,
                'moved_seconds_ago' => max(0, (int) $position->moved_at?->diffInSeconds(now())),
            ])
            ->values();
    }

    /**
     * 最後に保存した位置が、その点の近くか（施設・入口・転移陣を使う時の確認）。
     */
    public function isNear(Character $character, string $plane, int $tx, int $ty, ?int $reachTiles = null): bool
    {
        $position = CharacterFieldPosition::query()->where('character_id', $character->id)->first();
        if (! $position || $position->plane !== $plane) {
            return false;
        }
        $tile = $this->tilePx();
        $reach = ($reachTiles ?? (int) config('valzeria_field.sync.action_reach_tiles')) * $tile;
        $dx = $position->x - ($tx * $tile + $tile / 2);
        $dy = $position->y - ($ty * $tile + $tile / 2);

        return hypot($dx, $dy) <= $reach;
    }

    /** 最後に保存した位置が、その都市の城壁の中（少し外まで含む）か */
    public function isInsideCity(Character $character, int $cityId): bool
    {
        $city = $this->cityDefinitions()->get($cityId);
        $position = CharacterFieldPosition::query()->where('character_id', $character->id)->first();
        if (! $city || ! $position || $position->plane !== $city['plane']) {
            return false;
        }
        $tile = $this->tilePx();
        $margin = (int) config('valzeria_field.sync.city_reach_margin_tiles');
        $tx = intdiv($position->x, $tile);
        $ty = intdiv($position->y, $tile);

        return $tx >= $city['tx'] - $margin && $tx < $city['tx'] + $city['w'] + $margin
            && $ty >= $city['ty'] - $margin && $ty < $city['ty'] + $city['h'] + $margin;
    }

    /** @return array<string, mixed>|null */
    public function teleporter(string $key): ?array
    {
        return $this->teleporterDefinitions()->firstWhere('key', $key);
    }

    /** @return array{0: string, 1: int, 2: int} 転移先に着く位置（転移陣の2マス南） */
    public function teleportArrival(array $destination): array
    {
        $tile = $this->tilePx();

        return [$destination['plane'], $destination['tx'] * $tile + intdiv($tile, 2), ($destination['ty'] + 3) * $tile + intdiv($tile, 2)];
    }
}
