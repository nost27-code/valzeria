<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Services\Battle\WeaponOffenseCalculator;
use App\Services\JobService;
use App\Services\EquipmentEnhancementService;

class CharacterStatusService
{
    private static array $requestCache = [];

    public function getFinalStats(Character $character): array
    {
        if (isset(self::$requestCache[$character->id])) {
            return self::$requestCache[$character->id];
        }
        return self::$requestCache[$character->id] = $this->computeFinalStats($character);
    }

    private function computeFinalStats(Character $character): array
    {
        $jobService = new JobService();
        $jobStats = $jobService->calculateFinalStats($character); // [hp, atk, def, mag, spd, luck] (基礎値 + マスターボーナス)

        $jobLevel = 1;
        $jobClass = $character->jobClass()->first(); // 確実に最新のDB状態を取得
        if ($character->current_job_id) {
            $history = $character->jobHistories()->where('job_class_id', $character->current_job_id)->first();
            if ($history) {
                $jobLevel = $history->job_level;
            }
        }

        // 現在職のレベルによる固定ボーナス（現行仕様踏襲）
        $job_hp_bonus = $jobClass ? (int)(($jobClass->bonus_hp ?? 0) * $jobLevel * 0.5) : 0;
        $job_mp_bonus = $jobClass ? (int)(($jobClass->bonus_mp ?? 0) * $jobLevel * 0.5) : 0;
        $job_str_bonus = $jobClass ? (int)(($jobClass->bonus_str ?? 0) * $jobLevel * 0.5) : 0;
        $job_def_bonus = $jobClass ? (int)(($jobClass->bonus_def ?? 0) * $jobLevel * 0.5) : 0;
        $job_spd_bonus = $jobClass ? (int)(($jobClass->bonus_spd ?? 0) * $jobLevel * 0.5) : 0;
        $job_mag_bonus = $jobClass ? (int)(($jobClass->bonus_mag ?? 0) * $jobLevel * 0.5) : 0;
        $job_spr_bonus = $jobClass ? (int)(($jobClass->bonus_spr ?? 0) * $jobLevel * 0.5) : 0;
        $job_luk_bonus = $jobClass ? (int)(($jobClass->bonus_luk ?? 0) * $jobLevel * 0.5) : 0;

        // 現在職のレート（倍率補正）は仕様変更により廃止

        $markBonuses = app(MonsterMarkService::class)->permanentBonuses($character);

        // ---- パス1: 装備を一切含まない能力 ----
        // 武器を外した基礎能力との差分表示にも使うため、モンスターマークを含めてここで確定する。
        $preEquip = [
            'hp' => (int) $jobStats['hp'] + $job_hp_bonus + (int) ($markBonuses['hp'] ?? 0),
            'mp' => (int) ($jobStats['mp'] ?? 0) + $job_mp_bonus + (int) ($markBonuses['mp'] ?? 0),
            'str' => (int) $jobStats['atk'] + $job_str_bonus + (int) ($markBonuses['str'] ?? 0),
            'def' => (int) $jobStats['def'] + $job_def_bonus + (int) ($markBonuses['def'] ?? 0),
            'agi' => (int) $jobStats['spd'] + $job_spd_bonus + (int) ($markBonuses['agi'] ?? 0),
            'mag' => (int) $jobStats['mag'] + $job_mag_bonus + (int) ($markBonuses['mag'] ?? 0),
            'spr' => (int) $jobStats['spr'] + $job_spr_bonus + (int) ($markBonuses['spr'] ?? 0),
            'luk' => (int) $jobStats['luck'] + $job_luk_bonus + (int) ($markBonuses['luk'] ?? 0),
        ];

        // ---- パス2: 武器・防具由来の主能力を分離して集計 ----
        $hp_equip = 0; $mp_equip = 0; $atk_equip = 0; $def_equip = 0;
        $spd_equip = 0; $mag_equip = 0; $spr_equip = 0; $luk_equip = 0;
        $weaponStr = 0; $weaponMag = 0;
        $armorDef = 0; $armorSpr = 0;

        $equippedItems = $character->characterItems()->where('is_equipped', true)->with(['item', 'affixPrefix'])->get();
        foreach ($equippedItems as $charItem) {
            if ($charItem->item) {
                if ($this->isLegacyMarkItem($charItem->item)) {
                    continue;
                }

                $enhanceLevel = (int) ($charItem->enhance_level ?? 0);
                $enhancedStats = EquipmentEnhancementService::enhancedStatTotalsForItem($charItem->item, $enhanceLevel);

                $hp_equip += $enhancedStats['hp'] ?? 0;
                $mp_equip += $enhancedStats['mp'] ?? 0;
                $isWeapon = (string) ($charItem->item->type ?? '') === 'weapon';
                $isArmor = (string) ($charItem->item->type ?? '') === 'armor';
                if ($isWeapon) {
                    $weaponStr += (int) ($enhancedStats['str'] ?? 0);
                    $weaponMag += (int) ($enhancedStats['mag'] ?? 0);
                } else {
                    $atk_equip += (int) ($enhancedStats['str'] ?? 0);
                    $mag_equip += (int) ($enhancedStats['mag'] ?? 0);
                }
                if ($isArmor) {
                    $armorDef += (int) ($enhancedStats['def'] ?? 0);
                    $armorSpr += (int) ($enhancedStats['spr'] ?? 0);
                } else {
                    $def_equip += (int) ($enhancedStats['def'] ?? 0);
                    $spr_equip += (int) ($enhancedStats['spr'] ?? 0);
                }
                $spd_equip += $enhancedStats['agi'] ?? 0;
                $luk_equip += $enhancedStats['luk'] ?? 0;

                $affixBonuses = $charItem->affixStatBonuses();
                $hp_equip += (int) ($affixBonuses['hp'] ?? 0);
                if ($isWeapon) {
                    $weaponStr += (int) ($affixBonuses['str'] ?? 0);
                    $weaponMag += (int) ($affixBonuses['mag'] ?? 0);
                } else {
                    $atk_equip += (int) ($affixBonuses['str'] ?? 0);
                    $mag_equip += (int) ($affixBonuses['mag'] ?? 0);
                }
                if ($isArmor) {
                    $armorDef += (int) ($affixBonuses['def'] ?? 0);
                    $armorSpr += (int) ($affixBonuses['spr'] ?? 0);
                } else {
                    $def_equip += (int) ($affixBonuses['def'] ?? 0);
                    $spr_equip += (int) ($affixBonuses['spr'] ?? 0);
                }
                $spd_equip += (int) ($affixBonuses['agi'] ?? 0);
                $luk_equip += (int) ($affixBonuses['luk'] ?? 0);
            }
        }

        // 実効攻撃性能 = 武器を除いた基礎能力 × (0.80 + 8倍化後の武器能力 ÷ 2400)
        $weaponBaseStr = $preEquip['str'] + $atk_equip;
        $weaponBaseMag = $preEquip['mag'] + $mag_equip;
        $offenseCalculator = app(WeaponOffenseCalculator::class);
        $finalStr = $offenseCalculator->calculateEffectiveOffense($weaponBaseStr, $weaponStr);
        $finalMag = $offenseCalculator->calculateEffectiveOffense($weaponBaseMag, $weaponMag);

        // 実効防御性能 = 防具を除いた基礎能力 + max(8倍化前相当の補正, 8倍化後の比例補正)
        $armorBaseDef = $preEquip['def'] + $def_equip;
        $armorBaseSpr = $preEquip['spr'] + $spr_equip;
        $finalDef = $this->effectiveArmorStat($armorBaseDef, $armorDef, $offenseCalculator);
        $finalSpr = $this->effectiveArmorStat($armorBaseSpr, $armorSpr, $offenseCalculator);

        $unarmedStr = $offenseCalculator->calculateEffectiveOffense($preEquip['str'], 0);
        $unarmedMag = $offenseCalculator->calculateEffectiveOffense($preEquip['mag'], 0);
        $unarmoredDef = $preEquip['def'];
        $unarmoredSpr = $preEquip['spr'];

        return [
            'max_hp' => max(1, $preEquip['hp'] + $hp_equip),
            'max_mp' => max(0, $preEquip['mp'] + $mp_equip),
            'str' => max(1, $finalStr),
            'def' => max(0, $finalDef),
            'agi' => max(1, $preEquip['agi'] + $spd_equip),
            'mag' => max(0, $finalMag),
            'spr' => max(0, $finalSpr),
            'luk' => max(0, $preEquip['luk'] + $luk_equip),
            'bonuses' => [
                'hp' => $hp_equip,
                'mp' => $mp_equip,
                'str' => $finalStr - $unarmedStr,
                'def' => $finalDef - $unarmoredDef,
                'agi' => $spd_equip,
                'mag' => $finalMag - $unarmedMag,
                'spr' => $finalSpr - $unarmoredSpr,
                'luk' => $luk_equip,
            ],
            'monster_mark_bonuses' => $markBonuses,
            'pre_equipment' => $preEquip,
            'weapon_base' => ['str' => $weaponBaseStr, 'mag' => $weaponBaseMag],
            'weapon_offense' => ['str' => $weaponStr, 'mag' => $weaponMag],
            'armor_base' => ['def' => $armorBaseDef, 'spr' => $armorBaseSpr],
            'armor_defense' => ['def' => $armorDef, 'spr' => $armorSpr],
        ];
    }

