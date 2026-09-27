<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $references = [
        'exploration_maps' => ['owner_character_id'],
        'map_exploration_batches' => ['character_id'],
        'map_exploration_results' => ['character_id'],
        'map_income_logs' => ['payer_character_id', 'owner_character_id'],
    ];

    public function up(): void
    {
        foreach ($this->references as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->unsignedBigInteger($column)->nullable()->change();
                    $blueprint->foreign($column)->references('id')->on('characters')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Never erase history or invent replacement owners to roll back.
        foreach ($this->references as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereNull($column)->exists()) {
                    throw new RuntimeException('退会済みの地図履歴があるため、このmigrationは巻き戻せません。');
                }
            }
        }
        foreach ($this->references as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->unsignedBigInteger($column)->nullable(false)->change();
                    $blueprint->foreign($column)->references('id')->on('characters');
                }
            });
        }
    }
};
