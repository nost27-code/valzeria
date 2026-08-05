<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnemyDiscoveryService
{
    public const TABLE = 'character_enemy_discoveries';

    public function recordBattle(int $characterId, int $enemyId, string $result): void
    {
        if (! Schema::hasTable(self::TABLE) || $characterId <= 0 || $enemyId <= 0) {
            return;
        }

        $now = now();
        DB::table(self::TABLE)->insertOrIgnore([
            'character_id' => $characterId,
            'enemy_id' => $enemyId,
            'first_encountered_at' => $now,
            'first_defeated_at' => null,
            'last_defeated_at' => null,
            'defeat_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! in_array($result, ['win', 'victory'], true)) {
            return;
        }

        DB::table(self::TABLE)
            ->where('character_id', $characterId)
            ->where('enemy_id', $enemyId)
            ->whereNull('first_defeated_at')
            ->update([
                'first_defeated_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table(self::TABLE)
            ->where('character_id', $characterId)
            ->where('enemy_id', $enemyId)
            ->update([
                'last_defeated_at' => $now,
                'defeat_count' => DB::raw('defeat_count + 1'),
                'updated_at' => $now,
            ]);
    }
}
