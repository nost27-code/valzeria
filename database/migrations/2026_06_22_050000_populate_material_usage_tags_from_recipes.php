<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1: usage_tags をレシピDBから自動設定する
 *
 * 合成  : recipes.materials JSON / weapon_evolution_recipe_ingredients / armor_evolution_recipe_ingredients
 * 鍛冶  : weapon_enhancement_recipes.materials JSON / armor_enhancement_recipes.required_material_name
 * 交換所: 装備共通欠片系(material_code) / 都市素材(ID 5025-5044) / 調合素材(MAT_BREW_*)
 */
return new class extends Migration
{
    // -------------------------------------------------------------------
    // 交換所の確実なmaterial_codeリスト
    // -------------------------------------------------------------------
    private const EXCHANGE_CODES = [
        'MAT_EQUIPMENT_FRAGMENT',
        'MAT_FINE_EQUIPMENT_FRAGMENT',
        'MAT_STRONG_EQUIPMENT_FRAGMENT',
        'MAT_BREW_BEAST_FANG',
        'MAT_BREW_TOXIN',
        'MAT_BREW_HERB',
        'MAT_BREW_MAGIC_POWDER',
        'MAT_BREW_LOW_MONSTER',
    ];

    // PATH_STONE_RECIPES のソース素材ID（MaterialExchangeService 定数より）
    private const EXCHANGE_CITY_MATERIAL_IDS = [
        5025, 5026, 5027, 5029, 5030, 5031,
        5032, 5033, 5034, 5035, 5036, 5037,
        5038, 5041, 5042, 5043,
    ];

    public function up(): void
    {
        // name => [tags] のマップを構築してからまとめて更新
        $nameTagMap = [];   // material name  => string[]
        $codeTagMap = [];   // material_code  => string[]
        $idTagMap   = [];   // material id    => string[]

        // ------------------------------------------------------------------
        // 1. 合成: recipes.materials JSON (キー素材・非消費は除外)
        // ------------------------------------------------------------------
        DB::table('recipes')->pluck('materials')->each(function ($json) use (&$nameTagMap) {
            foreach (json_decode($json, true) ?? [] as $m) {
                if (!empty($m['name']) && !($m['is_key'] ?? false)) {
                    $nameTagMap[$m['name']][] = '合成';
                }
                if (!empty($m['material_code']) && !($m['is_key'] ?? false)) {
                    // name と両方登録しておく（後でどちらかでヒットさせる）
                }
            }
        });

        // ------------------------------------------------------------------
        // 2. 合成: weapon_evolution_recipe_ingredients
        // ------------------------------------------------------------------
        DB::table('weapon_evolution_recipe_ingredients')
            ->get(['ingredient_name', 'ingredient_id'])
            ->each(function ($row) use (&$nameTagMap, &$codeTagMap) {
                if ($row->ingredient_name) {
                    $nameTagMap[$row->ingredient_name][] = '合成';
                }
                if ($row->ingredient_id) {
                    $codeTagMap[$row->ingredient_id][] = '合成';
                }
            });

        // ------------------------------------------------------------------
        // 3. 合成: armor_evolution_recipe_ingredients
        // ------------------------------------------------------------------
        DB::table('armor_evolution_recipe_ingredients')
            ->get(['material_name', 'material_id'])
            ->each(function ($row) use (&$nameTagMap, &$idTagMap) {
                if ($row->material_name) {
                    $nameTagMap[$row->material_name][] = '合成';
                }
                if ($row->material_id) {
                    $idTagMap[(int) $row->material_id][] = '合成';
                }
            });

        // ------------------------------------------------------------------
        // 4. 鍛冶: weapon_enhancement_recipes.materials JSON
        // ------------------------------------------------------------------
        DB::table('weapon_enhancement_recipes')->pluck('materials')->each(function ($json) use (&$nameTagMap, &$codeTagMap) {
            foreach (json_decode($json, true) ?? [] as $m) {
                if (!empty($m['material_name'])) {
                    $nameTagMap[$m['material_name']][] = '鍛冶';
                }
                if (!empty($m['material_id'])) {
                    $codeTagMap[$m['material_id']][] = '鍛冶';
                }
            }
        });

        // ------------------------------------------------------------------
        // 5. 鍛冶: armor_enhancement_recipes
        // ------------------------------------------------------------------
        DB::table('armor_enhancement_recipes')
            ->get(['required_material_name', 'required_material_id'])
            ->each(function ($row) use (&$nameTagMap, &$codeTagMap) {
                if ($row->required_material_name) {
                    $nameTagMap[$row->required_material_name][] = '鍛冶';
                }
                if ($row->required_material_id) {
                    $codeTagMap[$row->required_material_id][] = '鍛冶';
                }
            });

        // ------------------------------------------------------------------
        // 6. 交換所: 確実なmaterial_codeリスト
        // ------------------------------------------------------------------
        foreach (self::EXCHANGE_CODES as $code) {
            $codeTagMap[$code][] = '交換所';
        }

        // ------------------------------------------------------------------
        // 7. 交換所: 都市素材（PATH_STONE_RECIPES のソース）
        // ------------------------------------------------------------------
        foreach (self::EXCHANGE_CITY_MATERIAL_IDS as $id) {
            $idTagMap[$id][] = '交換所';
        }

        // ------------------------------------------------------------------
        // まとめて materials テーブルを更新
        // ------------------------------------------------------------------
        $this->applyTagMap($nameTagMap, $codeTagMap, $idTagMap);
    }

    public function down(): void
    {
        // タグを個別除去するのが難しいため、追加したタグを含む素材からそれらを消す
        $removeTags = ['合成', '鍛冶', '交換所'];

        DB::table('materials')
            ->whereNotNull('usage_tags')
            ->get(['id', 'usage_tags'])
            ->each(function ($mat) use ($removeTags) {
                $tags = json_decode($mat->usage_tags, true) ?? [];
                $filtered = array_values(array_filter($tags, fn ($t) => !in_array($t, $removeTags, true)));
                if (count($filtered) !== count($tags)) {
                    DB::table('materials')->where('id', $mat->id)->update([
                        'usage_tags' => empty($filtered) ? null : json_encode($filtered, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    // ------------------------------------------------------------------
    // Helper: 各マップを材料テーブルへ適用
    // ------------------------------------------------------------------
    private function applyTagMap(array $nameTagMap, array $codeTagMap, array $idTagMap): void
    {
        // 名前で一括取得
        if ($nameTagMap) {
            DB::table('materials')
                ->whereIn('name', array_keys($nameTagMap))
                ->get(['id', 'name', 'usage_tags'])
                ->each(function ($mat) use ($nameTagMap) {
                    $this->mergeAndSave($mat, $nameTagMap[$mat->name] ?? []);
                });
        }

        // material_code で一括取得
        if ($codeTagMap) {
            DB::table('materials')
                ->whereIn('material_code', array_keys($codeTagMap))
                ->get(['id', 'material_code', 'usage_tags'])
                ->each(function ($mat) use ($codeTagMap) {
                    $this->mergeAndSave($mat, $codeTagMap[$mat->material_code] ?? []);
                });
        }

        // id で一括取得
        if ($idTagMap) {
            DB::table('materials')
                ->whereIn('id', array_keys($idTagMap))
                ->get(['id', 'usage_tags'])
                ->each(function ($mat) use ($idTagMap) {
                    $this->mergeAndSave($mat, $idTagMap[$mat->id] ?? []);
                });
        }
    }

    private function mergeAndSave(object $mat, array $newTags): void
    {
        if (empty($newTags)) {
            return;
        }
        $existing = json_decode($mat->usage_tags ?? '[]', true) ?? [];
        $merged   = array_values(array_unique(array_merge($existing, $newTags)));
        if ($merged !== $existing) {
            DB::table('materials')->where('id', $mat->id)->update([
                'usage_tags' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
