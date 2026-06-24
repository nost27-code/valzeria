<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('monster_marks') || !Schema::hasTable('enemies') || !Schema::hasTable('areas')) {
            return;
        }

        $marks = DB::table('monster_marks')
            ->join('enemies', 'enemies.id', '=', 'monster_marks.enemy_id')
            ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')
            ->select('monster_marks.id', 'areas.city_id')
            ->get();

        foreach ($marks as $mark) {
            DB::table('monster_marks')
                ->where('id', $mark->id)
                ->update([
                    'bonus_per_level' => $this->bonusPerLevel((int) ($mark->city_id ?? 1)),
                    'max_level' => 4,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('monster_marks')) {
            return;
        }

        DB::table('monster_marks')->update([
            'bonus_per_level' => 10,
            'max_level' => 10,
            'updated_at' => now(),
        ]);
    }

    private function bonusPerLevel(int $stage): int
    {
        return match (true) {
            $stage >= 7 => 3,
            $stage >= 4 => 2,
            default => 1,
        };
    }
};
