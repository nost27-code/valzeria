<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->applySchedule([
            1 => 1,
            11 => 1,
            21 => 2,
            31 => 2,
            41 => 3,
            51 => 3,
            61 => 4,
            71 => 4,
            81 => 5,
            91 => 5,
        ]);
    }

    public function down(): void
    {
        $this->applySchedule([
            1 => 1,
            11 => 2,
            21 => 3,
            31 => 4,
            41 => 5,
            51 => 6,
            61 => 7,
            71 => 8,
            81 => 9,
            91 => 10,
        ]);
    }

    /**
     * @param array<int, int> $schedule
     */
    private function applySchedule(array $schedule): void
    {
        if (!Schema::hasTable('tower_floor_master')) {
            return;
        }

        ksort($schedule, SORT_NUMERIC);
        $starts = array_keys($schedule);

        foreach ($starts as $index => $startFloor) {
            $query = DB::table('tower_floor_master')
                ->where('tower_key', 'star_tree_tower')
                ->where('floor', '>=', $startFloor);

            $nextStartFloor = $starts[$index + 1] ?? null;
            if ($nextStartFloor !== null) {
                $query->where('floor', '<', $nextStartFloor);
            }

            $query->update(['stamina_cost' => $schedule[$startFloor]]);
        }
    }
};
