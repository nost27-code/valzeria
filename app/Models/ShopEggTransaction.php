<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopEggTransaction extends Model
{
    protected $guarded = [];
    protected $casts = ['sale_price' => 'integer', 'sold_at' => 'datetime'];
}
