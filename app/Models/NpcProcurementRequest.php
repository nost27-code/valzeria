<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'reward_gold_on_complete' => 'integer',
        'reward_association_point_on_complete' => 'integer',
        'reward_items_json' => 'array',
        'display_order' => 'integer',
        'generated_for_date' => 'date',
    ];

    public function materials()
    {
        return $this->hasMany(NpcProcurementRequestMaterial::class);
    }

    public function deliveries()
    {
        return $this->hasMany(NpcProcurementDelivery::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function template()
    {
        return $this->belongsTo(NpcProcurementRequestTemplate::class, 'npc_procurement_request_template_id');
    }

    public function scopeActiveNow($query)
    {
        return $query
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->starts_at <= now()
            && $this->expires_at > now();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function progressPercent(): int
    {
        $materials = $this->relationLoaded('materials') ? $this->materials : $this->materials()->get();
        $required = (int) $materials->sum('required_quantity');
        $delivered = (int) $materials->sum('delivered_quantity');

        if ($required <= 0) {
            return 0;
        }

        return min(100, (int) floor(($delivered / $required) * 100));
    }

    public function remainingSeconds(): int
    {
        return max(0, now()->diffInSeconds($this->expires_at, false));
    }
}
