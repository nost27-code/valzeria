<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = [
        'character_id', 'item_id', 'affix_prefix_id', 'affix_suffix_id', 'affix_quality',
        'affix_hp_bonus', 'affix_str_bonus', 'affix_def_bonus', 'affix_mag_bonus',
        'affix_spr_bonus', 'affix_agi_bonus', 'affix_luk_bonus',
        'killer_species_key', 'killer_damage_rate', 'affix_generated_at',
        'is_equipped', 'is_stored', 'is_locked', 'enhance_level', 'equipped_slot', 'acquired_from'
    ];

    protected $casts = [
        'is_equipped' => 'boolean',
        'is_stored' => 'boolean',
        'is_locked' => 'boolean',
        'enhance_level' => 'integer',
        'affix_hp_bonus' => 'integer',
        'affix_str_bonus' => 'integer',
        'affix_def_bonus' => 'integer',
        'affix_mag_bonus' => 'integer',
        'affix_spr_bonus' => 'integer',
        'affix_agi_bonus' => 'integer',
        'affix_luk_bonus' => 'integer',
        'killer_damage_rate' => 'float',
        'affix_generated_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function affixPrefix()
    {
        return $this->belongsTo(EquipmentAffixPrefix::class, 'affix_prefix_id');
    }

    public function affixSuffix()
    {
        return $this->belongsTo(EquipmentAffixSuffix::class, 'affix_suffix_id');
    }

    public function displayName(): string
    {
        $name = $this->baseAffixedName();
        $enhanceLevel = (int) ($this->enhance_level ?? 0);

        return $enhanceLevel > 0 ? "{$name} +{$enhanceLevel}" : $name;
    }

    public function baseAffixedName(): string
    {
        $name = $this->item?->name ?? '不明な装備';
        $prefixName = $this->affixPrefix?->name;
        $suffixName = $this->affixSuffix?->name;

        if ($prefixName) {
            $name = $prefixName . $name;
        }

        if ($suffixName) {
            $name .= '・' . $suffixName;
        }

        if ($this->affix_quality === 'good') {
            $name .= '【良品】';
        } elseif ($this->affix_quality === 'excellent') {
            $name .= '【逸品】';
        }

        return $name;
    }

    public function hasAffix(): bool
    {
        return $this->affix_prefix_id !== null
            || $this->affix_suffix_id !== null
            || (float) ($this->killer_damage_rate ?? 0) > 0
            || array_sum($this->affixStatBonuses()) !== 0;
    }

    public function affixStatBonuses(): array
    {
        return [
            'hp' => (int) ($this->affix_hp_bonus ?? 0),
            'str' => (int) ($this->affix_str_bonus ?? 0),
            'def' => (int) ($this->affix_def_bonus ?? 0),
            'mag' => (int) ($this->affix_mag_bonus ?? 0),
            'spr' => (int) ($this->affix_spr_bonus ?? 0),
            'agi' => (int) ($this->affix_agi_bonus ?? 0),
            'luk' => (int) ($this->affix_luk_bonus ?? 0),
        ];
    }

    public function affixEffectLines(): array
    {
        $labels = [
            'hp' => 'HP',
            'str' => '攻撃',
            'def' => '防御',
            'mag' => '魔力',
            'spr' => '精神',
            'agi' => '敏捷',
            'luk' => '運',
        ];

        $lines = [];
        foreach ($this->affixStatBonuses() as $key => $value) {
            if ($value !== 0) {
                $lines[] = $labels[$key] . ($value > 0 ? '+' : '') . $value;
            }
        }

        if ($this->killer_species_key && (float) ($this->killer_damage_rate ?? 0) > 0) {
            $suffixName = $this->affixSuffix?->name;
            $familyLabel = $suffixName ? "{$suffixName}対象" : $this->killer_species_key;
            $lines[] = $familyLabel . 'への与ダメージ +' . (int) round(((float) $this->killer_damage_rate) * 100) . '%';
        }

        return $lines;
    }
}
