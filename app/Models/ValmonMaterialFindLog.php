<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValmonMaterialFindLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];
}