    /** @return array{str: int, mag: int} */
    public function weaponOffenseFor(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        if (! $item || (string) $item->type !== 'weapon') {
            return ['str' => 0, 'mag' => 0];
        }

        $enhanced = EquipmentEnhancementService::enhancedStatTotalsForItem($item, (int) ($characterItem->enhance_level ?? 0));
        $affix = $characterItem->affixStatBonuses();

        return [
            'str' => (int) ($enhanced['str'] ?? 0) + (int) ($affix['str'] ?? 0),
            'mag' => (int) ($enhanced['mag'] ?? 0) + (int) ($affix['mag'] ?? 0),
        ];
    }

    /** @return array{str: int, mag: int} */
    public function weaponEffectivePreview(Character $character, CharacterItem $characterItem): array
    {
        $stats = $this->getFinalStats($character);
        $base = $stats['weapon_base'] ?? ['str' => 0, 'mag' => 0];
        $weapon = $this->weaponOffenseFor($characterItem);
        $calculator = app(WeaponOffenseCalculator::class);

        return [
            'str' => $calculator->calculateEffectiveOffense((int) $base['str'], $weapon['str']),
            'mag' => $calculator->calculateEffectiveOffense((int) $base['mag'], $weapon['mag']),
        ];
    }

    /** @return array{def: int, spr: int} */
    public function armorDefenseFor(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        if (! $item || (string) $item->type !== 'armor') {
            return ['def' => 0, 'spr' => 0];
        }

        $enhanced = EquipmentEnhancementService::enhancedStatTotalsForItem($item, (int) ($characterItem->enhance_level ?? 0));
        $affix = $characterItem->affixStatBonuses();

        return [
            'def' => (int) ($enhanced['def'] ?? 0) + (int) ($affix['def'] ?? 0),
            'spr' => (int) ($enhanced['spr'] ?? 0) + (int) ($affix['spr'] ?? 0),
        ];
    }

