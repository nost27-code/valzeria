<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobClass extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bonus_hp' => 'integer',
        'bonus_mp' => 'integer',
        'bonus_str' => 'integer',
        'bonus_def' => 'integer',
        'bonus_mag' => 'integer',
        'bonus_spr' => 'integer',
        'bonus_spd' => 'integer',
        'bonus_luk' => 'integer',
        'bonus_gold_rate' => 'integer',
        'bonus_drop_rate' => 'integer',
        'bonus_critical_rate' => 'integer',
        'special_skill_rate' => 'integer',
        'is_hidden' => 'boolean',
        'is_active' => 'boolean',
        'affinity_physical' => 'float',
        'affinity_speed'    => 'float',
        'affinity_magical'  => 'float',
        'normal_attack_type' => 'string',
    ];
    public function requirements()
    {
        return $this->hasMany(JobRequirement::class, 'job_id');
    }

    public function masterBonuses()
    {
        return $this->hasMany(JobMasterBonus::class, 'job_id');
    }

    public function skill()
    {
        return $this->hasOne(Skill::class, 'job_id')->where('skill_type', 'special');
    }

    public function jobArts()
    {
        return $this->hasMany(Skill::class, 'job_id')->where('skill_type', 'job_art');
    }
}
