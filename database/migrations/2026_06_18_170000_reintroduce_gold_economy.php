<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gold_transactions')) {
            Schema::create('gold_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->integer('amount');
                $table->integer('balance_after')->default(0);
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['character_id', 'created_at']);
                $table->index('type');
            });
        }

        if (Schema::hasTable('items')
            && Schema::hasColumn('items', 'sell_price')
            && Schema::hasColumn('items', 'weapon_rank')
            && Schema::hasColumn('items', 'armor_rank')
            && Schema::hasColumn('items', 'accessory_rank')) {
            foreach (config('gold.equipment_sell_prices', []) as $rank => $price) {
                DB::table('items')
                    ->where(function ($query) use ($rank) {
                        $query->where('weapon_rank', $rank)
                            ->orWhere('armor_rank', $rank)
                            ->orWhere('accessory_rank', $rank);
                    })
                    ->update(['sell_price' => (int) $price, 'updated_at' => now()]);
            }
        }

        $this->updateMaterialSalePrices();
        $this->ensureTreasureMaterials();
        $this->attachTreasureDrops();
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_transactions');
    }

    private function updateMaterialSalePrices(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $protectedTypes = [
            'equipment_common',
            'weapon_common',
            'weapon_category',
            'weapon_city',
            'weapon_city_high',
            'weapon_unlock_key',
            'armor_common',
            'armor_category',
            'city_material',
            'accessory_evolution',
            'accessory_city',
            'accessory_city_high',
            'branch_evolution',
            'evolution_stone',
            'exchange_ticket',
            'brewing',
        ];

        DB::table('materials')
            ->where(function ($query) use ($protectedTypes) {
                $query->whereIn('material_type', $protectedTypes)
                    ->orWhere('category', 'like', '%討伐証%')
                    ->orWhere('category', 'like', '%刻印%')
                    ->orWhere('main_use', 'like', '%レシピ解放%')
                    ->orWhere('name', 'like', '%刻印')
                    ->orWhere('name', 'like', '%王印')
                    ->orWhere('name', 'like', '%神印');
            })
            ->update(['npc_sale_price' => 0, 'updated_at' => now()]);

        $rarityPrices = [
            'N' => 10,
            'N+' => 20,
            'R' => 30,
            'R+' => 50,
            'SR' => 60,
            'SR+' => 80,
            'SSR' => 100,
        ];

        foreach ($rarityPrices as $rarity => $price) {
            DB::table('materials')
                ->where('rarity', $rarity)
                ->where(function ($query) use ($protectedTypes) {
                    $query->whereNull('material_type')
                        ->orWhereNotIn('material_type', $protectedTypes);
                })
                ->where('category', 'not like', '%討伐証%')
                ->where('category', 'not like', '%刻印%')
                ->where('name', 'not like', '%刻印')
                ->where('name', 'not like', '%王印')
                ->where('name', 'not like', '%神印')
                ->update(['npc_sale_price' => $price, 'updated_at' => now()]);
        }
    }

    private function ensureTreasureMaterials(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        $materials = [
            ['MAT_TREASURE_CHIPPED_MAGIC_STONE', '欠けた魔石', 'N+', 80],
            ['MAT_TREASURE_MONSTER_FANG', '魔物の牙', 'R', 120],
            ['MAT_TREASURE_OLD_SILVER_COIN', '古びた銀貨', 'R+', 250],
            ['MAT_TREASURE_SPIRIT_FEATHER', '精霊の羽根', 'SR', 500],
            ['MAT_TREASURE_DRAGON_SCALE', '竜の鱗片', 'SR+', 1000],
            ['MAT_TREASURE_BEAST_HORN', '魔獣の角', 'SSR', 2000],
            ['MAT_TREASURE_ANCIENT_GOLD_COIN', '古代の金貨', 'SSR', 5000],
        ];

        foreach ($materials as [$code, $name, $rarity, $price]) {
            $payload = [
                'name' => $name,
                'category' => '換金品',
                'rarity' => $rarity,
                'element' => null,
                'main_use' => '売却してGoldに換える',
                'npc_sale_price' => $price,
                'is_tradable' => false,
                'city_id' => null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'updated_at' => $now,
            ];

            foreach ([
                'material_type' => 'sell_treasure',
                'category_id' => 'sell_treasure',
                'rank_tier' => 1,
                'is_consumable' => false,
                'obtain_method' => '探索中にまれに発見。倉庫で売却できます。',
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                array_merge($payload, ['created_at' => $now])
            );
        }
    }

    private function attachTreasureDrops(): void
    {
        if (!Schema::hasTable('material_drops') || !Schema::hasTable('enemies') || !Schema::hasTable('materials')) {
            return;
        }

        $treasures = DB::table('materials')
            ->whereIn('material_code', [
                'MAT_TREASURE_CHIPPED_MAGIC_STONE',
                'MAT_TREASURE_MONSTER_FANG',
                'MAT_TREASURE_OLD_SILVER_COIN',
                'MAT_TREASURE_SPIRIT_FEATHER',
                'MAT_TREASURE_DRAGON_SCALE',
                'MAT_TREASURE_BEAST_HORN',
                'MAT_TREASURE_ANCIENT_GOLD_COIN',
            ])
            ->pluck('id', 'material_code');

        if ($treasures->isEmpty()) {
            return;
        }

        $now = now();
        $enemies = DB::table('enemies')
            ->select('id', 'level', 'is_boss')
            ->orderBy('id')
            ->get();

        foreach ($enemies as $enemy) {
            $level = (int) ($enemy->level ?? 1);
            $code = match (true) {
                (bool) $enemy->is_boss && $level >= 180 => 'MAT_TREASURE_ANCIENT_GOLD_COIN',
                (bool) $enemy->is_boss && $level >= 120 => 'MAT_TREASURE_BEAST_HORN',
                (bool) $enemy->is_boss && $level >= 70 => 'MAT_TREASURE_DRAGON_SCALE',
                $level >= 120 => 'MAT_TREASURE_SPIRIT_FEATHER',
                $level >= 70 => 'MAT_TREASURE_OLD_SILVER_COIN',
                $level >= 25 => 'MAT_TREASURE_MONSTER_FANG',
                default => 'MAT_TREASURE_CHIPPED_MAGIC_STONE',
            };

            $materialId = $treasures[$code] ?? null;
            if (!$materialId) {
                continue;
            }

            DB::table('material_drops')->updateOrInsert(
                ['enemy_id' => $enemy->id, 'material_id' => $materialId],
                [
                    'drop_rate' => (bool) $enemy->is_boss ? 1.20 : 0.35,
                    'drop_first_clear_only' => false,
                    'drop_timing' => 'normal',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
