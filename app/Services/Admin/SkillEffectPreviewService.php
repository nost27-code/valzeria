<?php

namespace App\Services\Admin;

use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Support\JobArtEffectCatalog;
use Illuminate\Support\Collection;

class SkillEffectPreviewService
{
    /**
     * @param array<string, int|string|null> $attackerStats
     * @param Collection<int, Skill> $skills
     * @return array<string, mixed>
     */
    public function preview(array $attackerStats, JobClass $job, Enemy $enemy, Collection $skills): array
    {
        $attacker = new BattleActor('検証キャラ', true, array_merge($this->normalizePlayerStats($attackerStats), [
            'job_key' => $job->key ?? null,
            'normal_attack_type' => $job->normal_attack_type ?? null,
        ]));
        $defender = new BattleActor($enemy->name, false, $this->enemyStats($enemy), $enemy);

        $turns = [];
        $skillSummaries = [];
        $baselineDamage = null;

        for ($slot = 1; $slot <= 3; $slot++) {
            $normalTurn = (($slot - 1) * 2) + 1;
            $normalDamage = $this->normalDamage($attacker, $defender);
            $baselineDamage ??= $normalDamage;
            $turns[] = [
                'turn' => $normalTurn,
                'kind' => 'normal',
                'label' => '通常攻撃',
                'damage_type_label' => $attacker->usesMagForNormalAttack() ? '魔法' : '物理',
                'damage' => $normalDamage,
                'ratio_to_previous_normal' => 1.0,
                'ratio_to_first_normal' => $this->ratio($normalDamage, $baselineDamage),
                'effects' => [],
                'state' => $this->stateSnapshot($attacker, $defender),
            ];

            $skill = $skills->get($slot - 1);
            if (! $skill) {
                continue;
            }

            $skillTurn = $normalTurn + 1;
            $result = $this->skillResult($attacker, $defender, $skill);
            $this->applySkillEffects($attacker, $defender, $skill, $result['damage']);

            $turn = [
                'turn' => $skillTurn,
                'kind' => $skill->isJobArt() ? 'job_art' : 'special',
                'kind_label' => $this->kindLabel($skill, $job),
                'label' => $skill->name,
                'job_name' => $skill->jobClass?->name,
                'damage_type_label' => $result['damage_type_label'],
                'damage' => $result['damage'],
                'ratio_to_previous_normal' => $this->ratio($result['damage'], $normalDamage),
                'ratio_to_first_normal' => $this->ratio($result['damage'], $baselineDamage),
                'effects' => array_values(array_unique(array_merge($result['effects'], $this->effectDescriptions($skill, $result['damage'], $attacker)))),
                'description' => $skill->description ?: $skill->memo,
                'activation_rate' => $skill->effectiveActivationRate(),
                'sp_cost' => $skill->isJobArt()
                    ? $skill->jobArtSpCostForMaxSp($attacker->maxMp, 'inherited')
                    : $skill->specialSkillSpCostForMaxSp($attacker->maxMp),
                'state' => $this->stateSnapshot($attacker, $defender),
            ];

            $turns[] = $turn;
            $skillSummaries[] = $turn;
        }

        return [
            'turns' => $turns,
            'skill_summaries' => $skillSummaries,
            'baseline_damage' => $baselineDamage ?? 0,
            'attacker' => $this->stateSnapshot($attacker, $defender)['attacker'],
            'enemy' => $this->enemyStats($enemy),
            'notes' => [
                '乱数・命中・会心は除外した平均値ベースです。',
                '倍率は直前の通常攻撃比を主表示し、1ターン目通常攻撃比も併記します。',
                '継承奥義は継承時の威力倍率を反映します。',
            ],
        ];
    }

