<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegionDepthDungeon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'entry_materials' => 'array',
        'base_stat_multipliers' => 'array',
        'ore_vein' => 'array',
        'entry_gold' => 'integer',
        'danger_increase_percent' => 'integer',
        'base_exp_multiplier' => 'float',
        'base_job_exp' => 'integer',
        'main_stat_per_danger' => 'float',
        'hp_per_danger' => 'float',
        'agi_luk_per_danger' => 'float',
        'exp_per_danger' => 'float',
        'exp_multiplier_cap' => 'float',
        'job_exp_cap' => 'integer',
        'danger_per_guaranteed_bonus' => 'integer',
        'remainder_percent_divisor' => 'integer',
        'public_log_minimum_danger' => 'integer',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function sourceArea()
    {
        return $this->belongsTo(Area::class, 'source_area_id');
    }

    public function baselineArea()
    {
        return $this->belongsTo(Area::class, 'baseline_area_id');
    }
}
