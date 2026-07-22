<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ExplorationMapDropService
{
    public function __construct(private readonly ExplorationMapGenerator $generator) {}

    public function tryDrop(Character $character, Area $area, Enemy $enemy, bool $isBoss = false, bool $isMap = false): ?array
    {
        if (!config('exploration_maps.enabled') || !Schema::hasTable('exploration_maps')) return null;
        $key = $isMap ? ((bool) ($enemy->is_elite ?? false) ? 'map_elite' : 'map_normal') : ($isBoss ? 'boss' : ((bool) ($enemy->is_elite ?? false) ? 'elite' : 'normal'));
        if (random_int(1, 10000) > $this->dropRateBasisPoints($key)) return null;
        $map = $this->generator->generate($character, $area, $enemy, (string) Str::uuid());
        return ['id' => $map->id, 'name' => '未調査の探索地図', 'grade' => $map->map_grade, 'map' => $map];
    }

    public function dropRateBasisPoints(string $key): int
    {
        return max(0, min(10000, (int) config("exploration_maps.drop_rates_basis_points.{$key}", 0)));
    }
}
