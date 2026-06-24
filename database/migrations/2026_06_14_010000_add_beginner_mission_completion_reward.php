<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REWARD_EXTERNAL_ID = 'BEGINNER_MISSION_PROOF';

    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (!Schema::hasColumn('characters', 'beginner_mission_completed_keys')) {
                    $table->json('beginner_mission_completed_keys')->nullable()->after('bonus_points');
                }

                if (!Schema::hasColumn('characters', 'beginner_mission_reward_claimed')) {
                    $table->boolean('beginner_mission_reward_claimed')->default(false)->after('beginner_mission_completed_keys');
                }
            });
        }

        if (!Schema::hasTable('items')) {
            return;
        }

        $item = [
            'name' => '初心者の証',
            'type' => 'accessory',
            'description' => '初心者ミッションをすべて達成した冒険者に贈られる証。すべての能力をほんの少し高める。',
            'rarity' => 'rare',
            'price' => 0,
            'hp_bonus' => 1,
            'mp_bonus' => 1,
            'str_bonus' => 1,
            'def_bonus' => 1,
            'agi_bonus' => 1,
            'mag_bonus' => 1,
            'spr_bonus' => 1,
            'luk_bonus' => 1,
            'required_level' => 1,
            'is_shop_item' => false,
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => now(),
        ];

        foreach ([
            'external_item_id' => self::REWARD_EXTERNAL_ID,
            'sell_price' => 0,
            'sub_type' => '記念品',
            'is_evolution_enabled' => false,
            'is_drop_enabled' => false,
            'is_supply_enabled' => false,
            'max_enhance' => 0,
            'accessory_family_id' => 'BEGINNER_PROOF',
            'accessory_family_name' => '初心者の証系',
            'accessory_category_id' => 'beginner',
            'accessory_category_name' => '初心者',
            'accessory_rank' => 'G',
            'accessory_rank_sort' => 1,
            'accessory_rank_multiplier' => 1,
        ] as $column => $value) {
            if (Schema::hasColumn('items', $column)) {
                $item[$column] = $value;
            }
        }

        if (Schema::hasColumn('items', 'external_item_id')) {
            DB::table('items')->updateOrInsert(
                ['external_item_id' => self::REWARD_EXTERNAL_ID],
                $item + ['created_at' => now()]
            );
        } else {
            DB::table('items')->updateOrInsert(
                ['name' => '初心者の証', 'type' => 'accessory'],
                $item + ['created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('items')) {
            if (Schema::hasColumn('items', 'external_item_id')) {
                DB::table('items')
                    ->where('external_item_id', self::REWARD_EXTERNAL_ID)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            } else {
                DB::table('items')
                    ->where('name', '初心者の証')
                    ->where('type', 'accessory')
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (Schema::hasColumn('characters', 'beginner_mission_reward_claimed')) {
                    $table->dropColumn('beginner_mission_reward_claimed');
                }

                if (Schema::hasColumn('characters', 'beginner_mission_completed_keys')) {
                    $table->dropColumn('beginner_mission_completed_keys');
                }
            });
        }
    }
};
