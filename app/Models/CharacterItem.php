<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = [
        'character_id', 'item_id', 'affix_prefix_id', 'affix_suffix_id', 'affix_quality',
        'affix_hp_bonus', 'affix_str_bonus', 'affix_def_bonus', 'affix_mag_bonus',
        'affix_spr_bonus', 'affix_agi_bonus', 'affix_luk_bonus',
        'killer_species_key', 'killer_damage_rate',
        'resist_species_key', 'species_damage_reduction_rate', 'affix_generated_at',
        'is_equipped', 'is_stored', 'is_locked', 'enhance_level', 'equipped_slot', 'acquired_from',
        'affix_prefix_level', 'affix_suffix_level', 'market_listing_id', 'market_relistable_at', 'is_tradeable',
        'weapon_offense_scale_version', 'armor_performance_scale_version', 'accessory_performance_scale_version',
    ];

    protected $casts = [
        'is_equipped' => 'boolean',
        'is_stored' => 'boolean',
        'is_locked' => 'boolean',
        'is_tradeable' => 'boolean',
        'enhance_level' => 'integer',
        'affix_prefix_level' => 'integer',
        'affix_suffix_level' => 'integer',
        'market_relistable_at' => 'datetime',
        'affix_hp_bonus' => 'integer',
        'affix_str_bonus' => 'integer',
        'affix_def_bonus' => 'integer',
        'affix_mag_bonus' => 'integer',
        'affix_spr_bonus' => 'integer',
        'affix_agi_bonus' => 'integer',
        'affix_luk_bonus' => 'integer',
        'weapon_offense_scale_version' => 'integer',
        'armor_performance_scale_version' => 'integer',
        'accessory_performance_scale_version' => 'integer',
        'killer_damage_rate' => 'float',
        'species_damage_reduction_rate' => 'float',
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

    public function isMarketListed(): bool
    {
        return $this->market_listing_id !== null;
    }

    public function displayName(bool $includeRank = true): string
    {
        $name = $this->baseAffixedName($includeRank);
        $enhanceLevel = (int) ($this->enhance_level ?? 0);

        return $enhanceLevel > 0 ? "{$name} +{$enhanceLevel}" : $name;
    }

    private const AFFIX_ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];

    public function baseAffixedName(bool $includeRank = true): string
    {
        $name = $this->item?->name ?? '不明な装備';
        $prefixName = $this->affixPrefix?->name;
        $suffixName = $this->affixSuffix?->name;

        if ($prefixName) {
            $name = self::withAffixLevel($prefixName, $this->effectiveAffixPrefixLevel()) . $name;
        }

        if ($suffixName) {
            $name .= '・' . self::withAffixLevel($suffixName, $this->effectiveAffixSuffixLevel());
        }

        if ($this->affix_quality === 'good') {
            $name .= '【良品】';
        } elseif ($this->affix_quality === 'excellent') {
            $name .= '【逸品】';
        }

        $rank = $this->item?->weapon_rank;
        if ($includeRank && $rank) {
            $name = '[' . strtoupper($rank) . '] ' . $name;
        }

        return $name;
    }

    /**
     * 銘・特攻の名前にランク数値（ローマ数字）を差し込む。「生命の」のように末尾が「の」の場合は
     * 「生命Iの」のように数字を「の」の前に挿入する。
     */
    private static function withAffixLevel(string $name, int $level): string
    {
        $roman = self::AFFIX_ROMAN[$level] ?? '';
        if ($roman === '') {
            return $name;
        }

        if (mb_substr($name, -1) === 'の') {
            return mb_substr($name, 0, -1) . $roman . 'の';
        }

        return $name . $roman;
    }

    public function effectiveAffixPrefixLevel(): int
    {
        return $this->effectiveAffixLevel('affix_prefix_id', 'affix_prefix_level');
    }

    public function effectiveAffixSuffixLevel(): int
    {
        return $this->effectiveAffixLevel('affix_suffix_id', 'affix_suffix_level');
    }

    public function effectiveKillerDamageRate(): float
    {
        if ($this->affix_suffix_id && $this->item?->type === 'weapon') {
            return app(\App\Services\EquipmentAffixRulesService::class)->weaponKillerDamageRate(
                $this->item,
                $this->effectiveAffixSuffixLevel(),
                $this->affix_quality,
            );
        }

        return max(0.0, (float) ($this->killer_damage_rate ?? 0));
    }

    /**
     * 段階制移行前に生成された個体（良品5%/逸品6%固定）が下がらないよう、保存値と動的算出の高い方を使う。
     */
    public function effectiveSpeciesDamageReductionRate(): float
    {
        $storedRate = max(0.0, (float) ($this->species_damage_reduction_rate ?? 0));

        if ($this->affix_suffix_id && $this->item?->type === 'armor') {
            return max($storedRate, app(\App\Services\EquipmentAffixRulesService::class)->armorSpeciesResistRate(
                $this->item,
                $this->effectiveAffixSuffixLevel(),
                $this->affix_quality,
            ));
        }

        return $storedRate;
    }

    public function hasAffix(): bool
    {
        return $this->affix_prefix_id !== null
            || $this->affix_suffix_id !== null
            || (float) ($this->killer_damage_rate ?? 0) > 0
            || (float) ($this->species_damage_reduction_rate ?? 0) > 0
            || array_sum($this->affixStatBonuses()) !== 0;
    }

    public function affixStatBonuses(): array
    {
        if ($this->affix_prefix_id && $this->item && $this->affixPrefix) {
            return app(\App\Services\EquipmentAffixRulesService::class)->prefixBonuses(
                $this->item,
                $this->affixPrefix,
                $this->effectiveAffixPrefixLevel(),
                $this->affix_quality,
                (int) ($this->enhance_level ?? 0),
            );
        }

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

        return array_merge($lines, $this->slayerEffectLines());
    }

    /**
     * 武具そのものの性能。銘・特攻の補正は含めない。
     * @return list<string>
     */
    public function basePerformanceLines(): array
    {
        $stats = \App\Services\EquipmentEnhancementService::enhancedStatTotalsForItem(
            $this->item,
            (int) ($this->enhance_level ?? 0),
        );

        return $this->formatStatLines($stats);
    }

    /**
     * 接頭辞の銘による能力補正。
     * @return list<string>
     */
    public function engravingEffectLines(): array
    {
        return $this->formatStatLines($this->affixStatBonuses());
    }

    /**
     * 接尾辞の種族特攻・種族耐性。
     * @return list<string>
     */
    public function slayerEffectLines(): array
    {
        $lines = [];

        $killerDamageRate = $this->effectiveKillerDamageRate();
        if ($this->killer_species_key && $killerDamageRate > 0) {
            $speciesLabel = $this->speciesLabel((string) $this->killer_species_key);
            $lines[] = '種族が' . $speciesLabel . 'の敵への与ダメージ +' . $this->percentageLabel($killerDamageRate) . '%';
        }

        $resistRate = $this->effectiveSpeciesDamageReductionRate();
        if ($this->resist_species_key && $resistRate > 0) {
            $speciesLabel = $this->speciesLabel((string) $this->resist_species_key);
            $lines[] = '種族が' . $speciesLabel . 'の敵からの被ダメージ -' . $this->percentageLabel($resistRate) . '%';
        }

        return $lines;
    }

    /**
     * @param array<string, int> $stats
     * @return list<string>
     */
    private function formatStatLines(array $stats): array
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
        foreach ($stats as $key => $value) {
            if ((int) $value !== 0 && isset($labels[$key])) {
                $lines[] = $labels[$key] . ((int) $value > 0 ? ' +' : ' ') . (int) $value;
            }
        }

        return $lines;
    }

    private function effectiveAffixLevel(string $idColumn, string $levelColumn): int
    {
        if ($this->{$idColumn} === null) {
            return 0;
        }

        return app(\App\Services\EquipmentAffixRulesService::class)->clampLevel(
            $this->item,
            (int) ($this->{$levelColumn} ?? 1),
        );
    }

    private function percentageLabel(float $rate): string
    {
        $percentage = round($rate * 100, 1);

        return $percentage === floor($percentage)
            ? (string) (int) $percentage
            : number_format($percentage, 1, '.', '');
    }

    private function speciesLabel(string $speciesKey): string
    {
        return [
            'aquatic' => '水棲',
            'beast' => '獣',
            'demon' => '悪魔',
            'dragon' => '竜',
            'flying' => '飛行',
            'giant' => '巨人',
            'goblin' => '小鬼',
            'insect' => '虫',
            'machine' => '機械',
            'mage' => '魔法型',
            'plant' => '植物',
            'slime' => 'スライム',
            'soldier' => '人型',
            'spirit' => '精霊',
            'standard' => '通常',
            'undead' => '不死',
        ][$speciesKey] ?? $speciesKey;
    }
}
