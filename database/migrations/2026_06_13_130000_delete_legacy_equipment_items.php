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

        DB::transaction(function () {
            DB::table('items')
                ->where(function ($query) {
                    $query
                        ->where(function ($weapon) {
                            $weapon->where('type', 'weapon');
                            if (Schema::hasColumn('items', 'weapon_family_id')) {
                                $weapon->whereNull('weapon_family_id');
                            } else {
                                $weapon->whereRaw('1 = 1');
                            }
                        })
                        ->orWhere(function ($armor) {
                            $armor->where('type', 'armor');
                            if (Schema::hasColumn('items', 'armor_family_id')) {
                                $armor->whereNull('armor_family_id');
                            } else {
                                $armor->whereRaw('1 = 1');
                            }
                        })
                        ->orWhere(function ($accessory) {
                            $accessory->where('type', 'accessory');
                            if (Schema::hasColumn('items', 'accessory_family_id')) {
                                $accessory->whereNull('accessory_family_id');
                            } else {
                                $accessory->whereRaw('1 = 1');
                            }
                        });
                })
                ->delete();
        });
    }

    public function down(): void
    {
        // 旧装備は現行の合成・育成マスタ外のデータなので、rollbackでは復元しません。
    }
};
