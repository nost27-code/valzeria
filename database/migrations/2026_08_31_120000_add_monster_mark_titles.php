<?php

use App\Support\MonsterMarkTitleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        $titles = MonsterMarkTitleCatalog::definitions();
        $this->assertTitleIdsAreAvailable($titles);
        $this->assertNaturalKeysAreAvailable($titles);

        $now = now();
        $rows = [];
        foreach ($titles as $id => $title) {
            $rows[] = [
                'id' => $id,
                ...$title,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('titles')->upsert(
            $rows,
            ['id'],
            [
                'category',
                'rarity',
                'name',
                'description',
                'hint',
                'unlock_type',
                'target_type',
                'target_id',
                'source_master',
                'display_order',
                'is_hidden',
                'updated_at',
            ]
        );
    }

    public function down(): void
    {
        // Forward-only: deleting title masters would cascade into player-owned character_titles rows.
    }

    /**
     * @param  array<int, array<string, int|string|bool>>  $titles
     */
    private function assertTitleIdsAreAvailable(array $titles): void
    {
        $existing = DB::table('titles')
            ->whereIn('id', array_keys($titles))
            ->get()
            ->keyBy('id');

        foreach ($titles as $id => $title) {
            $row = $existing->get($id);
            if (! $row) {
                continue;
            }

            foreach ($title as $column => $expected) {
                $actual = $row->{$column};
                $actual = match (true) {
                    is_bool($expected) => (bool) $actual,
                    is_int($expected) => (int) $actual,
                    default => (string) $actual,
                };

                if ($actual !== $expected) {
                    throw new RuntimeException(
                        "Title ID {$id} already exists with a different {$column} value."
                    );
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, int|string|bool>>  $titles
     */
    private function assertNaturalKeysAreAvailable(array $titles): void
    {
        foreach ($titles as $id => $title) {
            $existingIds = DB::table('titles')
                ->where('unlock_type', $title['unlock_type'])
                ->where('target_type', $title['target_type'])
                ->where('target_id', $title['target_id'])
                ->pluck('id')
                ->map(static fn ($existingId): int => (int) $existingId)
                ->unique()
                ->values()
                ->all();
            $unexpectedIds = array_values(array_diff($existingIds, [$id]));

            if ($unexpectedIds !== []) {
                throw new RuntimeException(
                    "Title condition {$title['unlock_type']}/{$title['target_type']}/{$title['target_id']} also uses unexpected IDs ".implode(', ', $unexpectedIds).'.'
                );
            }
        }
    }
};
