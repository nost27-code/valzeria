<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops') || !Schema::hasTable('enemies')) {
            return;
        }

        DB::transaction(function (): void {
            $drops = DB::table('material_drops as drop')
                ->join('materials as material', 'material.id', '=', 'drop.material_id')
                ->join('enemies as enemy', 'enemy.id', '=', 'drop.enemy_id')
                ->where('material.material_type', 'branch_evolution')
                ->where('material.material_code', 'like', '%_ANCIENT')
                ->select('drop.id', 'enemy.role')
                ->get();

            foreach ($drops as $drop) {
                $rate = $this->dropRateForRole((string) ($drop->role ?? ''));

                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update([
                        'drop_rate' => $rate,
                        'is_active' => $rate > 0,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Balance-data migration only. Previous ancient-fragment rates are not restored.
    }

    private function dropRateForRole(string $role): int
    {
        if (str_contains($role, '最深部候補')) {
            return 3;
        }

        if (str_contains($role, 'レア敵')) {
            return 1;
        }

        return 0;
    }
};
