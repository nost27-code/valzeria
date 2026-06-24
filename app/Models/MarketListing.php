<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketListing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'unit_price' => 'integer',
        'listing_fee' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(Character::class, 'seller_character_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function transactions()
    {
        return $this->hasMany(MarketTransaction::class, 'listing_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
