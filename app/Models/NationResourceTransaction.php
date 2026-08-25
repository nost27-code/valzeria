<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationResourceTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'points_delta' => 'integer',
            'balance_after' => 'integer',
            'development_exp_delta' => 'integer',
            'metadata' => 'array',
        ];
    }
}
