<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebalance the first city enemies so new players can clear early dungeons
     * without requiring lucky drops or repeated defeats.
     */
    public function up(): void
    {
        if (! Schema::hasTable('enemies')) {
            return;
        }

        $enemies = [
            1 => ['max_hp' => 32, 'str' => 10, 'def' => 5, 'agi' => 5, 'mag' => 4, 'spr' => 4, 'luk' => 2, 'appearance_weight' => 50],
            2 => ['max_hp' => 30, 'str' => 11, 'def' => 4, 'agi' => 8, 'mag' => 4, 'spr' => 4, 'luk' => 3, 'appearance_weight' => 30],
            3 => ['max_hp' => 38, 'str' => 13, 'def' => 6, 'agi' => 10, 'mag' => 5, 'spr' => 5, 'luk' => 3, 'appearance_weight' => 15],
            4 => ['max_hp' => 44, 'str' => 15, 'def' => 7, 'agi' => 10, 'mag' => 5, 'spr' => 5, 'luk' => 4, 'appearance_weight' => 4],
            5 => ['max_hp' => 48, 'str' => 16, 'def' => 8, 'agi' => 12, 'mag' => 5, 'spr' => 6, 'luk' => 4, 'appearance_weight' => 1],
            6 => ['max_hp' => 125, 'str' => 28, 'def' => 16, 'agi' => 12, 'mag' => 14, 'spr' => 12, 'luk' => 5, 'appearance_weight' => 0],
            7 => ['max_hp' => 60, 'str' => 20, 'def' => 10, 'agi' => 10, 'mag' => 8, 'spr' => 8, 'luk' => 4, 'appearance_weight' => 50],
            8 => ['max_hp' => 64, 'str' => 21, 'def' => 10, 'agi' => 14, 'mag' => 8, 'spr' => 8, 'luk' => 5, 'appearance_weight' => 30],
            9 => ['max_hp' => 74, 'str' => 20, 'def' => 14, 'agi' => 10, 'mag' => 8, 'spr' => 10, 'luk' => 5, 'appearance_weight' => 15],
            10 => ['max_hp' => 68, 'str' => 16, 'def' => 10, 'agi' => 12, 'mag' => 22, 'spr' => 14, 'luk' => 6, 'appearance_weight' => 4],
            11 => ['max_hp' => 86, 'str' => 26, 'def' => 15, 'agi' => 14, 'mag' => 10, 'spr' => 12, 'luk' => 6, 'appearance_weight' => 1],
            12 => ['max_hp' => 210, 'str' => 38, 'def' => 24, 'agi' => 18, 'mag' => 16, 'spr' => 18, 'luk' => 7, 'appearance_weight' => 0],
            13 => ['max_hp' => 92, 'str' => 28, 'def' => 14, 'agi' => 20, 'mag' => 12, 'spr' => 12, 'luk' => 7, 'appearance_weight' => 50],
            14 => ['max_hp' => 108, 'str' => 30, 'def' => 18, 'agi' => 14, 'mag' => 12, 'spr' => 16, 'luk' => 6, 'appearance_weight' => 30],
            15 => ['max_hp' => 112, 'str' => 32, 'def' => 18, 'agi' => 15, 'mag' => 14, 'spr' => 16, 'luk' => 7, 'appearance_weight' => 15],
            16 => ['max_hp' => 104, 'str' => 31, 'def' => 15, 'agi' => 22, 'mag' => 12, 'spr' => 12, 'luk' => 8, 'appearance_weight' => 4],
            17 => ['max_hp' => 128, 'str' => 36, 'def' => 22, 'agi' => 14, 'mag' => 12, 'spr' => 16, 'luk' => 8, 'appearance_weight' => 1],
            18 => ['max_hp' => 285, 'str' => 46, 'def' => 32, 'agi' => 22, 'mag' => 22, 'spr' => 28, 'luk' => 9, 'appearance_weight' => 0],
            19 => ['max_hp' => 128, 'str' => 38, 'def' => 18, 'agi' => 28, 'mag' => 16, 'spr' => 16, 'luk' => 9, 'appearance_weight' => 50],
            20 => ['max_hp' => 140, 'str' => 40, 'def' => 20, 'agi' => 32, 'mag' => 16, 'spr' => 16, 'luk' => 10, 'appearance_weight' => 30],
            21 => ['max_hp' => 150, 'str' => 39, 'def' => 20, 'agi' => 34, 'mag' => 20, 'spr' => 20, 'luk' => 11, 'appearance_weight' => 15],
            22 => ['max_hp' => 150, 'str' => 42, 'def' => 21, 'agi' => 34, 'mag' => 16, 'spr' => 18, 'luk' => 11, 'appearance_weight' => 4],
            23 => ['max_hp' => 170, 'str' => 48, 'def' => 26, 'agi' => 28, 'mag' => 18, 'spr' => 22, 'luk' => 12, 'appearance_weight' => 1],
            24 => ['max_hp' => 330, 'str' => 54, 'def' => 34, 'agi' => 34, 'mag' => 24, 'spr' => 28, 'luk' => 13, 'appearance_weight' => 0],
            25 => ['max_hp' => 170, 'str' => 50, 'def' => 26, 'agi' => 22, 'mag' => 20, 'spr' => 26, 'luk' => 9, 'appearance_weight' => 50],
            26 => ['max_hp' => 185, 'str' => 52, 'def' => 28, 'agi' => 24, 'mag' => 20, 'spr' => 28, 'luk' => 10, 'appearance_weight' => 30],
            27 => ['max_hp' => 195, 'str' => 48, 'def' => 28, 'agi' => 26, 'mag' => 54, 'spr' => 34, 'luk' => 11, 'appearance_weight' => 15],
            28 => ['max_hp' => 188, 'str' => 50, 'def' => 26, 'agi' => 34, 'mag' => 44, 'spr' => 30, 'luk' => 12, 'appearance_weight' => 4],
            29 => ['max_hp' => 220, 'str' => 58, 'def' => 34, 'agi' => 28, 'mag' => 20, 'spr' => 30, 'luk' => 13, 'appearance_weight' => 1],
            30 => ['max_hp' => 410, 'str' => 60, 'def' => 38, 'agi' => 34, 'mag' => 60, 'spr' => 42, 'luk' => 15, 'appearance_weight' => 0],
            31 => ['max_hp' => 225, 'str' => 58, 'def' => 36, 'agi' => 28, 'mag' => 26, 'spr' => 34, 'luk' => 12, 'appearance_weight' => 50],
            32 => ['max_hp' => 220, 'str' => 46, 'def' => 30, 'agi' => 34, 'mag' => 62, 'spr' => 44, 'luk' => 14, 'appearance_weight' => 30],
            33 => ['max_hp' => 250, 'str' => 52, 'def' => 34, 'agi' => 36, 'mag' => 66, 'spr' => 46, 'luk' => 15, 'appearance_weight' => 15],
            34 => ['max_hp' => 240, 'str' => 50, 'def' => 32, 'agi' => 42, 'mag' => 62, 'spr' => 42, 'luk' => 16, 'appearance_weight' => 4],
            35 => ['max_hp' => 285, 'str' => 64, 'def' => 42, 'agi' => 34, 'mag' => 42, 'spr' => 46, 'luk' => 16, 'appearance_weight' => 1],
            36 => ['max_hp' => 500, 'str' => 70, 'def' => 44, 'agi' => 42, 'mag' => 70, 'spr' => 50, 'luk' => 18, 'appearance_weight' => 0],
            37 => ['max_hp' => 285, 'str' => 70, 'def' => 42, 'agi' => 38, 'mag' => 30, 'spr' => 34, 'luk' => 14, 'appearance_weight' => 50],
            38 => ['max_hp' => 300, 'str' => 74, 'def' => 44, 'agi' => 40, 'mag' => 30, 'spr' => 34, 'luk' => 15, 'appearance_weight' => 30],
            39 => ['max_hp' => 295, 'str' => 54, 'def' => 38, 'agi' => 40, 'mag' => 78, 'spr' => 52, 'luk' => 16, 'appearance_weight' => 15],
            40 => ['max_hp' => 330, 'str' => 72, 'def' => 46, 'agi' => 44, 'mag' => 60, 'spr' => 48, 'luk' => 18, 'appearance_weight' => 4],
            41 => ['max_hp' => 360, 'str' => 82, 'def' => 52, 'agi' => 40, 'mag' => 36, 'spr' => 42, 'luk' => 19, 'appearance_weight' => 1],
            42 => ['max_hp' => 590, 'str' => 78, 'def' => 52, 'agi' => 46, 'mag' => 46, 'spr' => 50, 'luk' => 21, 'appearance_weight' => 0],
        ];

        foreach ($enemies as $id => $attributes) {
            DB::table('enemies')
                ->where('id', $id)
                ->update(array_merge($attributes, ['updated_at' => now()]));
        }
    }

    public function down(): void
    {
        // Data rebalance only. The previous values are intentionally not restored.
    }
};
