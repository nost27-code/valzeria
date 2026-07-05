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

        $this->setColumnDefaults(250);

        DB::table('characters')
            ->select(['id', 'wins', 'explore_stamina', 'explore_stamina_max'])
            ->orderBy('id')
            ->chunkById(500, function ($characters): void {
                $now = now();

                foreach ($characters as $character) {
                    $targetMax = $this->maxForWins((int) ($character->wins ?? 0));
                    $currentMax = (int) ($character->explore_stamina_max ?? 0);

                    if ($currentMax >= $targetMax) {
                        continue;
                    }

                    $current = max(0, (int) ($character->explore_stamina ?? 0));

                    DB::table('characters')
                        ->where('id', $character->id)
                        ->update([
                            'explore_stamina' => max($current, $targetMax),
                            'explore_stamina_max' => $targetMax,
                            'explore_stamina_updated_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('characters')
            || !Schema::hasColumn('characters', 'explore_stamina')
            || !Schema::hasColumn('characters', 'explore_stamina_max')
        ) {
            return;
        }

        $this->setColumnDefaults(50);
    }

    private function setColumnDefaults(int $default): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE characters MODIFY explore_stamina INT UNSIGNED NOT NULL DEFAULT {$default}");
            DB::statement("ALTER TABLE characters MODIFY explore_stamina_max INT UNSIGNED NOT NULL DEFAULT {$default}");
        }
    }

    private function maxForWins(int $wins): int
    {
        $max = 250;
        $max += intdiv(min($wins, 2000), 10);
        $max += intdiv(min(max($wins - 2000, 0), 1000), 20);

        return min(500, $max);
    }
};
