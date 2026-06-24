<?php

namespace App\Services;

use App\Models\Character;
use App\Services\JobService;
use App\Services\EquipmentEnhancementService;

class CharacterStatusService
{
    /**
     * 装備補正を加算した最終ステータスを返す
     *
     * @param Character $character
     * @return array
     */
    public function getFinalStats(Character $character): array
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

        // 装備ボーナスの集計
        $hp_equip = 0; $mp_equip = 0; $atk_equip = 0; $def_equip = 0;
        $spd_equip = 0; $mag_equip = 0; $spr_equip = 0; $luk_equip = 0;

        $equippedItems = $character->characterItems()->where('is_equipped', true)->with('item')->get();
        foreach ($equippedItems as $charItem) {
            if ($charItem->item) {
                if ($this->isLegacyMarkItem($charItem->item)) {
                    continue;
                }

                $enhanceLevel = ($charItem->item->type ?? null) === 'weapon'
                    ? (int) ($charItem->enhance_level ?? 0)
                    : 0;

                $hp_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->hp_bonus ?? 0), $enhanceLevel);
                $mp_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->mp_bonus ?? 0), $enhanceLevel);
                $atk_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->str_bonus ?? ($charItem->item->attack_bonus ?? 0)), $enhanceLevel);
                $def_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->def_bonus ?? 0), $enhanceLevel);
                $spd_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->agi_bonus ?? ($charItem->item->speed_bonus ?? 0)), $enhanceLevel);
                $mag_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->mag_bonus ?? 0), $enhanceLevel);
                $spr_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->spr_bonus ?? 0), $enhanceLevel);
                $luk_equip += EquipmentEnhancementService::bonusWithEnhancement((int) ($charItem->item->luk_bonus ?? 0), $enhanceLevel);
            }
        }

        $markBonuses = app(MonsterMarkService::class)->permanentBonuses($character);
        $hp_equip += $markBonuses['hp'] ?? 0;
        $mp_equip += $markBonuses['mp'] ?? 0;
        $atk_equip += $markBonuses['str'] ?? 0;
        $def_equip += $markBonuses['def'] ?? 0;
        $spd_equip += $markBonuses['agi'] ?? 0;
        $mag_equip += $markBonuses['mag'] ?? 0;
        $spr_equip += $markBonuses['spr'] ?? 0;
        $luk_equip += $markBonuses['luk'] ?? 0;

        // 基礎値
        $base_hp = $jobStats['hp'];
        $base_mp = $jobStats['mp'] ?? 0;
        $base_atk = $jobStats['atk'];
        $base_def = $jobStats['def'];
        $base_spd = $jobStats['spd'];
        $base_mag = $jobStats['mag'];
        $base_spr = $jobStats['spr'];
        $base_luk = $jobStats['luck'];

        // 倍率補正は廃止し、レベルに応じた固定職ボーナスのみを加算
        $job_hp_diff = $job_hp_bonus;
        $job_mp_diff = $job_mp_bonus;
        $job_atk_diff = $job_str_bonus;
        $job_def_diff = $job_def_bonus;
        $job_spd_diff = $job_spd_bonus;
        $job_mag_diff = $job_mag_bonus;
        $job_spr_diff = $job_spr_bonus;
        $job_luk_diff = $job_luk_bonus;

        // 最終ボーナス（職増分 + 装備ボーナス）
        $total_hp_bonus = $job_hp_diff + $hp_equip;
        $total_mp_bonus = $job_mp_diff + $mp_equip;
        $total_atk_bonus = $job_atk_diff + $atk_equip;
        $total_def_bonus = $job_def_diff + $def_equip;
        $total_spd_bonus = $job_spd_diff + $spd_equip;
        $total_mag_bonus = $job_mag_diff + $mag_equip;
        $total_spr_bonus = $job_spr_diff + $spr_equip;
        $total_luk_bonus = $job_luk_diff + $luk_equip;

        return [
            'max_hp' => max(1, $base_hp + $total_hp_bonus),
            'max_mp' => max(0, $base_mp + $total_mp_bonus),
            'str' => max(1, $base_atk + $total_atk_bonus),
            'def' => max(0, $base_def + $total_def_bonus),
            'agi' => max(1, $base_spd + $total_spd_bonus),
            'mag' => max(0, $base_mag + $total_mag_bonus),
            'spr' => max(0, $base_spr + $total_spr_bonus),
            'luk' => max(0, $base_luk + $total_luk_bonus),
            'bonuses' => [
                'hp' => $total_hp_bonus,
                'mp' => $total_mp_bonus,
                'str' => $total_atk_bonus,
                'def' => $total_def_bonus,
                'agi' => $total_spd_bonus,
                'mag' => $total_mag_bonus,
                'spr' => $total_spr_bonus,
                'luk' => $total_luk_bonus,
            ],
            'monster_mark_bonuses' => $markBonuses,
        ];
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
