<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            if (!Schema::hasColumn('skills', 'sp_cost_fixed')) {
                $table->unsignedSmallInteger('sp_cost_fixed')
                    ->nullable()
                    ->after('sp_cost_rate')
                    ->comment('奥義用の固定SP消費量');
            }
        });

        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'job_art_activation_policy')) {
                $table->string('job_art_activation_policy', 30)
                    ->default('normal')
                    ->after('home_display_mode')
                    ->comment('奥義発動方針: aggressive / normal / conserve / boss_only');
            }
        });

        $this->backfillJobArtFixedSpCosts();
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'job_art_activation_policy')) {
                $table->dropColumn('job_art_activation_policy');
            }
        });

        Schema::table('skills', function (Blueprint $table) {
            if (Schema::hasColumn('skills', 'sp_cost_fixed')) {
                $table->dropColumn('sp_cost_fixed');
            }
        });
    }

    private function backfillJobArtFixedSpCosts(): void
    {
        $path = base_path('database/data/job_arts.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $learnRank = (int) ($row['learn_rank'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($jobId <= 0 || $learnRank <= 0 || $name === '') {
                continue;
            }

            DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('job_id', $jobId)
                ->where('learn_rank', $learnRank)
                ->where('name', $name)
                ->update(['sp_cost_fixed' => $this->fixedSpCostFor($row)]);
        }
    }

    private function fixedSpCostFor(array $row): int
    {
        if (isset($row['sp_cost_fixed']) && $row['sp_cost_fixed'] !== '') {
            return max(0, (int) $row['sp_cost_fixed']);
        }

        $rank = (int) ($row['learn_rank'] ?? 1);
        $column = match (true) {
            $rank >= 9 => 9,
            $rank >= 5 => 5,
            default => 1,
        };

        $template = (string) ($row['effect_template'] ?? '');
        $category = (string) ($row['art_category'] ?? '');
        $limitGroup = strtoupper((string) ($row['limit_group'] ?? 'NONE'));

        $costs = match (true) {
            $limitGroup === 'TIME' || $template === 'TIME_CONTROL_CURRENT_ONLY' => [1 => 12, 5 => 32, 9 => 65],
            $limitGroup === 'REWARD' || str_starts_with($template, 'REWARD_') => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'GUTS' || $template === 'GUTS' => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'HEAL' || in_array($template, ['HEAL', 'HEAL_CLEANSE'], true) => [1 => 10, 5 => 26, 9 => 60],
            $template === 'DRAIN' => [1 => 10, 5 => 28, 9 => 62],
            $template === 'MULTI_HIT' => [1 => 8, 5 => 20, 9 => 48],
            $template === 'MAGICAL_DAMAGE' => [1 => 8, 5 => 22, 9 => 52],
            $template === 'HYBRID_DAMAGE' => [1 => 8, 5 => 22, 9 => 52],
            $template === 'GUARD_BARRIER' || $category === 'guard' => [1 => 8, 5 => 22, 9 => 50],
            $category === 'buff' => [1 => 8, 5 => 20, 9 => 46],
            $category === 'debuff' || in_array($template, ['DAMAGE_DEBUFF', 'ENEMY_DEBUFF'], true) => [1 => 8, 5 => 20, 9 => 46],
            default => [1 => 6, 5 => 16, 9 => 42],
        };

        return $costs[$column];
    }
};
