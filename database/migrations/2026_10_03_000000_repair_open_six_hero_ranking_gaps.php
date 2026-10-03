<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $seasonIds = DB::table('six_hero_seasons')
            ->whereNull('finalized_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($seasonIds as $seasonId) {
            DB::transaction(function () use ($seasonId): void {
                $season = DB::table('six_hero_seasons')
                    ->where('id', $seasonId)
                    ->whereNull('finalized_at')
                    ->lockForUpdate()
                    ->first(['id']);
                if ($season === null) {
                    return;
                }

                $rooms = DB::table('six_hero_rankings')
                    ->where('season_id', $seasonId)
                    ->distinct()
                    ->orderBy('room_key')
                    ->pluck('room_key');

                foreach ($rooms as $room) {
                    $rankings = DB::table('six_hero_rankings')
                        ->where('season_id', $seasonId)
                        ->where('room_key', $room)
                        ->orderBy('rank')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id', 'rank', 'first_place_since']);

                    foreach ($rankings as $index => $ranking) {
                        $expectedRank = $index + 1;
                        if ((int) $ranking->rank === $expectedRank) {
                            continue;
                        }

                        $changes = [
                            'rank' => $expectedRank,
                            'updated_at' => now(),
                        ];
                        if ($expectedRank === 1) {
                            $changes['first_place_since'] = now();
                        }

                        DB::table('six_hero_rankings')
                            ->where('id', $ranking->id)
                            ->update($changes);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('修復した六英雄ランキングの欠番は安全に巻き戻せません。');
    }
};
