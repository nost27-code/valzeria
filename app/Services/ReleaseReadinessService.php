<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseReadinessService
{
    /** @return array<int, string> */
    public function issues(bool $includeDisabled = false): array
    {
        $issues = $this->databaseReferenceIssues();

        foreach (config('extra_content.contents', []) as $key => $content) {
            if (!$includeDisabled && !app(ExtraContentControlService::class)->isActive((string) $key, $content)) {
                continue;
            }

            $issues = array_merge($issues, $this->contentIssues((string) $key));
        }

        return array_values(array_unique($issues));
    }

    /** @return array<int, string> */
    public function contentIssues(string $contentKey): array
    {
        return match ($contentKey) {
            'star_tree_tower' => $this->starTreeTowerIssues(),
            'ferdia_unlocked' => $this->ferdiaIssues(),
            'exploration_support' => $this->explorationSupportIssues(),
            default => ["未対応の追加コンテンツです: {$contentKey}"],
        };
    }

    /** @return array<int, string> */
    private function databaseReferenceIssues(): array
    {
        $issues = [];

        if (Schema::hasTable('items') && Schema::hasTable('cities') && Schema::hasColumn('items', 'unlock_city_id')) {
            $invalid = DB::table('items')
                ->leftJoin('cities', 'cities.id', '=', 'items.unlock_city_id')
                ->whereNotNull('items.unlock_city_id')
                ->whereNull('cities.id')
                ->count();
            if ($invalid > 0) {
                $issues[] = "items.unlock_city_id に存在しない都市参照が {$invalid} 件あります。";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function starTreeTowerIssues(): array
    {
        $issues = $this->missingTables(['tower_floor_master', 'tower_runs', 'tower_reward_claims']);
        if ($issues !== []) {
            return $issues;
        }

        $towerKey = (string) config('star_tree_tower.star_tree.tower_key', 'star_tree_tower');
        $expectedFloors = (int) config('star_tree_tower.star_tree.seed_floor_count', 100);
        $floors = DB::table('tower_floor_master')->where('tower_key', $towerKey);
        $count = (int) $floors->count();
        $min = (int) $floors->min('floor');
        $max = (int) $floors->max('floor');
        if ($count !== $expectedFloors || $min !== 1 || $max !== $expectedFloors) {
            $issues[] = "星樹の塔の階層マスタが不足しています（期待 {$expectedFloors}階、実際 {$count}件・{$min}〜{$max}階）。";
        }

        if (!Schema::hasTable('items') || DB::table('items')->where('source_type', 'star_tree_tower_reward')->count() === 0) {
            $issues[] = '星樹の塔の初回到達報酬マスタがありません。';
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function ferdiaIssues(): array
    {
        $issues = $this->missingTables(['areas', 'cities', 'enemies', 'area_discovery_links']);
        if ($issues !== []) {
            return $issues;
        }

        $areaCount = DB::table('areas')->whereBetween('id', [1001, 1013])->count();
        if ($areaCount !== 13) {
            $issues[] = "フェルディア探索地マスタが不足しています（期待13件、実際 {$areaCount}件）。";
        }
        if (DB::table('enemies')->whereBetween('area_id', [1001, 1013])->count() === 0) {
            $issues[] = 'フェルディアの敵マスタがありません。';
        }

        return $issues;
    }

    /** @return array<int, string> */
    private function explorationSupportIssues(): array
    {
        $issues = $this->missingTables(['player_exploration_support_effects', 'character_exploration_support_prefs', 'items']);
        if ($issues !== []) {
            return $issues;
        }

        $expected = ['薬屋のお守り', '守りの香', '冒険者の救急包', '薬屋の特製漢方'];
        $actual = DB::table('items')->whereIn('name', $expected)->where('type', 'consumable')->count();
        if ($actual !== count($expected)) {
            $issues[] = "探索補助品マスタが不足しています（期待" . count($expected) . "件、実際 {$actual}件）。";
        }

        return $issues;
    }

    /** @param array<int, string> $tables
     *  @return array<int, string> */
    private function missingTables(array $tables): array
    {
        return collect($tables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->map(fn (string $table): string => "必要テーブル {$table} がありません。")
            ->values()
            ->all();
    }
}
