<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXISTING_ITEMS = [
        '薬屋のお守り' => '50戦有効。5戦ごとの戦闘後、最大HPの10%を回復する。',
        '守りの香' => '50戦有効。敵から受ける直接ダメージを8%軽減する。',
        '冒険者の救急包' => '50戦有効。火傷・毒・出血への備えになる。',
        '薬屋の特製漢方' => '50戦有効。瀕死時に最大HPの20%を回復する。',
    ];

    private const LURE_ITEMS = [
        ['name' => '誘魔香〈獣〉', 'species' => '獣', 'sort_order' => 95],
        ['name' => '誘魔香〈不死〉', 'species' => '不死', 'sort_order' => 96],
        ['name' => '誘魔香〈竜〉', 'species' => '竜', 'sort_order' => 97],
        ['name' => '誘魔香〈悪魔〉', 'species' => '悪魔', 'sort_order' => 98],
        ['name' => '誘魔香〈水棲〉', 'species' => '水棲', 'sort_order' => 99],
        ['name' => '誘魔香〈飛行〉', 'species' => '飛行', 'sort_order' => 100],
        ['name' => '誘魔香〈虫〉', 'species' => '虫', 'sort_order' => 101],
        ['name' => '誘魔香〈機械〉', 'species' => '機械', 'sort_order' => 102],
        ['name' => '誘魔香〈スライム〉', 'species' => 'スライム', 'sort_order' => 103],
        ['name' => '誘魔香〈人型〉', 'species' => '人型', 'sort_order' => 104],
        ['name' => '誘魔香〈魔法型〉', 'species' => '魔法型', 'sort_order' => 105],
        ['name' => '誘魔香〈精霊〉', 'species' => '精霊', 'sort_order' => 106],
    ];

    public function up(): void
    {
        Schema::create('player_exploration_support_item_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('battles_remaining')->default(50);
            $table->unsignedSmallInteger('battles_elapsed_in_period')->default(0);
            $table->unsignedSmallInteger('proc_count')->default(0);
            $table->foreignId('last_battle_log_id')->nullable();
            $table->foreign('last_battle_log_id', 'explore_support_state_battle_log_fk')
                ->references('id')
                ->on('battle_logs')
                ->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'item_id'], 'exploration_support_item_state_unique');
        });

        DB::table('player_exploration_support_effects')
            ->orderBy('id')
            ->each(function (object $effect): void {
                DB::table('player_exploration_support_item_states')->updateOrInsert(
                    [
                        'character_id' => $effect->character_id,
                        'item_id' => $effect->item_id,
                    ],
                    [
                        'battles_remaining' => $effect->battles_remaining,
                        'battles_elapsed_in_period' => $effect->battles_elapsed_in_period,
                        'proc_count' => $effect->proc_count,
                        'last_battle_log_id' => $effect->last_battle_log_id,
                        'lock_version' => $effect->lock_version,
                        'created_at' => $effect->created_at,
                        'updated_at' => $effect->updated_at,
                    ],
                );
            });

        $now = now();
        foreach (self::EXISTING_ITEMS as $name => $description) {
            DB::table('items')
                ->where('name', $name)
                ->where('type', 'consumable')
                ->update(['description' => $description, 'updated_at' => $now]);
        }

        foreach (self::LURE_ITEMS as $item) {
            DB::table('items')->updateOrInsert(
                ['name' => $item['name'], 'type' => 'consumable'],
                [
                    'description' => "50戦有効。通常探索で{$item['species']}系の敵の出現しやすさが3倍になる。",
                    'rarity' => 'R',
                    'price' => 0,
                    'sell_price' => 0,
                    'hp_bonus' => 0,
                    'mp_bonus' => 0,
                    'str_bonus' => 0,
                    'def_bonus' => 0,
                    'agi_bonus' => 0,
                    'mag_bonus' => 0,
                    'spr_bonus' => 0,
                    'luk_bonus' => 0,
                    'required_level' => 1,
                    'is_shop_item' => false,
                    'is_active' => true,
                    'sort_order' => $item['sort_order'],
                    'sub_type' => '探索補助品',
                    'element' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        $lureItemIds = DB::table('items')
            ->where('type', 'consumable')
            ->whereIn('name', array_column(self::LURE_ITEMS, 'name'))
            ->pluck('id');
        $hasSupportState = Schema::hasTable('player_exploration_support_item_states')
            && DB::table('player_exploration_support_item_states')->exists();
        $hasOwnedLure = $lureItemIds->isNotEmpty()
            && DB::table('character_items')->whereIn('item_id', $lureItemIds)->exists();
        if ($hasSupportState || $hasOwnedLure) {
            throw new RuntimeException('探索補助品の品目別残数または誘魔香の所持データがあるため、安全のためロールバックを中止しました。');
        }

        Schema::dropIfExists('player_exploration_support_item_states');

        DB::table('items')
            ->where('type', 'consumable')
            ->whereIn('name', array_column(self::LURE_ITEMS, 'name'))
            ->delete();

        $descriptions = [
            '薬屋のお守り' => '30戦有効。5戦ごとの戦闘後、最大HPの10%を回復する。',
            '守りの香' => '30戦有効。敵から受ける直接ダメージを8%軽減する。',
            '冒険者の救急包' => '30戦有効。火傷・毒・出血への備えになる。',
            '薬屋の特製漢方' => '30戦有効。瀕死時に最大HPの20%を回復する。',
        ];
        foreach ($descriptions as $name => $description) {
            DB::table('items')
                ->where('name', $name)
                ->where('type', 'consumable')
                ->update(['description' => $description, 'updated_at' => now()]);
        }
    }
};
