<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        $rows = collect($this->loadRows());
        $codes = $rows->pluck('material_code')->filter()->unique()->values();
        $materialIds = DB::table('materials')
            ->whereIn('material_code', $codes)
            ->pluck('id', 'material_code');

        $this->updateMaterials($rows);

        if ($materialIds->isNotEmpty()) {
            DB::table('material_drops')->whereIn('material_id', $materialIds->values())->delete();
        }

        foreach ($rows->whereIn('stage_key', ['path', 'ancient']) as $row) {
            $materialId = $materialIds[(string) $row['material_code']] ?? null;
            if (!$materialId) {
                continue;
            }

            foreach ($this->dropTargets($row) as $target) {
                DB::table('material_drops')->updateOrInsert(
                    [
                        'enemy_id' => $target['enemy_id'],
                        'material_id' => $materialId,
                    ],
                    [
                        'drop_rate' => $target['drop_rate'],
                        'drop_first_clear_only' => false,
                        'drop_timing' => 'branch_' . $row['stage_key'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $codes = collect($this->loadRows())->pluck('material_code')->filter()->unique()->values();
        $materialIds = DB::table('materials')
            ->whereIn('material_code', $codes)
            ->pluck('id');

        if ($materialIds->isNotEmpty()) {
            DB::table('material_drops')->whereIn('material_id', $materialIds)->delete();
        }
    }

    private function loadRows(): array
    {
        $path = database_path('data/branch_material_drop_design.json');
        if (!is_file($path)) {
            throw new RuntimeException('branch_material_drop_design.json not found.');
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data['rows'] ?? [];
    }

    private function updateMaterials(Collection $rows): void
    {
        foreach ($rows as $row) {
            $payload = [
                'name' => $row['material_name'],
                'city_id' => $row['primary_city_id'],
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => in_array($row['stage_key'], ['path', 'ancient'], true)
                    ? 'branch_' . $row['stage_key']
                    : null,
                'obtain_method' => $this->obtainMethod($row),
                'updated_at' => now(),
            ];

            DB::table('materials')
                ->where('material_code', $row['material_code'])
                ->update(array_filter(
                    $payload,
                    fn (string $column) => Schema::hasColumn('materials', $column),
                    ARRAY_FILTER_USE_KEY
                ));
        }
    }

    private function obtainMethod(array $row): string
    {
        return match ($row['stage_key']) {
            'path' => "{$row['primary_location']}。{$row['drop_policy']}",
            'ancient' => "{$row['primary_location']}。{$row['drop_policy']}",
            'secret' => "Phase 2予定: {$row['primary_location']}。通常探索ドロップなし。",
            'crest' => "Phase 2予定: {$row['primary_location']}。通常探索ドロップなし。",
            default => (string) ($row['drop_policy'] ?? ''),
        };
    }

    private function dropTargets(array $row): array
    {
        $areas = match ($row['stage_key']) {
            'path' => $this->terminalAreasForCity((int) $row['primary_city_id']),
            'ancient' => $this->ancientAreas($row),
            default => collect(),
        };

        if ($areas->isEmpty()) {
            return [];
        }

        return DB::table('enemies')
            ->whereIn('area_id', $areas->pluck('id'))
            ->where('is_boss', false)
            ->get()
            ->map(function ($enemy) use ($row) {
                $rate = $this->dropRateForRole(
                    (string) $row['stage_key'],
                    (string) ($enemy->role ?? ''),
                    (int) ($row['primary_city_id'] ?? 0)
                );

                return $rate > 0 ? [
                    'enemy_id' => $enemy->id,
                    'drop_rate' => $rate,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function ancientAreas(array $row): Collection
    {
        $location = (string) ($row['primary_location'] ?? '');
        $cityId = (int) ($row['primary_city_id'] ?? 0);

        if (str_contains($location, '前半') || str_contains($location, '中盤')) {
            return $this->earlyMidAreasForCity($cityId);
        }

        return $this->terminalAreasForCity($cityId);
    }

    private function earlyMidAreasForCity(int $cityId): Collection
    {
        return DB::table('areas')
            ->where('city_id', $cityId)
            ->orderBy('sort_order')
            ->get()
            ->filter(function ($area) {
                $localOrder = ((int) $area->sort_order) % 100;

                return $localOrder >= 10 && $localOrder <= 40;
            })
            ->values();
    }

    private function terminalAreasForCity(int $cityId): Collection
    {
        return DB::table('areas')
            ->where('city_id', $cityId)
            ->orderBy('sort_order')
            ->get()
            ->filter(function ($area) {
                $localOrder = ((int) $area->sort_order) % 100;

                return $localOrder >= 50 || (int) $area->id >= 71;
            })
            ->values();
    }

    private function dropRateForRole(string $stageKey, string $role, int $cityId = 0): float
    {
        return match ($stageKey) {
            'path' => $cityId >= 4 ? 1 : 0,
            'ancient' => match (true) {
                str_contains($role, '最深部候補') => 3,
                str_contains($role, 'レア敵') => 1,
                default => 0,
            },
            default => 0,
        };
    }
};
