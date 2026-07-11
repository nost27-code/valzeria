<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('areas') || !Schema::hasTable('enemies')) {
            return;
        }

        DB::table('areas')
            ->where('id', 1008)
            ->update(['name' => 'メイア河畔道']);

        $this->renameEnemies([
            '平原の疾風狼' => '河畔の疾風狼',
            '大平原の土塊' => '河畔の土塊',
            '平原のキラービー' => '河畔のキラービー',
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('areas') || !Schema::hasTable('enemies')) {
            return;
        }

        DB::table('areas')
            ->where('id', 1008)
            ->update(['name' => 'メイア平原']);

        $this->renameEnemies([
            '河畔の疾風狼' => '平原の疾風狼',
            '河畔の土塊' => '大平原の土塊',
            '河畔のキラービー' => '平原のキラービー',
        ]);
    }

    /** @param array<string, string> $renames */
    private function renameEnemies(array $renames): void
    {
        foreach ($renames as $from => $to) {
            DB::table('enemies')
                ->where('area_id', 1008)
                ->where('name', $from)
                ->update(['name' => $to]);
        }
    }
};
