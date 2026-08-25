<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyRows($this->newRows(), 'up');
    }

    public function down(): void
    {
        $this->applyRows($this->oldRows(), 'down');
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function applyRows(array $rows, string $direction): void
    {
        if (! Schema::hasTable('skills')) {
            return;
        }

        DB::transaction(function () use ($rows, $direction): void {
            $targetCount = DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where(function ($query) use ($rows): void {
                    foreach (array_keys($rows) as $naturalKey) {
                        [$jobId, $learnRank] = array_map('intval', explode(':', $naturalKey));
                        $query->orWhere(function ($pair) use ($jobId, $learnRank): void {
                            $pair->where('job_id', $jobId)->where('learn_rank', $learnRank);
                        });
                    }
                })
                ->count();

            // Fresh installations may run migrations before JobArtSeeder.
            if ($targetCount === 0) {
                return;
            }

            foreach ($rows as $naturalKey => $values) {
                [$jobId, $learnRank] = array_map('intval', explode(':', $naturalKey));
                $query = DB::table('skills')
                    ->where('job_id', $jobId)
                    ->where('learn_rank', $learnRank)
                    ->where('skill_type', 'job_art');
                $count = (clone $query)->count();

                if ($count !== 1) {
                    throw new RuntimeException(sprintf(
                        'Job Art replacement wave 2-C phase 1 %s aborted: expected exactly one row for %s, found %d.',
                        $direction,
                        $naturalKey,
                        $count,
                    ));
                }

                if (Schema::hasColumn('skills', 'updated_at')) {
                    $values['updated_at'] = now();
                }

                $query->update($values);
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function newRows(): array
    {
        return [
            '1:5' => $this->row(
                name: '受け返し',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 145,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 20,
                cooldown: 2,
                memo: '物理威力145%。直前の自分の行動後に受け流し成功なら最終ダメージ1.35倍',
            ),
            '17:1' => $this->row(
                name: '影伏せ',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 100,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 8,
                cooldown: 0,
                memo: '物理威力100%。次の封狩系Rank5/9の最終ダメージ1.20倍（1回・発動後4手番以内）',
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function oldRows(): array
    {
        return [
            '1:5' => $this->row(
                name: '連斬',
                template: 'MULTI_HIT',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 2,
                damageType: 'physical',
                spCostFixed: 20,
                cooldown: 2,
                memo: '2回物理攻撃。会心判定は各Hitで行う',
            ),
            '17:1' => $this->row(
                name: '煙玉',
                template: 'ENEMY_DEBUFF',
                category: 'debuff',
                power: 100,
                duration: 2,
                hitCount: 1,
                damageType: 'support',
                spCostFixed: 8,
                cooldown: 0,
                memo: '敵SPDを小低下（戦闘中・命中率に影響）',
                enemySpdDown: 10,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function row(
        string $name,
        string $template,
        string $category,
        int $power,
        int $duration,
        int $hitCount,
        string $damageType,
        int $spCostFixed,
        int $cooldown,
        string $memo,
        int $enemySpdDown = 0,
    ): array {
        return [
            'name' => $name,
            'effect_template' => $template,
            'art_category' => $category,
            'limit_group' => 'NONE',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'duration_turns' => $duration,
            'hit_count' => $hitCount,
            'damage_type' => $damageType,
            'hybrid_scaling' => 'average',
            'sp_cost_fixed' => $spCostFixed,
            'cooldown_turns' => $cooldown,
            'max_uses_per_battle' => null,
            'heal_percent' => 0,
            'self_damage_percent' => 0,
            'damage_reduction_percent' => 0,
            'self_buff_percent' => 0,
            'enemy_atk_down_percent' => 0,
            'enemy_mag_down_percent' => 0,
            'enemy_def_down_percent' => 0,
            'enemy_spr_down_percent' => 0,
            'enemy_spd_down_percent' => $enemySpdDown,
            'drain_hp_rate' => 0.0,
            'def_ignore_percent' => 0,
            'rare_bonus_percent' => 0,
            'mp_recover_percent' => 0,
            'gold_bonus_percent' => 0,
            'drop_bonus_percent' => 0,
            'luk_power_rate' => 0.0,
            'reward_scope' => 'none',
            'memo' => $memo,
            'description' => $memo,
        ];
    }
};
