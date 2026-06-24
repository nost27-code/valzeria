<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CITY_NAMES = [
        1 => ['王都アークレア', '王都アークレア'],
        2 => ['港町マリネス', '港町マリネス'],
        3 => ['精霊都市エルフィア', '精霊の森エルフィア'],
        4 => ['鉄鋼都市グランベルグ', '鍛冶街グランベルグ'],
        5 => ['雪都フロストリア', '雪原の町フロストリア'],
        6 => ['砂都サンドラ', '砂漠の宿場サンドラ'],
        7 => ['魔導都市ルミナス', '魔導学院ルミナス'],
        8 => ['魔界都市ネクロム', '死霊街ネクロム'],
        9 => ['天空都市セレスティア', '天空神殿セレスティア'],
        10 => ['魔王城ヴァルゼリア', '魔王城ヴァルゼリア'],
    ];

    private const CITY_DESCRIPTIONS = [
        3 => '深い森の奥に広がる幻想的な拠点。魔法や精霊の力に満ちている。',
        5 => '雪原に寄り添う寒冷地の中継町。氷雪に慣れた屈強なモンスターが徘徊する。',
        6 => '広大な砂漠のオアシスに築かれた旅の宿場。周囲には未知の遺跡が多く眠っている。',
        7 => '魔法技術と知識が集まる学術拠点。高度な魔法を操る敵が待ち受ける。',
        8 => '魔界にほど近い、瘴気に包まれた不穏な街。アンデッドや高位魔族の巣窟。',
        9 => '雲の上の浮遊島に築かれた神秘の神殿。天界の使いや聖なる獣が立ちはだかる。',
    ];

    private const TEXT_TABLES = [
        'areas',
        'cities',
        'items',
        'materials',
        'titles',
        'recipes',
        'weapon_evolution_city_materials',
        'armor_evolution_city_materials',
        'weapon_evolution_recipes',
        'armor_evolution_recipes',
        'accessory_evolution_recipes',
        'branch_evolution_recipes',
        'branch_materials',
        'npc_masters',
        'npc_procurement_requests',
        'npc_procurement_request_templates',
        'top_updates',
        'public_logs',
    ];

    private const TEXT_COLUMNS = [
        'name',
        'title',
        'description',
        'body',
        'message',
        'content',
        'city_name',
        'primary_city_name',
        'location',
        'unlock_hint',
        'usage_summary',
        'acquisition_summary',
        'usage_tags',
        'acquisition_tags',
        'market_hint',
        'obtain_method',
        'main_use',
        'notes',
        'drop_policy',
    ];

    public function up(): void
    {
        $this->renameCities(false);
        $this->replaceText(false);
    }

    public function down(): void
    {
        $this->replaceText(true);
        $this->renameCities(true);
    }

    private function renameCities(bool $reverse): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }

        foreach (self::CITY_NAMES as $sortOrder => [$old, $new]) {
            $from = $reverse ? $new : $old;
            $to = $reverse ? $old : $new;

            DB::table('cities')
                ->where('sort_order', $sortOrder * 10)
                ->update([
                    'name' => $to,
                    'updated_at' => now(),
                ]);

            DB::table('cities')
                ->where('name', $from)
                ->update([
                    'name' => $to,
                    'updated_at' => now(),
                ]);
        }

        if (! $reverse) {
            foreach (self::CITY_DESCRIPTIONS as $sortOrder => $description) {
                DB::table('cities')
                    ->where('sort_order', $sortOrder * 10)
                    ->update([
                        'description' => $description,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function replaceText(bool $reverse): void
    {
        foreach (self::TEXT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_intersect(self::TEXT_COLUMNS, Schema::getColumnListing($table));

            foreach ($columns as $column) {
                foreach (self::CITY_NAMES as [$old, $new]) {
                    if ($old === $new) {
                        continue;
                    }

                    $from = $reverse ? $new : $old;
                    $to = $reverse ? $old : $new;
                    $wrappedColumn = $this->wrap($column);

                    DB::table($table)
                        ->where($column, 'like', '%' . $from . '%')
                        ->update([
                            $column => DB::raw(
                                'REPLACE(' . $wrappedColumn . ', ' . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')'
                            ),
                        ]);
                }
            }
        }
    }

    private function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
