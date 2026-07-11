<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_exploration_support_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('battles_remaining')->default(30);
            $table->unsignedTinyInteger('battles_elapsed_in_period')->default(0);
            $table->unsignedTinyInteger('proc_count')->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->foreignId('last_battle_log_id')->nullable()->constrained('battle_logs')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['name' => '薬屋のお守り', 'description' => '30戦有効。5戦ごとの戦闘後、最大HPの10%を回復する。', 'sort_order' => 91],
            ['name' => '守りの香', 'description' => '30戦有効。敵から受ける直接ダメージを8%軽減する。', 'sort_order' => 92],
            ['name' => '冒険者の救急包', 'description' => '30戦有効。火傷・毒・出血への備えになる。', 'sort_order' => 93],
            ['name' => '薬屋の特製漢方', 'description' => '30戦有効。瀕死時に最大HPの20%を回復する。', 'sort_order' => 94],
        ] as $item) {
            DB::table('items')->updateOrInsert(['name' => $item['name'], 'type' => 'consumable'], array_merge($item, [
                'rarity' => 'R', 'price' => 0, 'sell_price' => 0, 'hp_bonus' => 0, 'mp_bonus' => 0,
                'str_bonus' => 0, 'def_bonus' => 0, 'agi_bonus' => 0, 'mag_bonus' => 0, 'spr_bonus' => 0, 'luk_bonus' => 0,
                'required_level' => 1, 'is_shop_item' => false, 'is_active' => true, 'sub_type' => '探索補助品', 'element' => null,
                'updated_at' => $now, 'created_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_exploration_support_effects');
        DB::table('items')->where('type', 'consumable')->whereIn('name', ['薬屋のお守り', '守りの香', '冒険者の救急包', '薬屋の特製漢方'])->delete();
    }
};
