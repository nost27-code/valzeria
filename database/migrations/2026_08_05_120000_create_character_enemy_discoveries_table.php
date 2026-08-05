<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_enemy_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enemy_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_encountered_at');
            $table->timestamp('first_defeated_at')->nullable();
            $table->timestamp('last_defeated_at')->nullable();
            $table->unsignedInteger('defeat_count')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'enemy_id'], 'character_enemy_discoveries_unique');
            $table->index(['character_id', 'first_defeated_at'], 'character_enemy_discoveries_defeated_idx');
        });

        $this->backfillBattleLogs();
    }

    public function down(): void
    {
        Schema::dropIfExists('character_enemy_discoveries');
    }

    private function backfillBattleLogs(): void
    {
        if (! Schema::hasTable('battle_logs')) {
            return;
        }

        DB::table('battle_logs')
            ->join('characters', 'characters.id', '=', 'battle_logs.character_id')
            ->join('enemies', 'enemies.id', '=', 'battle_logs.enemy_id')
            ->whereIn('battle_logs.battle_type', ['normal', 'boss', 'sub_area', 'exploration_map'])
            ->whereIn('battle_logs.result', ['win', 'victory', 'lose', 'defeat'])
            ->whereNotNull('battle_logs.enemy_id')
            ->where(function ($query): void {
                $query->whereNull('battle_logs.turn_count')->orWhere('battle_logs.turn_count', '>', 0);
            })
            ->selectRaw(
                "battle_logs.character_id, battle_logs.enemy_id, MIN(battle_logs.created_at) AS first_encountered_at, " .
                "MIN(CASE WHEN battle_logs.result IN ('win', 'victory') THEN battle_logs.created_at END) AS first_defeated_at, " .
                "MAX(CASE WHEN battle_logs.result IN ('win', 'victory') THEN battle_logs.created_at END) AS last_defeated_at, " .
                "SUM(CASE WHEN battle_logs.result IN ('win', 'victory') THEN 1 ELSE 0 END) AS defeat_count"
            )
            ->groupBy('battle_logs.character_id', 'battle_logs.enemy_id')
            ->orderBy('battle_logs.character_id')
            ->orderBy('battle_logs.enemy_id')
            ->chunk(200, function ($rows): void {
                $now = now();
                DB::table('character_enemy_discoveries')->insertOrIgnore($rows->map(
                    fn (object $row): array => [
                        'character_id' => (int) $row->character_id,
                        'enemy_id' => (int) $row->enemy_id,
                        'first_encountered_at' => $row->first_encountered_at,
                        'first_defeated_at' => $row->first_defeated_at,
                        'last_defeated_at' => $row->last_defeated_at,
                        'defeat_count' => (int) $row->defeat_count,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                )->all());
            });
    }
};
