<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminItemGrantLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'enhance_level' => 'integer',
        'metadata' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function adminUser()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
