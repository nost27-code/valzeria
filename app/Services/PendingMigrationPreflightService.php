<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingMigrationPreflightService
{
    private const ENEMY_MERGE_MIGRATION = '2026_07_10_140000_merge_renamed_enemy_duplicates';

    /** area_id => [old enemy name => canonical enemy id] */
    private const RENAMED_ENEMIES = [
        53 => ['神殿兵' => 313, '封印の守護者' => 314, '祭壇スライム' => 315, '古代司祭' => 316, '神殿の主' => 317],
        58 => ['次元スライム' => 343, '時空の影' => 344, '迷い人の亡霊' => 345, '空間裂け目' => 346, '回廊の主' => 347],
        59 => ['神殿兵' => 349, '封印の守護者' => 350, '祭壇スライム' => 351, '古代司祭' => 352, '神殿の主' => 353],
        60 => ['古代兵' => 355, '遺跡スライム' => 356, '石像兵' => 357, '呪文壁' => 358, '遺跡の守護者' => 359],
        61 => ['月光ピクシー' => 361, '庭園の騎士' => 362, '光る蝶' => 363, '星草スライム' => 364, '庭園の番人' => 365],
        62 => ['星読みの亡霊' => 367, '塔の魔術師' => 368, '星屑スライム' => 369, '天文ゴーレム' => 370, '塔の守護者' => 371],
        63 => ['神殿騎士' => 373, '祭壇の天使' => 374, '星の守護者' => 375, '古代神官' => 376, '雷神ヴォルト' => 377],
        65 => ['次元スライム' => 385, '時空の影' => 386, '迷い人の亡霊' => 387, '空間裂け目' => 388, '回廊の主' => 389],
        70 => ['神殿騎士' => 415, '祭壇の天使' => 416, '星の守護者' => 417, '古代神官' => 418, '雷神ヴォルト' => 419],
    ];

    /** @return array{blockers: array<int, string>, mergeSummary: array{old_enemy_rows: int, battle_logs: int, monster_marks: int, character_marks: int}} */
    public function inspect(): array
    {
        $blockers = [];

        if (Schema::hasTable('items') && Schema::hasTable('cities') && Schema::hasColumn('items', 'unlock_city_id')) {
            $invalidUnlockCities = DB::table('items')
                ->leftJoin('cities', 'cities.id', '=', 'items.unlock_city_id')
                ->whereNotNull('items.unlock_city_id')
                ->whereNull('cities.id')
                ->count();
            if ($invalidUnlockCities > 0) {
                $blockers[] = "items.unlock_city_id に存在しない都市参照が {$invalidUnlockCities} 件あります。";
            }
        }

        $summary = ['old_enemy_rows' => 0, 'battle_logs' => 0, 'monster_marks' => 0, 'character_marks' => 0];
        if (!$this->enemyMergeIsPending()) {
            return ['blockers' => $blockers, 'mergeSummary' => $summary];
        }

        if (!Schema::hasTable('enemies')) {
            $blockers[] = 'enemies テーブルがありません。';

            return ['blockers' => $blockers, 'mergeSummary' => $summary];
        }

        foreach (self::RENAMED_ENEMIES as $areaId => $mapping) {
            foreach ($mapping as $oldName => $newEnemyId) {
                if (!DB::table('enemies')->where('id', $newEnemyId)->exists()) {
                    $blockers[] = "統合先の敵マスタ ID {$newEnemyId}（エリア {$areaId}・{$oldName}）がありません。";
                    continue;
                }

                $oldEnemyIds = DB::table('enemies')
                    ->where('area_id', $areaId)
                    ->where('name', $oldName)
                    ->where('is_boss', false)
                    ->where('id', '!=', $newEnemyId)
                    ->pluck('id');
                if ($oldEnemyIds->isEmpty()) {
                    continue;
                }

                $summary['old_enemy_rows'] += $oldEnemyIds->count();
                if (Schema::hasTable('battle_logs')) {
                    $summary['battle_logs'] += DB::table('battle_logs')->whereIn('enemy_id', $oldEnemyIds)->count();
                }
                if (Schema::hasTable('monster_marks')) {
                    $oldMarkIds = DB::table('monster_marks')->whereIn('enemy_id', $oldEnemyIds)->pluck('id');
                    $summary['monster_marks'] += $oldMarkIds->count();
                    if ($oldMarkIds->isNotEmpty() && Schema::hasTable('character_monster_marks')) {
                        $summary['character_marks'] += DB::table('character_monster_marks')->whereIn('monster_mark_id', $oldMarkIds)->count();
                    }
                }
            }
        }

        return ['blockers' => array_values(array_unique($blockers)), 'mergeSummary' => $summary];
    }

    private function enemyMergeIsPending(): bool
    {
        return !Schema::hasTable('migrations')
            || !DB::table('migrations')->where('migration', self::ENEMY_MERGE_MIGRATION)->exists();
    }
}
