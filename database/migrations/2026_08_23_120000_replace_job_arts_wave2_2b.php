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
                        'Job Art replacement wave 2-B %s aborted: expected exactly one row for %s, found %d.',
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
            '2:9' => $this->row(
                name: '穿貫',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 225,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '物理威力225%の単発攻撃。相手の防御を50%無視',
                defIgnore: 50,
            ),
            '3:1' => $this->row(
                name: '影狩りの構え',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 90,
                duration: 3,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 8,
                cooldown: 0,
                maxUses: null,
                memo: '物理威力90%。敵の敏捷を15%低下（戦闘中）',
                enemySpdDown: 15,
            ),
            '3:5' => $this->row(
                name: '急所狙い',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 145,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 16,
                cooldown: 2,
                maxUses: null,
                memo: '物理威力145%。急所を狙う',
            ),
            '4:1' => $this->row(
                name: '精密射撃',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 90,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 8,
                cooldown: 0,
                maxUses: null,
                memo: '物理威力90%。精密な照準で射抜く',
            ),
            '5:1' => $this->row(
                name: '崩し打ち',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 90,
                duration: 3,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 6,
                cooldown: 0,
                maxUses: null,
                memo: '物理威力90%。敵の防御を15%低下（戦闘中）',
                enemyDefDown: 15,
            ),
            '5:5' => $this->row(
                name: '連環崩打',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 145,
                duration: 3,
                hitCount: 3,
                damageType: 'physical',
                spCostFixed: 20,
                cooldown: 2,
                maxUses: null,
                memo: '合計物理威力145%の3回攻撃。敵の防御と精神を15%低下（戦闘中）',
                enemyDefDown: 15,
                enemySprDown: 15,
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function oldRows(): array
    {
        return [
            '2:9' => $this->row(
                name: '巨人断ち',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 225,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '単体特大ダメージ＋自身の戦闘力を上昇（戦闘中）。1戦1回',
            ),
            '3:1' => $this->row(
                name: 'すり抜け',
                template: 'SELF_BUFF',
                category: 'buff',
                power: 90,
                duration: 2,
                hitCount: 1,
                damageType: 'support',
                spCostFixed: 8,
                cooldown: 0,
                maxUses: null,
                memo: '自身の戦闘力を小上昇（戦闘中）',
            ),
            '3:5' => $this->row(
                name: '不意打ち',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 16,
                cooldown: 2,
                maxUses: null,
                memo: '単体攻撃＋自身の戦闘力を上昇（戦闘中）',
            ),
            '4:1' => $this->row(
                name: '足止め矢',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 90,
                duration: 3,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 8,
                cooldown: 0,
                maxUses: null,
                memo: '単体小ダメージ＋敵SPD低下（戦闘中）',
                enemySpdDown: 10,
            ),
            '5:1' => $this->row(
                name: '気合拳',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 90,
                duration: 2,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 6,
                cooldown: 0,
                maxUses: null,
                memo: '単体小ダメージ＋自身の戦闘力を小上昇（戦闘中）',
            ),
            '5:5' => $this->row(
                name: '連打',
                template: 'MULTI_HIT',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 3,
                damageType: 'physical',
                spCostFixed: 20,
                cooldown: 2,
                maxUses: null,
                memo: '3回物理攻撃',
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
        ?int $maxUses,
        string $memo,
        int $defIgnore = 0,
        int $enemyDefDown = 0,
        int $enemySprDown = 0,
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
            'max_uses_per_battle' => $maxUses,
            'heal_percent' => 0,
            'self_damage_percent' => 0,
            'damage_reduction_percent' => 0,
            'self_buff_percent' => 0,
            'enemy_atk_down_percent' => 0,
            'enemy_mag_down_percent' => 0,
            'enemy_def_down_percent' => $enemyDefDown,
            'enemy_spr_down_percent' => $enemySprDown,
            'enemy_spd_down_percent' => $enemySpdDown,
            'drain_hp_rate' => 0.0,
            'def_ignore_percent' => $defIgnore,
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
