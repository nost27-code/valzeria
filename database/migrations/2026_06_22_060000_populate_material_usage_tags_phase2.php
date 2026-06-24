<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phase 2: パターンマッチングでレシピ未登録素材に usage_tags を設定する
//
// 合成  : WEV系/ACC系/BOSS_KEY系/CITY系/TOKEN系/MAT_COMMON系/MAT_REGION系/カテゴリ共通素材
//         + recipes テーブルの is_key 素材（刻印・王印・徽章等）
// 交換所: 未タグの MAT_BR_* 分岐進化素材（交換所で合成して上位石にする元素材）
return new class extends Migration
{
    // material_code の前方一致パターン → 合成タグを付与する
    private const SYNTHESIS_CODE_PREFIXES = [
        'WEV',           // 武器合成素材 (WEV0001〜)
        'ACC',           // アクセサリ進化素材 (ACC0001〜)
        'BOSS_KEY_',     // ボス特異素材 → レシピのキー素材として使用
        'MAT_COMMON_',   // 進化共通素材 (MAT_COMMON_BEAST_FANG 等)
        'MAT_REGION_',   // 地域素材 (MAT_REGION_ARKREA_RAW 等)
    ];

    // material_code の完全一致 → 合成タグ
    private const SYNTHESIS_CODE_EXACT = [
        'MAT_WEAPON_EVOLUTION_STONE',
        'MAT_ARMOR_EVOLUTION_STONE',
        'MAT_ACCESSORY_EVOLUTION_STONE',
        'TOKEN_CITY_MATERIAL',
        'TOKEN_CITY_HIGH_MATERIAL',   // weapon_evolution_recipe_ingredients に登録済みだが念のため
        'TOKEN_SECRET_DUNGEON_MATERIAL',
        'TOKEN_SECRET_HIGH_MATERIAL',
        'TOKEN_SECRET_MATERIAL',
        'TOKEN_LEGEND_MATERIAL',
        'MAT_EXCHANGE_TICKET',
        // カテゴリ共通素材（武器タイプ別進化素材）
        'MAT_SLASH_FRAGMENT', 'MAT_SLASH_CRYSTAL', 'MAT_SLASH_CORE',
        'MAT_PIERCE_FRAGMENT', 'MAT_PIERCE_CRYSTAL', 'MAT_PIERCE_CORE',
        'MAT_BLUNT_FRAGMENT', 'MAT_BLUNT_CRYSTAL', 'MAT_BLUNT_CORE',
        'MAT_RANGED_FRAGMENT', 'MAT_RANGED_CRYSTAL', 'MAT_RANGED_CORE',
        'MAT_MAGIC_FRAGMENT', 'MAT_MAGIC_CRYSTAL', 'MAT_MAGIC_CORE',
        // 武器共通素材
        'MAT_WEAPON_CRYSTAL', 'MAT_WEAPON_CORE', 'MAT_ANCIENT_PART', 'MAT_STARDUST_FORGE',
    ];

    // CITY_で始まり_MATERIALまたは_HIGHで終わるコード → 合成
    // numeric 5002〜5099 material_code → 合成（防具進化素材）
    // abstract/back 系 numeric 5045〜5059 も含む

    public function up(): void
    {
        $this->applyPrefixPatterns();
        $this->applyExactCodes();
        $this->applyCityMaterials();
        $this->applyNumericArmor5xxx();
        $this->applyRecipeKeyMaterials();
        $this->applyBranchExchangeMaterials();
    }

    public function down(): void
    {
        $removeTags = ['合成', '交換所'];

        // Phase 1 で追加したタグは残し、Phase 2 分のみ除去するのが理想だが
        // 区別が困難なため down() では Phase 2 で対象とした素材コードのみ処理する
        // （本番では rollback せず再 migrate が基本のため簡略実装）
        $this->removeTagsFromPrefixes($removeTags);
    }

    // ------------------------------------------------------------------
    // 前方一致パターンで合成タグを付与
    // ------------------------------------------------------------------
    private function applyPrefixPatterns(): void
    {
        foreach (self::SYNTHESIS_CODE_PREFIXES as $prefix) {
            DB::table('materials')
                ->where('material_code', 'like', $prefix . '%')
                ->get(['id', 'usage_tags'])
                ->each(fn ($m) => $this->merge($m, ['合成']));
        }
    }

    // ------------------------------------------------------------------
    // 完全一致コードで合成タグを付与
    // ------------------------------------------------------------------
    private function applyExactCodes(): void
    {
        DB::table('materials')
            ->whereIn('material_code', self::SYNTHESIS_CODE_EXACT)
            ->get(['id', 'usage_tags'])
            ->each(fn ($m) => $this->merge($m, ['合成']));
    }

    // ------------------------------------------------------------------
    // CITY_xx_MATERIAL / CITY_xx_HIGH → 合成
    // ------------------------------------------------------------------
    private function applyCityMaterials(): void
    {
        DB::table('materials')
            ->where(function ($q) {
                $q->where('material_code', 'like', 'CITY_%_MATERIAL')
                  ->orWhere('material_code', 'like', 'CITY_%_HIGH');
            })
            ->get(['id', 'usage_tags'])
            ->each(fn ($m) => $this->merge($m, ['合成']));
    }

    // ------------------------------------------------------------------
    // 数値コード 5001〜5099（防具進化素材）→ 合成
    // ------------------------------------------------------------------
    private function applyNumericArmor5xxx(): void
    {
        // material_code が '5001'〜'5099' の範囲（防具合成・abstract 等）
        // SQLite では CAST 不可のため、PHP 側でフィルタリング
        DB::table('materials')
            ->where('material_code', 'like', '5%')
            ->whereRaw("length(material_code) <= 4")
            ->get(['id', 'material_code', 'usage_tags'])
            ->filter(function ($m) {
                $n = (int) $m->material_code;
                return $n >= 5001 && $n <= 5099 && (string) $n === $m->material_code;
            })
            ->each(fn ($m) => $this->merge($m, ['合成']));
    }

    // ------------------------------------------------------------------
    // recipes テーブルの is_key 素材（刻印・王印等）→ 合成
    // ------------------------------------------------------------------
    private function applyRecipeKeyMaterials(): void
    {
        $keyNames = [];

        DB::table('recipes')->pluck('materials')->each(function ($json) use (&$keyNames) {
            foreach (json_decode($json, true) ?? [] as $m) {
                if (!empty($m['is_key']) && !empty($m['name'])) {
                    $keyNames[$m['name']] = true;
                }
            }
        });

        if (empty($keyNames)) {
            return;
        }

        DB::table('materials')
            ->whereIn('name', array_keys($keyNames))
            ->get(['id', 'usage_tags'])
            ->each(fn ($m) => $this->merge($m, ['合成']));
    }

    // ------------------------------------------------------------------
    // 未タグの MAT_BR_* 分岐進化素材 → 交換所
    // （すでに 合成 タグがついているものはスキップしない — 両用あり得る）
    // ------------------------------------------------------------------
    private function applyBranchExchangeMaterials(): void
    {
        DB::table('materials')
            ->where('material_code', 'like', 'MAT_BR_%')
            ->whereNull('usage_tags')
            ->get(['id', 'usage_tags'])
            ->each(fn ($m) => $this->merge($m, ['交換所']));
    }

    // ------------------------------------------------------------------
    // Helper: 既存タグとマージして保存
    // ------------------------------------------------------------------
    private function merge(object $mat, array $newTags): void
    {
        $existing = json_decode($mat->usage_tags ?? '[]', true) ?? [];
        $merged   = array_values(array_unique(array_merge($existing, $newTags)));
        if ($merged !== $existing) {
            DB::table('materials')->where('id', $mat->id)->update([
                'usage_tags' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // down() 用: Phase 2 対象素材からタグを除去
    // ------------------------------------------------------------------
    private function removeTagsFromPrefixes(array $removeTags): void
    {
        $queries = [
            fn ($q) => $q->where('material_code', 'like', 'WEV%'),
            fn ($q) => $q->where('material_code', 'like', 'ACC%'),
            fn ($q) => $q->where('material_code', 'like', 'BOSS_KEY_%'),
            fn ($q) => $q->where('material_code', 'like', 'MAT_COMMON_%'),
            fn ($q) => $q->where('material_code', 'like', 'MAT_REGION_%'),
            fn ($q) => $q->where('material_code', 'like', 'CITY_%'),
            fn ($q) => $q->where('material_code', 'like', 'TOKEN_%'),
            fn ($q) => $q->where('material_code', 'like', 'MAT_BR_%'),
            fn ($q) => $q->whereIn('material_code', self::SYNTHESIS_CODE_EXACT),
        ];

        foreach ($queries as $scope) {
            DB::table('materials')
                ->where($scope)
                ->whereNotNull('usage_tags')
                ->get(['id', 'usage_tags'])
                ->each(function ($m) use ($removeTags) {
                    $tags     = json_decode($m->usage_tags, true) ?? [];
                    $filtered = array_values(array_filter($tags, fn ($t) => !in_array($t, $removeTags, true)));
                    DB::table('materials')->where('id', $m->id)->update([
                        'usage_tags' => empty($filtered) ? null : json_encode($filtered, JSON_UNESCAPED_UNICODE),
                    ]);
                });
        }
    }
};
