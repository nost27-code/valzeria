<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerNamelessEquipment extends Model
{
    public const DISPLAY_RANK = '遺装';

    protected $table = 'player_nameless_equipments';

    protected $guarded = [];

    protected $casts = [
        'forge_level' => 'integer',
        'base_power' => 'integer',
        'power_per_level' => 'integer',
        'is_equipped' => 'boolean',
        'growth_exp' => 'integer',
        'revision' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function relics()
    {
        return $this->hasMany(PlayerRelic::class, 'nameless_equipment_id')->orderBy('slot_number');
    }

    public function displayName(): string
    {
        return trim((string) $this->custom_name) !== ''
            ? (string) $this->custom_name
            : '名もなき' . $this->equipment_type;
    }

    public function isRenamed(): bool
    {
        return trim((string) $this->custom_name) !== '';
    }

    /** 一覧・選択肢用。保存名と通常装備のランクは変更しない。 */
    public function rankedDisplayName(): string
    {
        return '［'.self::DISPLAY_RANK.'］ '.$this->displayName().' +'.$this->forge_level;
    }

    public function kindLabel(): string
    {
        return \App\Services\NamelessEquipmentService::kindLabelFor($this->kind);
    }

    public function imagePath(): ?string
    {
        return config('nameless_equipment_images.'.$this->kind.'.'.$this->equipment_type);
    }

    public function power(): int
    {
        return $this->powerAt((int) $this->forge_level);
    }

    public function powerAt(int $level): int
    {
        return app(\App\Services\NamelessEquipmentPowerService::class)->powerAt($this, $level);
    }

    /** 武具本体の各能力。遺物の割合効果は含めない。 */
    public function performanceStats(): array
    {
        return $this->performanceStatsAt((int) $this->forge_level);
    }

    public function performanceStatsAt(int $level): array
    {
        return app(\App\Services\NamelessEquipmentPowerService::class)->statsAt($this, $level);
    }

    public function performanceLabel(?int $nextLevel = null): string
    {
        return app(\App\Services\NamelessEquipmentPowerService::class)->labelAt($this, (int) $this->forge_level, $nextLevel);
    }
}
