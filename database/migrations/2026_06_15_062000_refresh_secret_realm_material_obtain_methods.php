<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasColumn('materials', 'obtain_method')) {
            return;
        }

        $now = now();

        DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_SECRET')
            ->update([
                'obtain_method' => 'Phase 2: 秘境採取30%、秘境主勝利で確定入手。チャンプ戦・通常探索・通常ボスでは入手不可。',
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_SECRET_SHARD')
            ->update([
                'obtain_method' => 'Phase 2: 秘境採取で入手。5個集めると対応する秘境晶1個へ交換できる。チャンプ戦では入手不可。',
                'updated_at' => $now,
            ]);

        DB::table('materials')
            ->where('material_type', 'branch_evolution')
            ->where('material_code', 'like', '%_CREST')
            ->update([
                'obtain_method' => 'Phase 3予定: 極印試練・極印片交換で入手。現時点ではチャンプ戦・通常探索では入手不可。',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Master text refresh only.
    }
};
