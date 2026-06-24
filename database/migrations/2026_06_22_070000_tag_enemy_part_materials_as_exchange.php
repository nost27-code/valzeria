<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MAT\d+ コードを持つ敵ドロップ素材に 交換所 タグを付与する
// これらは MaterialExchangeService::isEnemyPartMaterial() の条件に合致し
// 素材交換所で調合素材（獣牙素材・毒素材等）に変換できる
return new class extends Migration
{
    // isEnemyPartMaterial() の除外条件に合致する名前キーワード
    private const EXCLUDED_NAME_PATTERNS = [
        '刻印', '王印', '導石', '古代片', '秘境晶', '極印',
        '英雄の証', '進化証', '装備の欠片', '強化石',
    ];

    public function up(): void
    {
        // MAT\d+ パターン（MAT0001〜MAT9999）に合致する素材をすべて取得
        // SQLite: GLOB で代用、MySQL: REGEXP でも可
        // MAT0001〜MAT9999 (= 'MAT' + 4桁数字) のみ対象
        // GLOB は SQLite 専用のため LENGTH + PHP regex でフィルタ
        DB::table('materials')
            ->where('material_code', 'like', 'MAT%')
            ->whereRaw('LENGTH(material_code) = 7')
            ->get(['id', 'material_code', 'name', 'usage_tags'])
            ->filter(fn ($m) => preg_match('/^MAT\d{4}$/', $m->material_code))
            ->reject(function ($mat) {
                foreach (self::EXCLUDED_NAME_PATTERNS as $pattern) {
                    if (str_contains($mat->name, $pattern)) {
                        return true;
                    }
                }
                return false;
            })
            ->each(function ($mat) {
                $existing = json_decode($mat->usage_tags ?? '[]', true) ?? [];
                if (!in_array('交換所', $existing, true)) {
                    $merged = array_values(array_unique(array_merge($existing, ['交換所'])));
                    DB::table('materials')->where('id', $mat->id)->update([
                        'usage_tags' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('materials')
            ->where('material_code', 'like', 'MAT%')
            ->whereRaw('LENGTH(material_code) = 7')
            ->whereNotNull('usage_tags')
            ->get(['id', 'usage_tags'])
            ->each(function ($mat) {
                $tags     = json_decode($mat->usage_tags, true) ?? [];
                $filtered = array_values(array_filter($tags, fn ($t) => $t !== '交換所'));
                DB::table('materials')->where('id', $mat->id)->update([
                    'usage_tags' => empty($filtered) ? null : json_encode($filtered, JSON_UNESCAPED_UNICODE),
                ]);
            });
    }
};