    /**
     * @param array<string, int|string|null> $stats
     * @return array<string, int|string|null>
     */
    private function normalizePlayerStats(array $stats): array
    {
        $normalized = [
            'max_hp' => max(1, (int) ($stats['max_hp'] ?? 1000)),
            'hp' => max(1, (int) ($stats['max_hp'] ?? 1000)),
            'max_mp' => max(0, (int) ($stats['max_mp'] ?? 100)),
            'mp' => max(0, (int) ($stats['max_mp'] ?? 100)),
            'str' => max(1, (int) ($stats['str'] ?? 100)),
            'def' => max(1, (int) ($stats['def'] ?? 100)),
            'agi' => max(1, (int) ($stats['agi'] ?? 100)),
            'mag' => max(1, (int) ($stats['mag'] ?? 100)),
            'spr' => max(1, (int) ($stats['spr'] ?? 100)),
            'luk' => max(1, (int) ($stats['luk'] ?? 50)),
        ];

        return $normalized;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function enemyStats(Enemy $enemy): array
    {
        return [
            'name' => $enemy->name,
            'level' => (int) ($enemy->level ?? 1),
            'max_hp' => max(1, (int) ($enemy->max_hp ?? 1)),
            'hp' => max(1, (int) ($enemy->max_hp ?? 1)),
            'max_mp' => 0,
            'mp' => 0,
            'str' => max(1, (int) ($enemy->str ?? 1)),
            'def' => max(1, (int) ($enemy->def ?? 1)),
            'agi' => max(1, (int) ($enemy->agi ?? 1)),
            'mag' => max(1, (int) ($enemy->mag ?? 1)),
            'spr' => max(1, (int) ($enemy->spr ?? $enemy->def ?? 1)),
            'luk' => max(1, (int) ($enemy->luk ?? 10)),
            'area_name' => $enemy->area?->name,
            'city_name' => $enemy->area?->city?->name,
            'is_boss' => (bool) ($enemy->is_boss ?? false),
        ];
    }

    private function normalDamage(BattleActor $attacker, BattleActor $defender): int
    {
        return $attacker->usesMagForNormalAttack()
            ? $this->averageDamage($attacker->mag, $defender->spr, 100, $defender->damageReductionRate)
            : $this->averageDamage($attacker->str, $defender->def, 100, $defender->damageReductionRate);
    }

    /**
     * @return array{damage:int,damage_type_label:string,effects:array<int,string>}
     */
    private function skillResult(BattleActor $attacker, BattleActor $defender, Skill $skill): array
    {
        $rate = $skill->isJobArt() ? max(0.0, (float) ($skill->inherited_rate ?? 1.0)) : 1.0;
        $power = $skill->isJobArt()
            ? max(0, (int) round(((int) ($skill->power ?: 100)) * $rate))
            : max(0, (int) round((float) ($skill->power_multiplier ?: 0) * 100));

        if ((float) $skill->luk_power_rate > 0) {
            $power += (int) floor($attacker->luk * (float) $skill->luk_power_rate * $rate);
        }

        $damageType = $this->damageType($skill, $attacker);
        $baseHitCount = $this->hitCount($skill);
        $expectedHitCount = $baseHitCount;
        $effects = [];

        if ((int) $skill->extra_hit_chance_percent > 0) {
            $expectedHitCount += (int) $skill->extra_hit_chance_percent / 100;
            $effects[] = '追加攻撃期待値 +' . number_format((int) $skill->extra_hit_chance_percent / 100, 2) . 'Hit';
        }

        if ($power <= 0 || $baseHitCount <= 0 || in_array($damageType, ['heal', 'drop', 'gold'], true)) {
            return [
                'damage' => 0,
                'damage_type_label' => $this->damageTypeLabel($damageType),
                'effects' => $effects,
            ];
        }

        $hitPower = $skill->isJobArt()
            ? max(60, (int) round($power / max(1, $baseHitCount)))
            : $power;

        $damagePerHit = $this->skillDamagePerHit($attacker, $defender, $skill, $damageType, $hitPower);
        $damage = max(0, (int) floor($damagePerHit * $expectedHitCount));

        return [
            'damage' => $damage,
            'damage_type_label' => $this->damageTypeLabel($damageType) . ($expectedHitCount > 1 ? ' / ' . number_format($expectedHitCount, 2) . 'Hit期待値' : ''),
            'effects' => $effects,
        ];
    }

    private function skillDamagePerHit(BattleActor $attacker, BattleActor $defender, Skill $skill, string $damageType, int $power): int
    {
        $ignoreRate = max(0, min(100, (int) $skill->def_ignore_percent)) / 100;
        $def = (int) floor($defender->def * (1 - $ignoreRate));
        $spr = (int) floor($defender->spr * (1 - $ignoreRate));

        return match ($damageType) {
            'magical' => $this->averageDamage($attacker->mag, $spr, $power, $defender->damageReductionRate),
            'hybrid' => $this->averageDamage(
                (string) $skill->hybrid_scaling === 'max'
                    ? max($attacker->str, $attacker->mag)
                    : (int) floor(($attacker->str + $attacker->mag) / 2),
                $def,
                $power,
                $defender->damageReductionRate
            ),
            default => $this->averageDamage($attacker->str, $def, $power, $defender->damageReductionRate),
        };
    }

    private function averageDamage(int $attack, int $defense, int $power, int $damageReductionRate = 0): int
    {
        $damage = max(1, ($attack - ($defense / 2)) * ($power / 100));
        if ($damageReductionRate > 0) {
            $damage *= (1 - ($damageReductionRate / 100));
        }

        return max(1, (int) floor($damage));
    }

    private const ADAPTIVE_DAMAGE_TEMPLATES = ['DAMAGE_BUFF', 'DAMAGE_DEBUFF', 'MULTI_HIT', 'DAMAGE_GUARD_BARRIER'];

    private function damageType(Skill $skill, BattleActor $attacker): string
    {
        $template = (string) $skill->effect_template;
        if ($skill->isJobArt() && in_array($template, self::ADAPTIVE_DAMAGE_TEMPLATES, true)) {
            // 実戦闘(BattleService等)ではこれらのテンプレートは damage_type カラムを見ず、
            // 常に攻撃者の usesMagForNormalAttack() で物理/魔法を動的判定している。
            // プレビューも同じ判定に揃える。
            return $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        }

        $type = (string) ($skill->damage_type ?: 'physical');
        if ($type === 'support' && $skill->isJobArt()) {
            $type = JobArtEffectCatalog::damageType($template);
        }

        if ($type === 'support') {
            return $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        }

        if (in_array($type, ['gold', 'drop']) && ((float) $skill->power_multiplier > 0 || (int) $skill->power > 0)) {
            return 'physical';
        }

        return $type;
    }

    private function damageTypeLabel(string $damageType): string
    {
        return match ($damageType) {
            'magical' => '魔法',
            'hybrid' => '複合',
            'heal' => '回復',
            'drop' => 'ドロップ',
            'gold' => 'Gold',
            default => '物理',
        };
    }

    private function kindLabel(Skill $skill, JobClass $job): string
    {
        if (! $skill->isJobArt()) {
            return '必殺技';
        }

        return (int) $skill->job_id === (int) $job->id ? '職業奥義' : '継承奥義';
    }

    private function hitCount(Skill $skill): int
    {
        $hitCount = (int) $skill->hit_count;
        if ($hitCount > 0) {
            return $hitCount;
        }

        if ($skill->isJobArt()) {
            return JobArtEffectCatalog::hitCount((string) $skill->effect_template);
        }

        return 0;
    }

    private function applySkillEffects(BattleActor $attacker, BattleActor $defender, Skill $skill, int $damage): void
    {
        $rate = $skill->isJobArt() ? max(0.0, (float) ($skill->inherited_rate ?? 1.0)) : 1.0;
        $template = (string) $skill->effect_template;

        if ((int) $skill->self_damage_percent > 0) {
            $attacker->takeDamage(max(1, (int) floor($attacker->maxHp * ((int) $skill->self_damage_percent / 100) * $rate)));
        }
        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $attacker->healHp($this->templateHealAmount($attacker, $skill, $rate));
        }
        if (! in_array($template, ['HEAL', 'HEAL_CLEANSE'], true) && (int) $skill->heal_percent > 0) {
            $attacker->healHp(max(1, (int) floor($attacker->maxHp * ((int) $skill->heal_percent / 100) * $rate)));
        }
        if ($template === 'DRAIN' && $damage > 0 && (float) $skill->drain_hp_rate > 0) {
            $attacker->healHp(max(1, (int) floor($damage * (float) $skill->drain_hp_rate * $rate)));
        }
        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $attacker->damageReductionRate = max($attacker->damageReductionRate, $this->jobArtGuardReduction($skill, $rate));
        } elseif ((int) $skill->damage_reduction_percent > 0) {
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(25, max(1, (int) floor((int) $skill->damage_reduction_percent * $rate))));
        }

        $this->applySelfBuffs($attacker, $skill, $rate);
        $this->applyDebuffs($defender, $skill, $rate);
    }

    private function applySelfBuffs(BattleActor $attacker, Skill $skill, float $rate): void
    {
        if ((int) $skill->self_buff_percent > 0) {
            $buffRate = ((int) $skill->self_buff_percent / 100) * $rate;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + (int) floor($attacker->baseStr * $buffRate));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + (int) floor($attacker->baseMag * $buffRate));
            return;
        }

        $template = (string) $skill->effect_template;
        if (! in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            return;
        }

        $buffRate = $this->jobArtBuffRate($skill) * $rate;
        if ($template === 'MAGICAL_DAMAGE_BUFF' || $attacker->usesMagForNormalAttack()) {
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * $buffRate)));
            $attacker->spr = min((int) floor($attacker->baseSpr * 1.5), $attacker->spr + max(1, (int) floor($attacker->baseSpr * ($buffRate / 2))));
            return;
        }

        $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * $buffRate)));
        $attacker->def = min((int) floor($attacker->baseDef * 1.5), $attacker->def + max(1, (int) floor($attacker->baseDef * ($buffRate / 2))));
    }

    private function applyDebuffs(BattleActor $defender, Skill $skill, float $rate): void
    {
        $bossRate = (bool) ($defender->originalModel->is_boss ?? false) ? 0.5 : 1.0;
        $structuredRate = $rate * $bossRate;
        $applied = false;

        foreach ([
            'enemy_atk_down_percent' => ['prop' => 'str', 'base' => 'baseStr'],
            'enemy_mag_down_percent' => ['prop' => 'mag', 'base' => 'baseMag'],
            'enemy_def_down_percent' => ['prop' => 'def', 'base' => 'baseDef'],
            'enemy_spr_down_percent' => ['prop' => 'spr', 'base' => 'baseSpr'],
            'enemy_spd_down_percent' => ['prop' => 'agi', 'base' => 'baseAgi'],
        ] as $field => $config) {
            $percent = (int) ($skill->{$field} ?? 0);
            if ($percent <= 0) {
                continue;
            }

            $prop = $config['prop'];
            $base = $config['base'];
            $defender->{$prop} = max(1, $defender->{$prop} - (int) floor($defender->{$base} * (($percent * $structuredRate) / 100)));
            $applied = true;
        }

        $template = (string) $skill->effect_template;
        if (! $applied && in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true)) {
            $debuffRate = $this->jobArtBuffRate($skill) * $rate;
            $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * $debuffRate)));
            $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * ($debuffRate / 2))));
        }
        if (! $applied && $template === 'TIME_CONTROL_CURRENT_ONLY') {
            $debuffRate = $this->jobArtBuffRate($skill) * $rate;
            $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $debuffRate)));
        }
    }

    private function jobArtBuffRate(Skill $skill): float
    {
        $power = max(1, (int) ($skill->power ?: 100));

        return match (true) {
            $power >= 220 => 0.20,
            $power >= 150 => 0.15,
            default => 0.10,
        };
    }

    private function jobArtGuardReduction(Skill $skill, float $rate = 1.0): int
    {
        $base = (int) $skill->damage_reduction_percent > 0
            ? (int) $skill->damage_reduction_percent
            : min(25, max(10, (int) floor(((int) $skill->power ?: 100) / 10)));

        return min(25, max(1, (int) floor($base * $rate)));
    }

    private function templateHealAmount(BattleActor $attacker, Skill $skill, float $rate): int
    {
        $power = max(1, (int) ($skill->power ?: 100));

        return max(1, (int) floor($attacker->spr * ($power / 100) * $rate));
    }

    /**
     * @return array<int, string>
     */
    private function effectDescriptions(Skill $skill, int $damage, BattleActor $attacker): array
    {
        $rate = $skill->isJobArt() ? max(0.0, (float) ($skill->inherited_rate ?? 1.0)) : 1.0;
        $effects = [];

        foreach ([
            'heal_percent' => '最大HP%d%%回復',
            'mp_recover_percent' => '最大SP%d%%回復',
            'self_damage_percent' => '反動 最大HP%d%%',
            'damage_reduction_percent' => '次被ダメージ%d%%軽減',
            'self_buff_percent' => 'ATK/MAG%d%%上昇',
            'enemy_atk_down_percent' => '敵ATK%d%%低下',
            'enemy_mag_down_percent' => '敵MAG%d%%低下',
            'enemy_def_down_percent' => '敵DEF%d%%低下',
            'enemy_spr_down_percent' => '敵SPR%d%%低下',
            'enemy_spd_down_percent' => '敵SPD%d%%低下',
            'gold_bonus_percent' => 'Gold判定+%d%%',
            'drop_bonus_percent' => '素材判定+%d%%',
            'rare_bonus_percent' => 'レア判定+%d%%',
            'def_ignore_percent' => '敵DEF/SPR%d%%無視',
        ] as $field => $format) {
            $value = (int) ($skill->{$field} ?? 0);
            if ($field === 'heal_percent' && in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)) {
                continue;
            }
            if ($value > 0) {
                $effects[] = sprintf($format, max(1, (int) floor($value * $rate)));
            }
        }

        if (in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $effects[] = 'HP回復 ' . number_format($this->templateHealAmount($attacker, $skill, $rate));
        }

        if ((float) $skill->drain_hp_rate > 0 && $damage > 0) {
            $effects[] = '吸収HP ' . number_format(max(1, (int) floor($damage * (float) $skill->drain_hp_rate * $rate)));
        }
        if ((float) $skill->luk_power_rate > 0) {
            $effects[] = 'LUKで威力加算';
        }

        $template = (string) $skill->effect_template;
        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true) && (int) $skill->self_buff_percent <= 0) {
            $effects[] = '奥義テンプレートの自己バフ';
        }
        if (in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true) && ! $this->hasStructuredDebuff($skill)) {
            $effects[] = '奥義テンプレートの敵防御低下';
        }
        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true) && (int) $skill->damage_reduction_percent <= 0) {
            $effects[] = '奥義テンプレートの被ダメ軽減';
        }

        return $effects;
    }

    private function hasStructuredDebuff(Skill $skill): bool
    {
        return (int) $skill->enemy_atk_down_percent > 0
            || (int) $skill->enemy_mag_down_percent > 0
            || (int) $skill->enemy_def_down_percent > 0
            || (int) $skill->enemy_spr_down_percent > 0
            || (int) $skill->enemy_spd_down_percent > 0;
    }

    private function ratio(int $damage, int $baseline): float
    {
        return $baseline > 0 ? round($damage / $baseline, 2) : 0.0;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function stateSnapshot(BattleActor $attacker, BattleActor $defender): array
    {
        return [
            'attacker' => [
                'hp' => $attacker->hp,
                'max_hp' => $attacker->maxHp,
                'sp' => $attacker->mp,
                'max_sp' => $attacker->maxMp,
                'atk' => $attacker->str,
                'def' => $attacker->def,
                'mag' => $attacker->mag,
                'spr' => $attacker->spr,
                'spd' => $attacker->agi,
                'luk' => $attacker->luk,
                'damage_reduction' => $attacker->damageReductionRate,
            ],
            'defender' => [
                'hp' => $defender->hp,
                'max_hp' => $defender->maxHp,
                'atk' => $defender->str,
                'def' => $defender->def,
                'mag' => $defender->mag,
                'spr' => $defender->spr,
                'spd' => $defender->agi,
                'luk' => $defender->luk,
            ],
        ];
    }
}
