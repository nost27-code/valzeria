<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Item;
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

        // ---- パス1: 装備を一切含まない主能力（武器の比例補正はこの値を基準にする） ----
        // 職業基礎値 + マスター済み職の永続蓄積 + 現在職レベルボーナス + モンスターマーク永続ボーナス。
        // 装備ループより前に確定させることで、装備順や装備ループ内の集計に依存しない値になる。
        $preEquipStr = (int) $jobStats['atk'] + $job_str_bonus + (int) ($markBonuses['str'] ?? 0);
        $preEquipMag = (int) $jobStats['mag'] + $job_mag_bonus + (int) ($markBonuses['mag'] ?? 0);

        // ---- パス2: 装備ボーナスの集計（固定値 + 銘 + 武器の比例補正） ----
        $hp_equip = 0; $mp_equip = 0; $atk_equip = 0; $def_equip = 0;
        $spd_equip = 0; $mag_equip = 0; $spr_equip = 0; $luk_equip = 0;

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
                $atk_equip += $enhancedStats['str'] ?? 0;
                $def_equip += $enhancedStats['def'] ?? 0;
                $spd_equip += $enhancedStats['agi'] ?? 0;
                $mag_equip += $enhancedStats['mag'] ?? 0;
                $spr_equip += $enhancedStats['spr'] ?? 0;
                $luk_equip += $enhancedStats['luk'] ?? 0;

                if ((string) ($charItem->item->type ?? '') === 'weapon') {
                    $proportional = $this->weaponProportionalBonus($charItem->item, $preEquipStr, $preEquipMag);
                    $atk_equip += $proportional['str'];
                    $mag_equip += $proportional['mag'];
                }

                $affixBonuses = $charItem->affixStatBonuses();
                $hp_equip += (int) ($affixBonuses['hp'] ?? 0);
                $atk_equip += (int) ($affixBonuses['str'] ?? 0);
                $def_equip += (int) ($affixBonuses['def'] ?? 0);
                $spd_equip += (int) ($affixBonuses['agi'] ?? 0);
                $mag_equip += (int) ($affixBonuses['mag'] ?? 0);
                $spr_equip += (int) ($affixBonuses['spr'] ?? 0);
                $luk_equip += (int) ($affixBonuses['luk'] ?? 0);
            }
        }

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
            // 装備を一切含まない主能力（武器の比例補正の基準値）。装備比較表示などで再利用する。
            'pre_equipment' => [
                'str' => $preEquipStr,
                'mag' => $preEquipMag,
            ],
        ];
    }

    /**
     * 武器の比例補正（固定値には影響しない）。装備前主能力 × ランク補正率 を、
     * 武器自身のSTR/MAG固定値比率で按分してSTR/MAGへ振り分ける。
     * 鍛冶強化・銘は一切参照しないため、比例部分へ二重に乗ることはない。
     *
     * @return array{str: int, mag: int}
     */
    private function weaponProportionalBonus(Item $item, int $preEquipStr, int $preEquipMag): array
    {
        if (! (bool) config('equipment_scaling.weapon.proportional_enabled', true)) {
            return ['str' => 0, 'mag' => 0];
        }

        $rank = strtoupper((string) ($item->weapon_rank ?? ''));
        $rate = (float) config("equipment_scaling.weapon.proportional_rate.{$rank}", 0.0);
        if ($rate <= 0.0) {
            return ['str' => 0, 'mag' => 0];
        }

        $strBase = max(0, (int) ($item->str_bonus ?? 0));
        $magBase = max(0, (int) ($item->mag_bonus ?? 0));
        $total = $strBase + $magBase;
        if ($total <= 0) {
            return ['str' => 0, 'mag' => 0];
        }

        return [
            'str' => (int) floor(max(0, $preEquipStr) * $rate * ($strBase / $total)),
            'mag' => (int) floor(max(0, $preEquipMag) * $rate * ($magBase / $total)),
        ];
    }

    /**
     * 表示層向け: 指定の武器アイテムをキャラクターが装備した場合の比例補正内訳を返す。
     * 装備中かどうかに関わらず、現在の装備前主能力を基準に見込み値を計算する。
     *
     * @return array{rate: float, str: int, mag: int}
     */
    public function weaponProportionalPreview(Character $character, Item $item): array
    {
        $stats = $this->getFinalStats($character);
        $pre = $stats['pre_equipment'] ?? ['str' => 0, 'mag' => 0];
        $rank = strtoupper((string) ($item->weapon_rank ?? ''));
        $rate = (float) config("equipment_scaling.weapon.proportional_rate.{$rank}", 0.0);
        if (! (bool) config('equipment_scaling.weapon.proportional_enabled', true)) {
            $rate = 0.0;
        }

        $bonus = $this->weaponProportionalBonus($item, (int) $pre['str'], (int) $pre['mag']);

        return [
            'rate' => $rate,
            'str' => $bonus['str'],
            'mag' => $bonus['mag'],
        ];
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
