<?php

namespace App\Services;

use App\Models\BattleLog;
use App\Models\Character;

class BattleLogService
{
    /**
     * 個人戦闘ログを記録する
     *
     * @param  array<string, mixed>  $telemetry  turn_count, damage_dealt, damage_taken, start_hp, end_hp,
     *                                            weapon_rank, pre_equipment_main_stat, has_engraving, has_slayer,
     *                                            enemy_hp_multiplier, enemy_def_spr_multiplier, enemy_atk_mag_multiplier
     */
    public function addLog(Character $character, int $areaId, int $enemyId, string $battleType, string $result, int $expGained, int $goldGained, int $levelUpCount, string $logText, ?int $droppedItemId = null, ?int $droppedCharacterItemId = null, int $goldLost = 0, array $telemetry = []): BattleLog
    {
        $allowedTelemetryKeys = [
            'turn_count', 'damage_dealt', 'damage_taken', 'start_hp', 'end_hp',
            'weapon_rank', 'pre_equipment_main_stat', 'has_engraving', 'has_slayer',
            'enemy_hp_multiplier', 'enemy_def_spr_multiplier', 'enemy_atk_mag_multiplier',
        ];

        $log = BattleLog::create([
            'character_id' => $character->id,
            'area_id' => $areaId,
            'enemy_id' => $enemyId,
            'battle_type' => $battleType,
            'result' => $result,
            'exp_gained' => $expGained,
            'gold_gained' => $goldGained,
            'gold_lost' => max(0, $goldLost),
            'level_up_count' => $levelUpCount,
            'log_text' => $logText,
            'dropped_item_id' => $droppedItemId,
            'dropped_character_item_id' => $droppedCharacterItemId,
            ...array_intersect_key($telemetry, array_flip($allowedTelemetryKeys)),
        ]);

        app(PlayerLifecycleEventService::class)->recordFirstBattle($character, $result);

        return $log;
    }

    /**
     * BattleResult・装備状態からtelemetry配列を組み立てる共通ヘルパー。
     * ExplorationService/SubAreaExplorationServiceの両方から利用する。
     *
     * @return array<string, mixed>
     */
    public function telemetryFor(Character $character, \App\Services\Battle\BattleResult $battleResult): array
    {
        $weapon = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($q) => $q->where('type', 'weapon'))
            ->with('item')
            ->first();

        $statusService = app(\App\Services\CharacterStatusService::class);
        \App\Services\CharacterStatusService::clearRequestCache($character->id);
        $stats = $statusService->getFinalStats($character);
        $preEquip = $stats['pre_equipment'] ?? ['str' => 0, 'mag' => 0];
        $mainStat = max((int) $preEquip['str'], (int) $preEquip['mag']);

        return [
            'turn_count' => $battleResult->turnCount,
            'damage_dealt' => $battleResult->damageDealt,
            'damage_taken' => $battleResult->damageTaken,
            'start_hp' => $battleResult->playerHpBefore,
            'end_hp' => $battleResult->playerHpAfter,
            // 内部集計用の実効ランク（SPECIAL武器はdisplay_rankから解決）。
            // 攻略可否の判定やプレイヤー向け表示には使用しない。
            'weapon_rank' => $weapon?->item
                ? \App\Support\WeaponRankResolver::effectiveRank($weapon->item->weapon_rank, $weapon->item->display_rank)
                : null,
            'pre_equipment_main_stat' => $mainStat,
            'has_engraving' => $weapon ? $weapon->affix_prefix_id !== null : null,
            'has_slayer' => $weapon ? $weapon->affix_suffix_id !== null : null,
            'enemy_hp_multiplier' => $battleResult->enemyDurability['hp_multiplier'] ?? null,
            'enemy_def_spr_multiplier' => $battleResult->enemyDurability['def_spr_multiplier'] ?? null,
            'enemy_atk_mag_multiplier' => $battleResult->enemyDurability['atk_mag_multiplier'] ?? null,
        ];
    }
}
