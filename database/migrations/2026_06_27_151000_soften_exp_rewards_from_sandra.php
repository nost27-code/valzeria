<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, float> */
    private array $multipliers = [
        6 => 0.94,
        7 => 0.88,
        8 => 0.82,
        9 => 0.76,
    ];

    public function up(): void
    {
        $this->applyMultipliers(false);
    }

    public function down(): void
    {
        $this->applyMultipliers(true);
    }

    private function applyMultipliers(bool $reverse): void
    {
        $rows = DB::table('enemies')
            ->select('enemies.id', 'enemies.exp_reward', 'areas.city_id')
            ->join('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('areas.city_id', '>=', 6)
            ->get();

        foreach ($rows as $row) {
            $cityId = (int) $row->city_id;
            $multiplier = $this->multipliers[$cityId] ?? 0.70;
            $factor = $reverse ? (1 / $multiplier) : $multiplier;
            $expReward = max(1, (int) round((int) $row->exp_reward * $factor));

            DB::table('enemies')
                ->where('id', (int) $row->id)
                ->update(['exp_reward' => $expReward]);
        }
    }
};
