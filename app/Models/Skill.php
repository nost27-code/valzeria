<?php

namespace App\Models;

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
        'heal_percent',
        'self_damage_percent',
        'gold_bonus_percent',
        'drop_bonus_percent',
        'def_ignore_percent',
        'damage_reduction_percent',
        'enemy_def_down_percent',
        'enemy_spr_down_percent',
        'enemy_spd_down_percent',
        'mp_recover_percent',
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
        'heal_percent' => 'integer',
        'self_damage_percent' => 'integer',
        'gold_bonus_percent' => 'integer',
        'drop_bonus_percent' => 'integer',
        'def_ignore_percent' => 'integer',
        'damage_reduction_percent' => 'integer',
        'enemy_def_down_percent' => 'integer',
        'enemy_spr_down_percent' => 'integer',
        'enemy_spd_down_percent' => 'integer',
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
        return match ((string) $this->effect_template) {
            'PHYSICAL_DAMAGE', 'MAGICAL_DAMAGE', 'HYBRID_DAMAGE' => '攻撃',
            'MULTI_HIT' => '連撃',
            'DAMAGE_BUFF' => '攻撃+バフ',
            'MAGICAL_DAMAGE_BUFF' => '魔法+バフ',
            'DAMAGE_DEBUFF' => '攻撃+デバフ',
            'SELF_BUFF' => 'バフ',
            'ENEMY_DEBUFF' => 'デバフ',
            'GUARD_BARRIER' => '防御',
            'HEAL', 'HEAL_CLEANSE' => '回復',
            'DRAIN' => '吸収',
            'GUTS' => '踏みとどまり',
            'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED' => '報酬',
            'TIME_CONTROL_CURRENT_ONLY' => '時空',
            default => match ((string) $this->art_category) {
                'attack' => '攻撃',
                'buff' => 'バフ',
                'debuff' => 'デバフ',
                'guard' => '防御',
                'heal' => '回復',
                default => '奥義',
            },
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
