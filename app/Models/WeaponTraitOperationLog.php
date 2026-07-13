<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class WeaponTraitOperationLog extends Model
{
    public const OPERATION_LABELS = [
        'engraving_forge' => '銘鍛錬',
        'slayer_forge' => '特攻磨き',
        'dual_forge' => '重ね鍛錬',
        'engraving_transfer' => '銘移し',
        'slayer_transfer' => '特攻移し',
    ];

    protected $fillable = [
        'character_id',
        'operation',
        'base_character_item_id',
        'material_character_item_id',
        'before_snapshot',
        'material_snapshot',
        'after_snapshot',
        'gold_cost',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'material_snapshot' => 'array',
        'after_snapshot' => 'array',
        'gold_cost' => 'integer',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function operationLabel(): string
    {
        return self::OPERATION_LABELS[$this->operation] ?? $this->operation;
    }

    public function baseDisplayName(): string
    {
        return (string) (data_get($this->before_snapshot, 'display_name') ?: '不明なベース武器');
    }

    public function materialDisplayName(): string
    {
        return (string) (data_get($this->material_snapshot, 'display_name') ?: '不明な素材武器');
    }

    public function completedDisplayName(): string
    {
        return (string) (data_get($this->after_snapshot, 'display_name') ?: '不明な完成武器');
    }
}
