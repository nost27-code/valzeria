<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skills')) {
            return;
        }

        $query = DB::table('skills')
            ->where('job_id', 67)
            ->where('learn_rank', 9)
            ->where('skill_type', 'job_art')
            ->where('name', '金冠ミダスフィールド');

        $count = (clone $query)->count();
        $hasCrownJobArts = DB::table('skills')
            ->where('skill_type', 'job_art')
            ->whereBetween('job_id', [60, 69])
            ->exists();
        if ($count === 0 && ! $hasCrownJobArts) {
            return;
        }
        if ($count !== 1) {
            throw new RuntimeException(
                "Crown Alchemist rebalance aborted: expected exactly one Rank 9 Job Art row, found {$count}."
            );
        }

        $values = [
            'power' => 315,
            'power_multiplier' => 3.15,
        ];
        if (Schema::hasColumn('skills', 'updated_at')) {
            $values['updated_at'] = now();
        }

        $query->update($values);
    }

    public function down(): void
    {
        // Intentional no-op. The authoritative master remains at 315%, and
        // restoring only the database row would make runtime data inconsistent.
    }
};
