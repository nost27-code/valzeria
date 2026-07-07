<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementRequest extends Model
{
    public const PERSISTENT_UNTIL_COMPLETED_TITLES = [
        '旅装ギルド設立準備',
        '遠征外套の試作',
        '祭礼布と護符の修繕',
        '深層調査隊の防護布',
        '空織り調査隊の装備準備',
    ];

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

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
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
            ->where(function ($query) {
                $query->where('expires_at', '>', now())
                    ->orWhereIn('title', self::PERSISTENT_UNTIL_COMPLETED_TITLES);
            });
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->starts_at <= now()
            && ($this->expires_at > now() || $this->isPersistentUntilCompleted());
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

    public function isPersistentUntilCompleted(): bool
    {
        return in_array((string) $this->title, self::PERSISTENT_UNTIL_COMPLETED_TITLES, true);
    }
}
