<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobArtPresetSlot extends Model
{
    protected $fillable = [
        'job_art_preset_id',
        'slot_no',
        'skill_id',
        'activation_policy',
        'condition_key',
    ];

    protected $casts = [
        'job_art_preset_id' => 'integer',
        'slot_no' => 'integer',
        'skill_id' => 'integer',
        'activation_policy' => 'string',
        'condition_key' => 'string',
    ];

    public function preset()
    {
        return $this->belongsTo(JobArtPreset::class, 'job_art_preset_id');
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
