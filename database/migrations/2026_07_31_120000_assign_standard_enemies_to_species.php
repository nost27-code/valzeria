<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 「通常」のまま残っていた敵へ、特攻・耐性用の種族を割り当てる。
     */
    public function up(): void
    {
        if (! Schema::hasTable('enemies') || ! Schema::hasColumn('enemies', 'species_key')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (config('enemy_species.assignments', []) as $enemyId => $assignment) {
                DB::table('enemies')
                    ->where('id', (int) $enemyId)
                    ->where('name', (string) ($assignment['name'] ?? ''))
                    ->where(function ($query): void {
                        $query
                            ->whereNull('species_key')
                            ->orWhere('species_key', '')
                            ->orWhere('species_key', 'standard');
                    })
                    ->update([
                        'species_key' => (string) ($assignment['species_key'] ?? ''),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * この移行で割り当てた値だけを従来の状態へ戻す。
     */
    public function down(): void
    {
        if (! Schema::hasTable('enemies') || ! Schema::hasColumn('enemies', 'species_key')) {
            return;
        }

        DB::transaction(function (): void {
            foreach (config('enemy_species.assignments', []) as $enemyId => $assignment) {
                DB::table('enemies')
                    ->where('id', (int) $enemyId)
                    ->where('name', (string) ($assignment['name'] ?? ''))
                    ->where('species_key', (string) ($assignment['species_key'] ?? ''))
                    ->update([
                        'species_key' => 'standard',
                        'updated_at' => now(),
                    ]);
            }
        });
    }
};
