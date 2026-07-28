<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ValidateDungeon extends Command
{
    private const FERDIA_MAIN_AREA_ID_MIN = 1001;
    private const FERDIA_MAIN_AREA_ID_MAX = 1013;

    protected $signature = 'dungeon:validate';

    protected $description = 'ダンジョン、敵、素材、発見リンクの参照整合性を検証します。';

    public function handle(): int
    {
        foreach (['areas', 'cities'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("必要テーブル {$table} がありません。migration 後に実行してください。");
                return self::FAILURE;
            }
        }

        $errors = [];
        $warnings = [];

        $this->collect($errors, 'areas.city_id', DB::table('areas')
            ->leftJoin('cities', 'cities.id', '=', 'areas.city_id')
            ->whereNotNull('areas.city_id')->whereNull('cities.id')->pluck('areas.id'));
        $this->collect($errors, 'areas.unlock_required_area_id', DB::table('areas as areas')
            ->leftJoin('areas as required_areas', 'required_areas.id', '=', 'areas.unlock_required_area_id')
            ->whereNotNull('areas.unlock_required_area_id')
            ->where(fn ($query) => $query->whereNull('required_areas.id')->orWhereColumn('areas.id', 'areas.unlock_required_area_id'))
            ->pluck('areas.id'));

        if (Schema::hasTable('enemies')) {
            $this->collect($errors, 'enemies.area_id', DB::table('enemies')
                ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')->whereNull('areas.id')->pluck('enemies.id'));
        }
        foreach (['dungeon_id', 'source_area_id'] as $column) {
            if (Schema::hasTable('materials') && Schema::hasColumn('materials', $column)) {
                $this->collect($errors, "materials.{$column}", DB::table('materials')
                    ->leftJoin('areas', 'areas.id', '=', "materials.{$column}")
                    ->whereNotNull("materials.{$column}")->whereNull('areas.id')->pluck('materials.id'));
            }
        }
        if (Schema::hasTable('material_drops') && Schema::hasTable('enemies') && Schema::hasTable('materials')) {
            $this->collect($errors, 'material_drops.enemy_id/material_id', DB::table('material_drops')
                ->leftJoin('enemies', 'enemies.id', '=', 'material_drops.enemy_id')
                ->leftJoin('materials', 'materials.id', '=', 'material_drops.material_id')
                ->where(fn ($query) => $query->whereNull('enemies.id')->orWhereNull('materials.id'))
                ->pluck('material_drops.id'));
            $this->validateFerdiaMaterialDropCoverage($errors);
        }
        if (Schema::hasTable('area_discovery_links')) {
            foreach (DB::table('area_discovery_links')->orderBy('id')->get() as $link) {
                if (!$this->targetExists((string) $link->from_type, (int) $link->from_id)
                    || !$this->targetExists((string) $link->to_type, (int) $link->to_id)) {
                    $errors[] = "area_discovery_links #{$link->id} ({$link->from_type}:{$link->from_id} -> {$link->to_type}:{$link->to_id})";
                    continue;
                }
                if (in_array($link->to_type, ['area', 'route_area'], true)
                    && Schema::hasColumn('areas', 'is_published')
                    && !(bool) DB::table('areas')->where('id', $link->to_id)->value('is_published')) {
                    $warnings[] = "area_discovery_links #{$link->id} は未公開エリア #{$link->to_id} を参照します（到達・噂表示は抑止します）。";
                }
            }
        }
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        if ($errors !== []) {
            $this->error('ダンジョン参照整合性チェックに失敗しました。');
            foreach ($errors as $error) $this->line("- {$error}");
            return self::FAILURE;
        }
        $this->info('ダンジョン参照整合性チェックは通過しました。');
        return self::SUCCESS;
    }

    private function validateFerdiaMaterialDropCoverage(array &$errors): void
    {
        $areaIds = DB::table('areas')
            ->whereBetween('id', [self::FERDIA_MAIN_AREA_ID_MIN, self::FERDIA_MAIN_AREA_ID_MAX])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        if ($areaIds->isEmpty()) {
            return;
        }

        $baseQuery = DB::table('material_drops as material_drop')
            ->join('enemies as enemy', 'enemy.id', '=', 'material_drop.enemy_id')
            ->join('materials as material', 'material.id', '=', 'material_drop.material_id')
            ->whereIn('enemy.area_id', $areaIds)
            ->where('enemy.is_boss', false)
            ->where('material_drop.is_active', true)
            ->where('material_drop.drop_first_clear_only', false)
            ->where('material_drop.drop_rate', '>', 0);

        $normalAreaIds = (clone $baseQuery)
            ->where('material.name', 'not like', '%古代片%')
            ->distinct()
            ->pluck('enemy.area_id')
            ->map(fn ($id): int => (int) $id);
        $ancientAreaIds = (clone $baseQuery)
            ->where('material.name', 'like', '%古代片%')
            ->distinct()
            ->pluck('enemy.area_id')
            ->map(fn ($id): int => (int) $id);

        foreach ($areaIds as $areaId) {
            if (!$normalAreaIds->contains($areaId)) {
                $errors[] = "ferdia material_drops normal: area #{$areaId}";
            }
            if (!$ancientAreaIds->contains($areaId)) {
                $errors[] = "ferdia material_drops ancient: area #{$areaId}";
            }
        }
    }

    private function targetExists(string $type, int $id): bool
    {
        return match ($type) {
            'city' => DB::table('cities')->where('id', $id)->exists(),
            'area' => DB::table('areas')->where('id', $id)->where(fn ($query) => $query->where('is_route_area', false)->orWhereNull('is_route_area'))->exists(),
            'route_area' => DB::table('areas')->where('id', $id)->where('is_route_area', true)->exists(),
            default => false,
        };
    }

    /** @param iterable<int, int|string> $ids */
    private function collect(array &$errors, string $label, iterable $ids): void
    {
        foreach ($ids as $id) $errors[] = "{$label}: #{$id}";
    }
}
