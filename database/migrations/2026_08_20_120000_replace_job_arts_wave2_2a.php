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
                        'Job Art replacement wave 2-A %s aborted: expected exactly one row for %s, found %d.',
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
            '6:5' => $this->row(
                name: '天測の陣',
                template: 'MAGICAL_DAMAGE',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 1,
                damageType: 'magical',
                spCostFixed: 22,
                cooldown: 2,
                maxUses: null,
                memo: '星印を4消費して魔法威力145%。天測の場を5ラウンド展開（この攻撃には非適用）',
            ),
            '17:9' => $this->row(
                name: '狩猟の完成',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 255,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '物理威力255%。標的印が2段階以上なら2段階消費して最終ダメージ1.50倍',
            ),
            '19:9' => $this->row(
                name: '魂喰らい',
                template: 'DRAIN',
                category: 'attack',
                power: 255,
                duration: 1,
                hitCount: 1,
                damageType: 'magical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '魔法威力255%。与ダメージの35%をHP吸収し、HIT時に対象の最大SP10%分を圧する',
                drainHpRate: 0.35,
            ),
            '33:9' => $this->row(
                name: '崩落',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 315,
                duration: 5,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 46,
                cooldown: 5,
                maxUses: 1,
                memo: '物理威力315%の単発攻撃＋敵DEF/SPRを25%低下（戦闘中）',
                enemyDefDown: 25,
                enemySprDown: 25,
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function oldRows(): array
    {
        return [
            '6:5' => $this->row(
                name: '火炎弾',
                template: 'MAGICAL_DAMAGE',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 1,
                damageType: 'magical',
                spCostFixed: 22,
                cooldown: 2,
                maxUses: null,
                memo: '単体中魔法ダメージ',
            ),
            '17:9' => $this->row(
                name: '瞬影乱舞',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 255,
                duration: 1,
                hitCount: 4,
                damageType: 'physical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '4回攻撃＋自身の戦闘力上昇（戦闘中）。1戦1回',
            ),
            '19:9' => $this->row(
                name: 'ルーン強奪',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 255,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                spCostFixed: 42,
                cooldown: 5,
                maxUses: 1,
                memo: '単体大魔法＋自身の戦闘力を上昇（戦闘中）。1戦1回',
            ),
            '33:9' => $this->row(
                name: '武神降臨',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 315,
                duration: 3,
                hitCount: 2,
                damageType: 'physical',
                spCostFixed: 46,
                cooldown: 5,
                maxUses: 1,
                memo: '強力な2回攻撃＋敵の守りを低下（戦闘中）。1戦1回',
                enemyDefDown: 20,
                enemySprDown: 10,
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
        float $drainHpRate = 0.0,
        int $enemyDefDown = 0,
        int $enemySprDown = 0,
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
            'enemy_spd_down_percent' => 0,
            'drain_hp_rate' => $drainHpRate,
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
