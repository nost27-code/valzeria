<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobArtPreset extends Model
{
    protected $fillable = [
        'character_id',
        'name',
        'current_job_id',
        'source_context',
        'sp_policy',
    ];

    protected $casts = [
        'character_id' => 'integer',
        'current_job_id' => 'integer',
        'sp_policy' => 'string',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function slots()
    {
        return $this->hasMany(JobArtPresetSlot::class)->orderBy('slot_no');
    }
}
