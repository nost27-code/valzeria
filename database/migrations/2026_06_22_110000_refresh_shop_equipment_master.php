<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        $path = database_path('data/shop_equipment_master.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        $columns = Schema::getColumnListing('items');
        $now = now();

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['external_item_id'])) {
                continue;
            }

            $payload = [];
            foreach ($row as $key => $value) {
                if (!in_array($key, $columns, true)) {
                    continue;
                }

                $payload[$key] = $value;
            }

            foreach ([
                'is_shop_item',
                'is_active',
                'is_evolution_enabled',
                'is_drop_enabled',
                'is_supply_enabled',
            ] as $booleanColumn) {
                if (array_key_exists($booleanColumn, $payload)) {
                    $payload[$booleanColumn] = (bool) $payload[$booleanColumn];
                }
            }

            foreach ([
                'price',
                'sell_price',
                'hp_bonus',
                'mp_bonus',
                'str_bonus',
                'def_bonus',
                'agi_bonus',
                'mag_bonus',
                'spr_bonus',
                'luk_bonus',
                'required_level',
                'sort_order',
                'unlock_city_id',
                'weapon_rank_sort',
                'armor_rank_sort',
                'accessory_rank_sort',
                'evolution_stage',
                'max_enhance',
            ] as $integerColumn) {
                if (array_key_exists($integerColumn, $payload) && $payload[$integerColumn] !== null) {
                    $payload[$integerColumn] = (int) $payload[$integerColumn];
                }
            }

            if (in_array('created_at', $columns, true)) {
                $payload['created_at'] = $now;
            }
            if (in_array('updated_at', $columns, true)) {
                $payload['updated_at'] = $now;
            }

            DB::table('items')->updateOrInsert(
                ['external_item_id' => (string) $row['external_item_id']],
                $payload
            );
        }
    }

    public function down(): void
    {
        // Master refresh only. Do not delete shop equipment when rolling this back.
    }
};
