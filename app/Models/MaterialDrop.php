<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialDrop extends Model
{
    protected $fillable = [
        'enemy_id',
        'material_id',
        'drop_rate',
        'drop_first_clear_only',
        'drop_timing',
        'is_active',
    ];

    protected $casts = [
        'drop_rate' => 'float',
        'drop_first_clear_only' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }
}
