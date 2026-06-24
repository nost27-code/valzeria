<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_code',
        'name',
        'item_type',
        'result_item_name',
        'result_item_id',
        'required_level',
        'area_id',
        'area_name',
        'city_name',
        'element',
        'cost',
        'success_rate',
        'unlock_condition_type',
        'unlock_condition_value',
        'unlock_condition_desc',
        'materials',
        'key_material_code',
        'key_material_name',
        'consume_key_material',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'materials' => 'array',
        'consume_key_material' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function resultItem()
    {
        return $this->belongsTo(Item::class, 'result_item_id');
    }
}
