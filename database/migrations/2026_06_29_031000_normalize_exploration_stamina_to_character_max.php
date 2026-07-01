<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('characters')
            || !Schema::hasColumn('characters', 'explore_stamina')
            || !Schema::hasColumn('characters', 'explore_stamina_max')
            || !Schema::hasColumn('characters', 'explore_stamina_updated_at')
        ) {
            return;
        }

        DB::table('characters')
            ->select(['id', 'wins'])
            ->orderBy('id')
            ->chunkById(500, function ($characters): void {
                $now = now();
                foreach ($characters as $character) {
                    $max = $this->maxForWins((int) ($character->wins ?? 0));
                    DB::table('characters')
                        ->where('id', $character->id)
                        ->update([
                            'explore_stamina' => $max,
                            'explore_stamina_max' => $max,
                            'explore_stamina_updated_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // データ補正のみのためロールバックでは値を戻しません。
    }

    private function maxForWins(int $wins): int
    {
        $max = 50;
        $max += intdiv(min($wins, 2000), 10);
        $max += intdiv(min(max($wins - 2000, 0), 2000), 20);
        $max += intdiv(min(max($wins - 4000, 0), 1500), 30);
        $max += intdiv(min(max($wins - 5500, 0), 2000), 40);
        $max += intdiv(max($wins - 7500, 0), 50);

        return min(500, $max);
    }
};
