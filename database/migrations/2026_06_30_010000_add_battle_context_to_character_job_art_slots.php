<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_job_art_slots')) {
            return;
        }

        if (!Schema::hasColumn('character_job_art_slots', 'battle_context')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->string('battle_context', 20)
                    ->default('normal')
                    ->after('character_id')
                    ->comment('奥義セット種別: normal / boss');
            });
        }

        DB::table('character_job_art_slots')
            ->whereNull('battle_context')
            ->orWhere('battle_context', '')
            ->update(['battle_context' => 'normal']);

        $this->dropForeignKeyForColumnIfExists('character_job_art_slots', 'character_id');
        $this->dropForeignKeyForColumnIfExists('character_job_art_slots', 'skill_id');

        $this->addIndexIfMissing('character_job_art_slots', 'character_id', 'character_job_art_slots_character_id_index');
        $this->addIndexIfMissing('character_job_art_slots', 'skill_id', 'character_job_art_slots_skill_id_index');

        $this->dropIndexIfExists('character_job_art_slots', 'character_job_art_slots_character_id_slot_no_unique');
        $this->dropIndexIfExists('character_job_art_slots', 'character_job_art_slots_character_id_skill_id_unique');
        $this->addForeignKeyIfMissing('character_job_art_slots', 'character_id', 'characters', 'id', 'character_job_art_slots_character_id_foreign');
        $this->addForeignKeyIfMissing('character_job_art_slots', 'skill_id', 'skills', 'id', 'character_job_art_slots_skill_id_foreign');

        $existingSlots = DB::table('character_job_art_slots')
            ->where('battle_context', 'normal')
            ->orderBy('id')
            ->get();

        foreach ($existingSlots as $slot) {
            $exists = DB::table('character_job_art_slots')
                ->where('character_id', $slot->character_id)
                ->where('battle_context', 'boss')
                ->where('slot_no', $slot->slot_no)
                ->exists();

            if (!$exists) {
                DB::table('character_job_art_slots')->insert([
                    'character_id' => $slot->character_id,
                    'battle_context' => 'boss',
                    'slot_no' => $slot->slot_no,
                    'skill_id' => $slot->skill_id,
                    'created_at' => $slot->created_at,
                    'updated_at' => $slot->updated_at,
                ]);
            }
        }

        if (!$this->indexExists('character_job_art_slots', 'character_job_art_slots_context_slot_unique')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->unique(['character_id', 'battle_context', 'slot_no'], 'character_job_art_slots_context_slot_unique');
            });
        }

        if (!$this->indexExists('character_job_art_slots', 'character_job_art_slots_context_skill_unique')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->unique(['character_id', 'battle_context', 'skill_id'], 'character_job_art_slots_context_skill_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('character_job_art_slots')) {
            return;
        }

        $this->dropIndexIfExists('character_job_art_slots', 'character_job_art_slots_context_slot_unique');
        $this->dropIndexIfExists('character_job_art_slots', 'character_job_art_slots_context_skill_unique');

        DB::table('character_job_art_slots')
            ->where('battle_context', 'boss')
            ->delete();

        if (Schema::hasColumn('character_job_art_slots', 'battle_context')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->dropColumn('battle_context');
                $table->unique(['character_id', 'slot_no']);
                $table->unique(['character_id', 'skill_id']);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!$this->indexExists($table, $index)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($index) {
                $tableBlueprint->dropIndex($index);
            });
        } catch (Throwable) {
            // Already absent or unsupported on this connection.
        }
    }

    private function addIndexIfMissing(string $table, string $column, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($column, $index) {
                $tableBlueprint->index($column, $index);
            });
        } catch (Throwable) {
            // Already present under another index name or unsupported on this connection.
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn (array $candidate): bool => ($candidate['name'] ?? null) === $index);
        } catch (Throwable) {
            return false;
        }
    }

    private function dropForeignKeyForColumnIfExists(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'mysql') {
            try {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($column) {
                    $tableBlueprint->dropForeign([$column]);
                });
            } catch (Throwable) {
                // Already absent.
            }
            return;
        }

        foreach ([$table . '_' . $column . '_foreign'] as $knownConstraint) {
            try {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $knownConstraint));
            } catch (Throwable) {
                // Different constraint name or already absent.
            }
        }

        $constraints = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->pluck('constraint_name')
            ->unique();

        foreach ($constraints as $constraint) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraint));
        }
    }

    private function addForeignKeyIfMissing(string $table, string $column, string $referencedTable, string $referencedColumn, string $constraint): void
    {
        if (DB::getDriverName() !== 'mysql') {
            try {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($column, $referencedTable, $referencedColumn) {
                    $tableBlueprint->foreign($column)->references($referencedColumn)->on($referencedTable)->cascadeOnDelete();
                });
            } catch (Throwable) {
                // Already present or unsupported on this connection.
            }
            return;
        }

        $exists = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->exists();

        if (!$exists) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`%s`) ON DELETE CASCADE',
                $table,
                $constraint,
                $column,
                $referencedTable,
                $referencedColumn
            ));
        }
    }
};
