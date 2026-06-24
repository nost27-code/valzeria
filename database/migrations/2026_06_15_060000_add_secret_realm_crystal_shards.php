<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        $secretMaterials = DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_SECRET')
            ->orderBy('material_code')
            ->get();

        foreach ($secretMaterials as $secret) {
            $code = str_replace('_SECRET', '_SECRET_SHARD', (string) $secret->material_code);
            $name = str_replace('秘境晶', '秘境晶片', (string) $secret->name);
            if ($name === (string) $secret->name) {
                $name .= '片';
            }

            $payload = [
                'name' => $name,
                'category' => $secret->category ?? '分岐進化素材',
                'rarity' => 'SS',
                'element' => $secret->element ?? null,
                'main_use' => '秘境晶交換',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'city_id' => $secret->city_id ?? null,
                'dungeon_id' => null,
                'source_enemy_id' => null,
                'updated_at' => $now,
            ];

            foreach ([
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => 'secret_realm_gather',
                'material_type' => 'branch_evolution',
                'category_id' => $secret->category_id ?? null,
                'rank_tier' => 4,
                'is_consumable' => true,
                'obtain_method' => 'Phase 2: 秘境採取で入手。5個集めると対応する秘境晶1個へ交換できる。チャンプ戦では入手不可。',
            ] as $column => $value) {
                if (Schema::hasColumn('materials', $column)) {
                    $payload[$column] = $value;
                }
            }

            if (!DB::table('materials')->where('material_code', $code)->exists()) {
                $payload['created_at'] = $now;
            }

            DB::table('materials')->updateOrInsert(['material_code' => $code], $payload);
        }

        if (Schema::hasColumn('materials', 'obtain_method')) {
            $secretPayload = [
                'obtain_method' => 'Phase 2: 秘境採取30%、秘境主勝利で確定入手。チャンプ戦・通常探索・通常ボスでは入手不可。',
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('materials', 'drop_timing')) {
                $secretPayload['drop_timing'] = 'secret_realm';
            }

            DB::table('materials')
                ->where('material_type', 'branch_evolution')
                ->where('material_code', 'like', '%_SECRET')
                ->update($secretPayload);

            DB::table('materials')
                ->where('material_type', 'branch_evolution')
                ->where('material_code', 'like', '%_CREST')
                ->update([
                    'obtain_method' => 'Phase 3予定: 極印試練・極印片交換で入手。現時点ではチャンプ戦・通常探索では入手不可。',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_SECRET_SHARD')
            ->delete();
    }
};
