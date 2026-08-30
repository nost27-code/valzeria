<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'character_titles_character_title_unique';

    public function up(): void
    {
        if (! Schema::hasTable('character_titles') || $this->hasCharacterTitleUniqueIndex()) {
            return;
        }

        $duplicatePairs = DB::query()
            ->fromSub(
                DB::table('character_titles')
                    ->selectRaw('character_id, title_id, COUNT(*) AS total')
                    ->groupBy('character_id', 'title_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_character_titles',
            )
            ->count();

        if ($duplicatePairs !== 0) {
            throw new RuntimeException(
                "character_titles に既存重複が {$duplicatePairs} 組あります。プレイヤー所持データを自動削除せず移行を中止します。"
            );
        }

        Schema::table('character_titles', function (Blueprint $table): void {
            $table->unique(['character_id', 'title_id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        // Forward-only: this constraint protects player-owned title grants from duplicate rows.
    }

    private function hasCharacterTitleUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('character_titles') as $index) {
            $columns = array_map(
                static fn ($column): string => strtolower((string) $column),
                $index['columns'] ?? [],
            );

            if (($index['unique'] ?? false) === true
                && $columns === ['character_id', 'title_id']) {
                return true;
            }
        }

        return false;
    }
};