    /** @return array{def: int, spr: int} */
    public function armorEffectivePreview(Character $character, CharacterItem $characterItem): array
    {
        $stats = $this->getFinalStats($character);
        $base = $stats['armor_base'] ?? ['def' => 0, 'spr' => 0];
        $armor = $this->armorDefenseFor($characterItem);
        $calculator = app(WeaponOffenseCalculator::class);

        return [
            'def' => $this->effectiveArmorStat((int) $base['def'], $armor['def'], $calculator),
            'spr' => $this->effectiveArmorStat((int) $base['spr'], $armor['spr'], $calculator),
        ];
    }

    private function effectiveArmorStat(int $baseStatWithoutArmor, int $armorStat, WeaponOffenseCalculator $calculator): int
    {
        $base = max(0, $baseStatWithoutArmor);
        $armor = max(0, $armorStat);

        // 8倍化前の防具性能を下限にする。低い基礎能力値・転職直後でも、防具装備で被ダメージが悪化しない。
        $legacyArmorBonus = intdiv($armor, 8);
        $scaledArmorBonus = $calculator->calculateProportionalBonus($base, $armor);

        return $base + max($legacyArmorBonus, $scaledArmorBonus);
    }

    public static function clearRequestCache(int $characterId): void
    {
        unset(self::$requestCache[$characterId]);
    }

    private function isLegacyMarkItem($item): bool
    {
        if (($item->type ?? null) !== 'accessory') {
            return false;
        }

        $subType = (string) ($item->sub_type ?? '');
        $name = (string) ($item->name ?? '');

        return in_array($subType, ['印', '刻印', '王印', '神印'], true)
            || str_ends_with($name, 'の印')
            || str_ends_with($name, 'の刻印')
            || str_ends_with($name, 'の王印')
            || str_ends_with($name, 'の神印');
    }
}
