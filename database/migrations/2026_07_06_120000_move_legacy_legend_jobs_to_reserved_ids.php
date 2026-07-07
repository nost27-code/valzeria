<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ID_MAP = [
        39 => 95,
        40 => 96,
        41 => 97,
        42 => 98,
        43 => 99,
    ];

    private const REFERENCE_COLUMNS = [
        'characters' => ['current_job_id'],
        'character_jobs' => ['job_class_id'],
        'job_change_logs' => ['from_job_id', 'to_job_id'],
        'job_requirements' => ['job_id', 'required_job_id'],
        'job_weapon_permissions' => ['job_id'],
        'job_armor_permissions' => ['job_id'],
        'job_master_bonuses' => ['job_id'],
        'skills' => ['job_id'],
    ];

    public function up(): void
    {
        $this->moveIds(self::ID_MAP);
    }

    public function down(): void
    {
        $this->moveIds(array_flip(self::ID_MAP));
    }

    private function moveIds(array $idMap): void
    {
        if (! Schema::hasTable('job_classes')) {
            return;
        }

        $sourceIds = array_keys($idMap);
        $targetIds = array_values($idMap);

        $existingSources = DB::table('job_classes')
            ->whereIn('id', $sourceIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($existingSources === []) {
            return;
        }

        $conflictingTargets = DB::table('job_classes')
            ->whereIn('id', $targetIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($conflictingTargets !== []) {
            throw new RuntimeException('Cannot move legend job IDs because target IDs already exist: ' . implode(', ', $conflictingTargets));
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($idMap): void {
                $this->updateReferenceColumns($idMap);

                foreach ($idMap as $fromId => $toId) {
                    DB::table('job_classes')
                        ->where('id', $fromId)
                        ->update([
                            'id' => $toId,
                            'sort_order' => $toId * 10,
                            'updated_at' => now(),
                        ]);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function updateReferenceColumns(array $idMap): void
    {
        foreach (self::REFERENCE_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                foreach ($idMap as $fromId => $toId) {
                    DB::table($table)
                        ->where($column, $fromId)
                        ->update([$column => $toId]);
                }
            }
        }
    }
};
