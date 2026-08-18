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
                        'First Job Art replacement wave %s aborted: expected exactly one row for %s, found %d.',
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
            '1:1' => $this->row(
                name: '見切りの呼吸',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 90,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                memo: '物理威力90%。発動後1ラウンド、受け流し率+25%',
                phrase: '「その一太刀、見切った。」',
                activationDescription: '{user}は呼吸を研ぎ澄ます。《{skill}》で{target}の動きを読み、受け流しの構えを取った！',
            ),
            '2:5' => $this->row(
                name: '二段穿ち',
                template: 'MULTI_HIT',
                category: 'attack',
                power: 145,
                duration: 1,
                hitCount: 2,
                damageType: 'physical',
                memo: '合計物理威力145%の2回攻撃。2Hitとも物理DEFを25%無視',
                phrase: '「一点を、二度穿つ！」',
                activationDescription: '{user}が鋭く踏み込む。《{skill}》の二撃が{target}の守りを貫いた！',
                cooldown: 2,
            ),
            '5:9' => $this->row(
                name: '大崩拳',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 225,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                memo: '物理威力225%。行動開始時HP30%以下なら最終ダメージ1.60倍',
                phrase: '「崩し切る――これで終いだ！」',
                activationDescription: '{user}が残る力を拳へ集める。《{skill}》が{target}の体勢ごと打ち砕いた！',
                cooldown: 5,
                maxUses: 1,
            ),
            '9:9' => $this->row(
                name: '蝕みの終端',
                template: 'PHYSICAL_DAMAGE',
                category: 'attack',
                power: 255,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                memo: '物理威力255%。行動開始時HP40%以下なら最終ダメージ1.50倍',
                phrase: '「蝕め、命の果てまで。」',
                activationDescription: '{user}の身を蝕む力が刃へ集う。《{skill}》が{target}へ終焉の一撃を刻んだ！',
                cooldown: 5,
                maxUses: 1,
            ),
            '12:9' => $this->row(
                name: '総力戦',
                template: 'HYBRID_DAMAGE',
                category: 'attack',
                power: 255,
                duration: 3,
                hitCount: 1,
                damageType: 'hybrid',
                memo: '複合威力255%。発動後ATK/MAG+30%（3ラウンド、重複せず更新）',
                phrase: '「全軍、ここが勝負どころだ！」',
                activationDescription: '{user}の号令にすべての力が応える。《{skill}》が{target}へ総攻撃を仕掛けた！',
                cooldown: 5,
                maxUses: 1,
            ),
            '15:1' => $this->row(
                name: '不屈の誓い',
                template: 'GUARD_BARRIER',
                category: 'guard',
                power: 0,
                duration: 1,
                hitCount: 0,
                damageType: 'support',
                memo: '次に受けるダメージを40%軽減。軽減は1回で消滅',
                phrase: '「この身は、まだ折れない。」',
                activationDescription: '{user}は決して退かない。《{skill}》の守りが次の一撃に備えて立ちはだかる！',
                damageReduction: 40,
            ),
            '29:1' => $this->row(
                name: '静寂の帳',
                template: 'MAGICAL_DAMAGE',
                category: 'attack',
                power: 95,
                duration: 5,
                hitCount: 1,
                damageType: 'magical',
                memo: '魔法威力95%。静寂の場を5ラウンド展開（この攻撃には非適用）',
                phrase: '「音よ、沈み、力を伏せよ。」',
                activationDescription: '{user}が静かに手を掲げる。《{skill}》が{target}を包む静寂の帳を広げた！',
                maxUses: 3,
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function oldRows(): array
    {
        return [
            '1:1' => $this->row(
                name: '斬り払い',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 90,
                duration: 3,
                hitCount: 1,
                damageType: 'physical',
                memo: '単体小ダメージ＋敵ATK小低下（戦闘中）',
                phrase: '「そこを退け！」',
                activationDescription: '{user}は半歩踏み込み、《{skill}》で{target}の攻撃ごと刃で払いのけた！',
                enemyAtkDown: 6,
            ),
            '2:5' => $this->row(
                name: '渾身撃',
                template: 'DAMAGE_BUFF',
                category: 'attack',
                power: 145,
                duration: 2,
                hitCount: 1,
                damageType: 'physical',
                memo: '単体中大ダメージ＋自身の戦闘力を上昇（戦闘中）',
                phrase: '「力で押し切る！」',
                activationDescription: '{user}の重い一撃が唸る。《{skill}》で{target}の体勢を大きく崩した！',
                cooldown: 2,
            ),
            '5:9' => $this->row(
                name: '爆裂闘気',
                template: 'DAMAGE_BUFF',
                category: 'debuff',
                power: 225,
                duration: 1,
                hitCount: 4,
                damageType: 'physical',
                memo: '4回攻撃＋自身の戦闘力上昇（戦闘中）。1戦1回',
                phrase: '「我が闘気、解き放つ！」',
                activationDescription: '{user}の全身から闘気が噴き上がる。《{skill}》が{target}を一気に打ち抜いた！',
                cooldown: 5,
                maxUses: 1,
            ),
            '9:9' => $this->row(
                name: '双極断',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 255,
                duration: 1,
                hitCount: 1,
                damageType: 'physical',
                memo: '物理Hit＋魔法Hit。敵の高い防御系ステを小低下',
                phrase: '「極まれ、我が魔導！」',
                activationDescription: '{user}の魔導が臨界に達する。《{skill}》が戦場をまばゆい奔流で満たした！',
                cooldown: 5,
                maxUses: 1,
            ),
            '12:9' => $this->row(
                name: '十面埋伏',
                template: 'ENEMY_DEBUFF',
                category: 'debuff',
                power: 255,
                duration: 3,
                hitCount: 0,
                damageType: 'support',
                memo: '敵ATK/MAG/SPDを低下（戦闘中）',
                phrase: '「全軍、決着の陣へ！」',
                activationDescription: '{user}の策が一斉に噛み合う。《{skill}》が{target}を完全に包囲した！',
                cooldown: 5,
                maxUses: 1,
                enemyAtkDown: 10,
                enemyMagDown: 10,
                enemySpdDown: 10,
            ),
            '15:1' => $this->row(
                name: 'シールドバッシュ',
                template: 'DAMAGE_DEBUFF',
                category: 'debuff',
                power: 100,
                duration: 2,
                hitCount: 1,
                damageType: 'physical',
                memo: '単体小ダメージ＋敵ATK小低下（戦闘中）',
                phrase: '「ここは通さない。」',
                activationDescription: '{user}は盾を構え、《{skill}》で{target}の勢いを受け止めた！',
                enemyAtkDown: 6,
            ),
            '29:1' => $this->row(
                name: '魔力循環',
                template: 'HEAL',
                category: 'heal',
                power: 100,
                duration: 2,
                hitCount: 0,
                damageType: 'heal',
                memo: 'HP小回復＋SP小回復',
                phrase: '「魔力よ、形を成せ。」',
                activationDescription: '{user}の指先に魔力が灯る。《{skill}》が小さな光となって{target}へ飛んだ！',
                limitGroup: 'HEAL',
                maxUses: 3,
                mpRecover: 5,
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
        string $memo,
        string $phrase,
        string $activationDescription,
        string $limitGroup = 'NONE',
        int $cooldown = 0,
        ?int $maxUses = null,
        int $damageReduction = 0,
        int $enemyAtkDown = 0,
        int $enemyMagDown = 0,
        int $enemyDefDown = 0,
        int $enemySprDown = 0,
        int $enemySpdDown = 0,
        int $mpRecover = 0,
    ): array {
        return [
            'name' => $name,
            'effect_template' => $template,
            'art_category' => $category,
            'limit_group' => $limitGroup,
            'power' => $power,
            'power_multiplier' => $power / 100,
            'duration_turns' => $duration,
            'hit_count' => $hitCount,
            'damage_type' => $damageType,
            'hybrid_scaling' => 'average',
            'cooldown_turns' => $cooldown,
            'max_uses_per_battle' => $maxUses,
            'damage_reduction_percent' => $damageReduction,
            'enemy_atk_down_percent' => $enemyAtkDown,
            'enemy_mag_down_percent' => $enemyMagDown,
            'enemy_def_down_percent' => $enemyDefDown,
            'enemy_spr_down_percent' => $enemySprDown,
            'enemy_spd_down_percent' => $enemySpdDown,
            'mp_recover_percent' => $mpRecover,
            'memo' => $memo,
            'description' => $memo,
            'activation_phrase' => $phrase,
            'activation_description' => $activationDescription,
        ];
    }
};
