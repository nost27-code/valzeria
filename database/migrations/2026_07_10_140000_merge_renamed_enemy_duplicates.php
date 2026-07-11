<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 敵マスタのID振り直し＋改名（例: 神殿兵 → 魔神神殿兵）の際、
     * 本番DBに旧IDの敵レコードと印が残存し、印図鑑で重複表示になっていた。
     * 旧名は新名と異なるため MonsterMarkService の同名シグネチャ重複除去では
     * マージされない。旧敵の印所持数を新敵の印へ合算してから旧敵を削除する。
     *
     * 対象: ネクロム4 / セレスティア2〜7 / ヴァルゼリア2・7（プレイヤー報告と一致）
     */
    private const UNLOCK_THRESHOLDS = [1, 3, 7, 15];

    /** area_id => [旧敵名 => 現行マスタの敵ID] */
    private const RENAMED_ENEMIES = [
        // ネクロム4 悪魔神殿
        53 => [
            '神殿兵' => 313,      // 魔神神殿兵
            '封印の守護者' => 314, // 魔神封印守
            '祭壇スライム' => 315, // 魔神祭壇スライム
            '古代司祭' => 316,    // 魔神司祭
            '神殿の主' => 317,    // 魔神神殿主
        ],
        // セレスティア2 天空回廊
        58 => [
            '次元スライム' => 343,
            '時空の影' => 344,
            '迷い人の亡霊' => 345,
            '空間裂け目' => 346,
            '回廊の主' => 347,
        ],
        // セレスティア3 雷鳴神殿
        59 => [
            '神殿兵' => 349,
            '封印の守護者' => 350,
            '祭壇スライム' => 351,
            '古代司祭' => 352,
            '神殿の主' => 353,
        ],
        // セレスティア4 浮遊遺跡
        60 => [
            '古代兵' => 355,      // 天空機兵
            '遺跡スライム' => 356, // 浮遊遺跡スライム
            '石像兵' => 357,      // 天空石像兵
            '呪文壁' => 358,      // 天空呪文壁
            '遺跡の守護者' => 359, // 天空遺跡守護者
        ],
        // セレスティア5 天使の庭園
        61 => [
            '月光ピクシー' => 361,
            '庭園の騎士' => 362,
            '光る蝶' => 363,
            '星草スライム' => 364,
            '庭園の番人' => 365,
        ],
        // セレスティア6 星辰の塔
        62 => [
            '星読みの亡霊' => 367,
            '塔の魔術師' => 368,
            '星屑スライム' => 369,
            '天文ゴーレム' => 370,
            '塔の守護者' => 371,
        ],
        // セレスティア7 神々の祭壇
        63 => [
            '神殿騎士' => 373,
            '祭壇の天使' => 374,
            '星の守護者' => 375,
            '古代神官' => 376,
            '雷神ヴォルト' => 377, // 同名だが旧IDの残存行がありうる
        ],
        // ヴァルゼリア2 絶望の回廊
        65 => [
            '次元スライム' => 385,
            '時空の影' => 386,
            '迷い人の亡霊' => 387,
            '空間裂け目' => 388,
            '回廊の主' => 389,
        ],
        // ヴァルゼリア7 終焉の祭壇
        70 => [
            '神殿騎士' => 415,
            '祭壇の天使' => 416,
            '星の守護者' => 417,
            '古代神官' => 418,
            '雷神ヴォルト' => 419, // 終焉ヴォルト
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('enemies') || !Schema::hasTable('monster_marks')) {
            return;
        }

        DB::transaction(function () {
            foreach (self::RENAMED_ENEMIES as $areaId => $mapping) {
                foreach ($mapping as $oldName => $newEnemyId) {
                    $this->mergeIntoNewEnemy($areaId, $oldName, $newEnemyId);
                }
            }
        });
    }

    public function down(): void
    {
        // 統合済みデータは分離できないため元に戻せない
    }

    private function mergeIntoNewEnemy(int $areaId, string $oldName, int $newEnemyId): void
    {
        $newEnemy = DB::table('enemies')->where('id', $newEnemyId)->first();
        if (!$newEnemy) {
            return;
        }

        $oldEnemies = DB::table('enemies')
            ->where('area_id', $areaId)
            ->where('name', $oldName)
            ->where('is_boss', false)
            ->where('id', '!=', $newEnemyId)
            ->get();

        foreach ($oldEnemies as $oldEnemy) {
            $this->mergeMarks((int) $oldEnemy->id, $newEnemyId, (string) $newEnemy->name);

            if (Schema::hasTable('battle_logs')) {
                DB::table('battle_logs')
                    ->where('enemy_id', $oldEnemy->id)
                    ->update(['enemy_id' => $newEnemyId]);
            }

            // enemy_drops / material_drops / 残った monster_marks はカスケード削除
            DB::table('enemies')->where('id', $oldEnemy->id)->delete();
        }
    }

    private function mergeMarks(int $oldEnemyId, int $newEnemyId, string $newEnemyName): void
    {
        $oldMark = DB::table('monster_marks')->where('enemy_id', $oldEnemyId)->first();
        if (!$oldMark) {
            return;
        }

        $newMark = DB::table('monster_marks')->where('enemy_id', $newEnemyId)->first();
        if (!$newMark) {
            // 新敵側にまだ印がなければ、旧印を付け替えるだけでよい
            DB::table('monster_marks')->where('id', $oldMark->id)->update([
                'enemy_id' => $newEnemyId,
                'mark_name' => $newEnemyName . 'の印',
                'updated_at' => now(),
            ]);

            return;
        }

        $ownedRows = DB::table('character_monster_marks')
            ->where('monster_mark_id', $oldMark->id)
            ->get();

        foreach ($ownedRows as $row) {
            $target = DB::table('character_monster_marks')
                ->where('character_id', $row->character_id)
                ->where('monster_mark_id', $newMark->id)
                ->first();

            $quantity = (int) $row->quantity + (int) ($target->quantity ?? 0);
            $level = $this->unlockedLevel($quantity, (int) $newMark->max_level);

            if ($target) {
                DB::table('character_monster_marks')->where('id', $target->id)->update([
                    'quantity' => $quantity,
                    'unlocked_level' => $level,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('character_monster_marks')->insert([
                    'character_id' => $row->character_id,
                    'monster_mark_id' => $newMark->id,
                    'quantity' => $quantity,
                    'unlocked_level' => $level,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('character_monster_marks')->where('id', $row->id)->delete();
        }

        DB::table('monster_marks')->where('id', $oldMark->id)->delete();
    }

    private function unlockedLevel(int $quantity, int $maxLevel): int
    {
        $max = min(count(self::UNLOCK_THRESHOLDS), max(0, $maxLevel));
        $level = 0;

        foreach (array_slice(self::UNLOCK_THRESHOLDS, 0, $max) as $threshold) {
            if ($quantity >= $threshold) {
                $level++;
            }
        }

        return $level;
    }
};
