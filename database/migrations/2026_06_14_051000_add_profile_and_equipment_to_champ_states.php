<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('champ_states')) {
            return;
        }

        Schema::table('champ_states', function (Blueprint $table) {
            if (!Schema::hasColumn('champ_states', 'icon_path')) {
                $table->string('icon_path')->nullable()->after('player_name');
            }
            if (!Schema::hasColumn('champ_states', 'weapon_name')) {
                $table->string('weapon_name')->nullable()->after('luk');
            }
            if (!Schema::hasColumn('champ_states', 'armor_name')) {
                $table->string('armor_name')->nullable()->after('weapon_name');
            }
            if (!Schema::hasColumn('champ_states', 'accessory_name')) {
                $table->string('accessory_name')->nullable()->after('armor_name');
            }
        });

        DB::table('champ_states')
            ->whereNull('icon_path')
            ->update(['icon_path' => '/images/chara/chara_001.webp']);

        $champ = DB::table('champ_states')->first();
        if (!$champ || !$champ->character_id) {
            DB::table('champ_states')
                ->whereNull('weapon_name')
                ->update([
                    'weapon_name' => '訓練用の剣',
                    'armor_name' => '協会の軽鎧',
                    'accessory_name' => '試練官の徽章',
                ]);
            return;
        }

        $character = DB::table('characters')->where('id', $champ->character_id)->first();
        if (!$character) {
            return;
        }

        $equipment = DB::table('character_items')
            ->join('items', 'character_items.item_id', '=', 'items.id')
            ->where('character_items.character_id', $character->id)
            ->where('character_items.is_equipped', true)
            ->select('character_items.equipped_slot', 'items.name')
            ->get()
            ->keyBy('equipped_slot');

        DB::table('champ_states')->where('id', $champ->id)->update([
            'icon_path' => $character->icon_path ?: '/images/chara/chara_001.webp',
            'weapon_name' => optional($equipment->get('weapon'))->name,
            'armor_name' => optional($equipment->get('armor'))->name,
            'accessory_name' => optional($equipment->get('accessory'))->name,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('champ_states')) {
            return;
        }

        Schema::table('champ_states', function (Blueprint $table) {
            foreach (['icon_path', 'weapon_name', 'armor_name', 'accessory_name'] as $column) {
                if (Schema::hasColumn('champ_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
