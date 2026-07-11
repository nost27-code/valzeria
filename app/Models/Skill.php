<?php

namespace App\Models;

use App\Support\JobArtEffectCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'skill_type',
        'learn_rank',
        'art_cost',
        'art_category',
        'limit_group',
        'effect_template',
        'element',
        'power',
        'duration_turns',
        'cooldown_turns',
        'max_uses_per_battle',
        'inherit_on_master',
        'inherit_policy',
        'inherited_rate',
        'pve_enabled',
        'boss_enabled',
        'champ_enabled',
        'reward_scope',
        'sort_order',
        'mp_cost',
        'activation_rate',
        'sp_cost_base',
        'sp_cost_rate',
        'sp_cost_fixed',
        'name',
        'trigger_rate',
        'damage_type',
        'power_multiplier',
        'hit_count',
        'extra_hit_chance_percent',
        'luk_power_rate',
        'hybrid_scaling',
        'heal_percent',
        'self_damage_percent',
        'gold_bonus_percent',
        'drop_bonus_percent',
        'rare_bonus_percent',
        'def_ignore_percent',
        'damage_reduction_percent',
        'self_buff_percent',
        'enemy_atk_down_percent',
        'enemy_mag_down_percent',
        'enemy_def_down_percent',
        'enemy_spr_down_percent',
        'enemy_spd_down_percent',
        'drain_hp_rate',
        'mp_recover_percent',
        'activation_phrase',
        'activation_description',
        'description',
        'memo',
    ];

    protected $casts = [
        'learn_rank' => 'integer',
        'art_cost' => 'integer',
        'power' => 'integer',
        'duration_turns' => 'integer',
        'cooldown_turns' => 'integer',
        'max_uses_per_battle' => 'integer',
        'inherit_on_master' => 'boolean',
        'inherited_rate' => 'float',
        'pve_enabled' => 'boolean',
        'boss_enabled' => 'boolean',
        'champ_enabled' => 'boolean',
        'sort_order' => 'integer',
        'activation_rate' => 'integer',
        'sp_cost_base' => 'integer',
        'sp_cost_rate' => 'float',
        'sp_cost_fixed' => 'integer',
        'trigger_rate' => 'integer',
        'power_multiplier' => 'float',
        'hit_count' => 'integer',
        'extra_hit_chance_percent' => 'integer',
        'luk_power_rate' => 'float',
        'hybrid_scaling' => 'string',
        'heal_percent' => 'integer',
        'self_damage_percent' => 'integer',
        'gold_bonus_percent' => 'integer',
        'drop_bonus_percent' => 'integer',
        'rare_bonus_percent' => 'integer',
        'def_ignore_percent' => 'integer',
        'damage_reduction_percent' => 'integer',
        'self_buff_percent' => 'integer',
        'enemy_atk_down_percent' => 'integer',
        'enemy_mag_down_percent' => 'integer',
        'enemy_def_down_percent' => 'integer',
        'enemy_spr_down_percent' => 'integer',
        'enemy_spd_down_percent' => 'integer',
        'drain_hp_rate' => 'float',
        'mp_recover_percent' => 'integer',
    ];

    public function effectiveActivationRate(): int
    {
        return (int) ($this->activation_rate ?? $this->trigger_rate ?? 0);
    }

    public function isJobArt(): bool
    {
        return $this->skill_type === 'job_art';
    }

    public function isTimeLimited(): bool
    {
        return $this->limit_group === 'TIME';
    }

    public function isRewardArt(): bool
    {
        return $this->limit_group === 'REWARD';
    }

    public function isHealArt(): bool
    {
        return $this->limit_group === 'HEAL';
    }

    public function isGutsArt(): bool
    {
        return $this->limit_group === 'GUTS';
    }

    public function jobArtEffectLabel(): string
    {
        return JobArtEffectCatalog::label((string) $this->effect_template) ?? match ((string) $this->art_category) {
            'attack' => '攻撃',
            'buff' => 'バフ',
            'debuff' => 'デバフ',
            'guard' => '防御',
            'heal' => '回復',
            default => '奥義',
        };
    }

    public function jobArtLimitLabel(): ?string
    {
        return match ((string) $this->limit_group) {
            'HEAL' => '回復枠',
            'REWARD' => '報酬枠',
            'TIME' => '時空限定',
            'GUTS' => '踏みとどまり枠',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public function jobArtNumericEffectLabels(): array
    {
        if (! $this->isJobArt()) {
            return [];
        }

        $template = (string) $this->effect_template;
        $power = max(0, (int) ($this->power ?: 0));
        $labels = [];

        if (JobArtEffectCatalog::dealsDamage($template)) {
            $labels[] = "威力 {$power}%";
            $hitCount = max(1, (int) $this->hit_count);
            if ($hitCount > 1) {
                $labels[] = "{$hitCount}Hit";
            }
        }

        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $labels[] = "HP回復 SPR×{$power}%";
        }

        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            $buff = (int) $this->self_buff_percent;
            if ($buff <= 0) {
                $buff = $this->jobArtTierPercent($power);
            }
            $labels[] = '自己強化 主+' . $buff . '% / 副+' . intdiv($buff, 2) . '%';
        }

        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $reduction = (int) $this->damage_reduction_percent;
            if ($reduction <= 0) {
                $reduction = min(25, max(10, intdiv(max(1, $power), 10)));
            }
            $labels[] = "被ダメージ -{$reduction}%";
        }

        $debuffLabels = [
            'enemy_atk_down_percent' => '敵ATK',
            'enemy_mag_down_percent' => '敵MAG',
            'enemy_def_down_percent' => '敵DEF',
            'enemy_spr_down_percent' => '敵SPR',
            'enemy_spd_down_percent' => '敵SPD',
        ];
        $hasStructuredDebuff = false;
        foreach ($debuffLabels as $field => $label) {
            $percent = (int) $this->{$field};
            if ($percent > 0) {
                $labels[] = "{$label} -{$percent}%";
                $hasStructuredDebuff = true;
            }
        }

        if (! $hasStructuredDebuff && in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true)) {
            $debuff = $this->jobArtTierPercent($power);
            $labels[] = "敵DEF -{$debuff}% / SPR -" . intdiv($debuff, 2) . '%';
        }

        foreach ([
            'def_ignore_percent' => '敵DEF/SPR無視',
            'heal_percent' => '最大HP回復',
            'mp_recover_percent' => '最大SP回復',
            'gold_bonus_percent' => 'Gold判定',
            'drop_bonus_percent' => '素材判定',
            'rare_bonus_percent' => 'レア判定',
        ] as $field => $label) {
            $percent = (int) $this->{$field};
            if ($percent > 0) {
                $labels[] = "{$label} +{$percent}%";
            }
        }

        return array_values(array_unique($labels));
    }

    private function jobArtTierPercent(int $power): int
    {
        return match (true) {
            $power >= 200 => 20,
            $power >= 140 => 15,
            default => 10,
        };
    }

    public function spCostForMaxSp(int $maxSp): int
    {
        if ($this->sp_cost_fixed !== null) {
            return max(0, (int) $this->sp_cost_fixed);
        }

        $base = (int) ($this->sp_cost_base ?? 0);
        $rate = (float) ($this->sp_cost_rate ?? 0);

        if ($base > 0 || $rate > 0) {
            return max(0, (int) ceil($base + max(0, $maxSp) * $rate));
        }

        return max(0, (int) ($this->mp_cost ?? 0));
    }

    public function specialSkillSpCostForMaxSp(int $maxSp): int
    {
        $baseCost = $this->spCostForMaxSp($maxSp);
        if ($baseCost <= 0) {
            return 0;
        }

        return max(1, (int) ceil($baseCost / 2));
    }

    public function jobArtBaseSpCostForMaxSp(int $maxSp): int
    {
        if ($this->sp_cost_fixed !== null) {
            return max(0, (int) $this->sp_cost_fixed);
        }

        $rate = (float) ($this->sp_cost_rate ?? 0);
        if ($rate > 0) {
            return max(0, (int) ceil(max(0, $maxSp) * $rate));
        }

        return 0;
    }

    public function jobArtSpCostForMaxSp(int $maxSp, string $origin = 'current'): int
    {
        $hasCost = $this->sp_cost_fixed !== null || (float) ($this->sp_cost_rate ?? 0) > 0;
        if (!$hasCost) {
            return 0;
        }

        $baseCost = $this->jobArtBaseSpCostForMaxSp($maxSp);
        $multiplier = 1.0;

        if ($origin === 'current') {
            $multiplier = 0.8;
        } elseif ($origin === 'inherited' && in_array((string) $this->limit_group, ['HEAL', 'REWARD', 'GUTS'], true)) {
            $multiplier = 1.2;
        }

        return max(1, (int) ceil($baseCost * $multiplier));
    }

    public function jobClass()
    {
        return $this->belongsTo(JobClass::class, 'job_id');
    }

    public function jobSkills()
    {
        return $this->hasMany(JobSkill::class);
    }
}
