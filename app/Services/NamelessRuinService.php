<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\EnemyAction;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerRelic;
use RuntimeException;

class NamelessRuinService
{
    public function zones(): array
    {
        $zones = (array) config('nameless_ruins');
        $keys = array_keys($zones);
        foreach ($zones as $key => &$zone) {
            $zone['next_zone_key'] = $keys[array_search($key, $keys, true) + 1] ?? null;
            $zone['next_zone_name'] = $zones[$zone['next_zone_key']]['name'] ?? null;
            foreach ($zone['enemies'] as &$enemy) {
                $enemy['image'] = config('enemy_images')[$enemy['name']] ?? null;
                $enemy['description'] = config('enemy_species.labels.'.$enemy['species']).' / '.match ($enemy['profile']) {
                    'guard' => '守りが堅い', 'swift' => '素早い', 'mage' => '魔法で攻撃する', 'brute' => '一撃が重い', default => '攻守の均衡がよい',
                };
            }
        }

        unset($enemy, $zone);

        foreach ($zones as &$bossZone) {
            foreach ($bossZone['bosses'] as &$definition) {
                $definition['image'] ??= config('enemy_images')[$definition['name']] ?? null;
                $definition['description'] = config('enemy_species.labels.'.$definition['species']).' / '.$definition['trait'];
                $definition['recommended_relics'] = array_map(static fn (string $key): array => [
                    'key' => $key, 'name' => config('nameless_relic_effects.'.$key.'.name'),
                    'zone' => config('nameless_ruins.'.config('nameless_relic_effects.'.$key.'.zone').'.name'),
                ], $definition['relics']);
            }
            unset($definition);
        }
        unset($bossZone);

        return $zones;
    }

    public function bossForDepth(array $zone, int $depth): array
    {
        foreach ($zone['bosses'] as $boss) {
            if ($depth >= $boss['min_depth'] && $depth <= $boss['max_depth']) {
                return $boss;
            }
        }
        throw new RuntimeException('ボスの深度が範囲外です。');
    }

    /** 仮想マスタの技だけを既存戦闘へ渡す。敵・敵技のDB行は作らない。 */
    public function bossActions(array $definition): \Illuminate\Support\Collection
    {
        if (! isset($definition['action'])) {
            return collect();
        }
        return collect([new EnemyAction($definition['action'] + [
            'id' => 1, 'action_key' => 'nameless_signature', 'selection_weight' => 100,
            'power_percent' => 100, 'hit_count' => 1, 'effect_percent' => 0, 'duration_turns' => 0,
            'cooldown_turns' => 0, 'max_uses_per_battle' => null, 'trigger_turn' => null,
            'trigger_key' => null, 'trigger_value' => null, 'can_use_on_first_turn' => true,
            'is_telegraphed' => false, 'telegraph_turns' => 0, 'can_be_guarded' => false,
            'guard_reduction_rate' => 0, 'cancel_on_enemy_death' => true, 'guarantee_first_use' => false,
        ])]);
    }

    /** 設定の順に道がつながる。深度50の撃破は、深度51の解放記録で判定する。 */
    public function availableZones(Character $character): array
    {
        $progress = NamelessRuinProgress::query()->where('character_id', $character->id)
            ->pluck('unlocked_depth', 'zone_key');
        $available = [];
        foreach ($this->zones() as $key => $zone) {
            $available[$key] = $zone;
            if ((int) $progress->get($key, 1) <= (int) config('nameless_relics.next_zone_unlock_depth')) {
                break;
            }
        }

        return $available;
    }

    public function rankWeights(int $depth): array
    {
        $bonus = 1 + min(max(0, $depth - 1), (int) config('nameless_relics.depth_rank_bonus_cap')) * (float) config('nameless_relics.depth_rank_bonus');
        $weights = [];
        foreach ((array) config('nameless_relics.rank_weights') as $rank => $weight) {
            $weights[$rank] = max(1, (int) round($weight * $bonus ** ($rank - 1)));
        }

        return $weights;
    }

    /** チケットを引数にできるため、希少ランクの境界も乱数頼みにならず検証できる。 */
    public function rankForTicket(int $depth, int $ticket): int
    {
        $weights = $this->rankWeights($depth);
        if ($ticket < 1 || $ticket > array_sum($weights)) {
            throw new RuntimeException('抽選値が範囲外です。');
        }
        foreach ($weights as $rank => $weight) {
            $ticket -= $weight;
            if ($ticket <= 0) {
                return $rank;
            }
        }
        throw new RuntimeException('遺物抽選に失敗しました。');
    }

    public function enemyStats(array $definition, int $depth, bool $boss): array
    {
        $growth = 1 + max(0, $depth - 1) * (float) config('nameless_relics.enemy_depth_growth')
            + max(0, $depth - 1) ** 2 * (float) config('nameless_relics.enemy_depth_quadratic_growth');
        $growth *= $boss ? (float) config('nameless_relics.boss_stat_multiplier') : 1;
        $profile = match ($definition['profile']) {
            'guard' => ['max_hp' => 1.2, 'def' => 1.4, 'agi' => .7],
            'swift' => ['max_hp' => .8, 'agi' => 1.5, 'def' => .8],
            'mage' => ['mag' => 1.3, 'spr' => 1.3, 'def' => .8],
            'brute' => ['str' => 1.3, 'max_hp' => 1.1, 'agi' => .8],
            default => [],
        };
        $stats = [];
        foreach ((array) config('nameless_relics.enemy_base') as $key => $value) {
            $stats[$key] = max(1, (int) round($value * $growth * ($profile[$key] ?? 1)));
        }

        return $stats + ['danger_rate' => 0, 'danger_label' => '遺跡 深度'.$depth, 'base_str' => $stats['str'], 'bonus_str' => 0, 'base_def' => $stats['def'], 'bonus_def' => 0, 'base_hp' => $stats['max_hp'], 'bonus_hp' => 0, 'durability_hp_multiplier' => 1, 'durability_def_spr_multiplier' => 1, 'durability_atk_mag_multiplier' => 1, 'durability_tier' => 'nameless_ruin'];
    }

    public function fight(Character $character, string $zoneKey, int $depth, bool $boss, string $uuid, int $count = 1): array
    {
        $zone = $this->zones()[$zoneKey] ?? throw new RuntimeException('未知の遺跡です。');

        if ($count < 1 || $count > ExplorationService::MAX_REPEAT_COUNT || ($boss && $count !== 1)) {
            throw new RuntimeException('通常探索は1〜50回、ボス挑戦は1回を指定してください。');
        }

        return app(NamelessWorkshopService::class)->operation($character, $uuid, 'ruin', compact('zoneKey', 'depth', 'boss', 'count'), function (Character $locked) use ($zone, $zoneKey, $depth, $boss, $count): array {
            if (! array_key_exists($zoneKey, $this->availableZones($locked))) {
                throw new RuntimeException('未解放の遺跡です。先に直前の遺跡の深度'.config('nameless_relics.next_zone_unlock_depth').'のボスを倒してください。');
            }
            if ($locked->is_frozen) {
                throw new RuntimeException('凍結中は探索できません。');
            }
            if ($count === 1) {
                $result = $this->fightLocked($locked, $zone, $zoneKey, $depth, $boss);
                return $result;
            }
            $drops = [];
            $rareEncounters = [];
            $equipmentDrops = [];
            $runIndex = 0;
            $last = [];
            $result = app(ExplorationService::class)->exploreRepeated($locked, 0, $count,
                function (Character $runner) use ($zone, $zoneKey, $depth, &$drops, &$equipmentDrops, &$rareEncounters, &$runIndex, &$last): array {
                    // A full shared warehouse stops before another battle, preserving earned rewards.
                    $storage = app(StorageCapacityService::class)->summary($runner);
                    $block = app(NamelessWorkshopService::class)->inventoryBlockReason($runner, $storage);
                    if ($last && $block !== null) {
                        return $last + ['error' => $block];
                    }
                    $last = $this->fightLocked($runner, $zone, $zoneKey, $depth, false, $storage);
                    $runIndex++;
                    $drops = array_merge($drops, $last['relic_drops']);
                    $equipmentDrops = array_merge($equipmentDrops, $last['nameless_equipment_drops']);
                    foreach ($last['rare_encounters'] as $encounter) {
                        $encounter['index'] = $runIndex;
                        $rareEncounters[] = $encounter;
                    }
                    return $last;
                }, [], (int) config('nameless_relics.stamina_cost'));
            if ((int) data_get($result, 'batch_explore.completed', 0) === 0) {
                throw new RuntimeException($result['error'] ?? '探索を開始できませんでした。');
            }
            if (isset($result['error'])) {
                $result['batch_explore']['stop_text'] = $result['error'];
                unset($result['error']);
            }
            // The shared batch runner aggregates ordinary equipment separately from relics.
            $result['relic_drops'] = $drops;
            $result['nameless_equipment_drops'] = $equipmentDrops;
            $result['rare_encounters'] = $rareEncounters;
            $result['drop'] = $last['drop'] ?? null;
            return $result;
        });
    }

    private function fightLocked(Character $locked, array $zone, string $zoneKey, int $depth, bool $boss, ?array $storage = null): array
    {
        $progress = NamelessRuinProgress::query()->firstOrCreate(['character_id' => $locked->id, 'zone_key' => $zoneKey], ['unlocked_depth' => 1]);
        if ($depth < 1 || $depth > $progress->unlocked_depth || $depth > (int) config('nameless_relics.max_depth')) {
            throw new RuntimeException('未解放の深度です。');
        }
        if ($locked->current_hp <= 0) {
            throw new RuntimeException('宿屋で回復してから探索してください。');
        }
        if ($locked->exploration_cooldown_until && now()->lt($locked->exploration_cooldown_until)) {
            throw new RuntimeException('休息が必要です。宿屋で回復してください。');
        }
        $storage ??= app(StorageCapacityService::class)->summary($locked);
        $block = app(NamelessWorkshopService::class)->inventoryBlockReason($locked, $storage);
        if ($block !== null) {
            throw new RuntimeException($block);
        }
        $freeSlots = $storage['material_free'];
        $collection = app(NamelessEquipmentCollectionService::class);
        $stamina = app(ExplorationStaminaService::class);
        // 探索力が無効の環境では、無制限の資源生成にならないよう試作探索を開始しない。
        if (! $stamina->enabled()) {
            throw new RuntimeException('遺跡探索には探索力モードが必要です。');
        }
        $consumption = $stamina->consume($locked, (int) config($boss ? 'nameless_relics.boss_stamina_cost' : 'nameless_relics.stamina_cost'));
        if (! $consumption['ok']) {
            throw new RuntimeException($consumption['error']);
        }
        $encounter = $boss
            ? ['kind' => 'boss_challenge', 'definition' => $this->bossForDepth($zone, $depth), 'is_boss' => true]
            : $this->encounterForTicket($zone, $this->bossCleared($locked, $zoneKey, $depth, $progress), $freeSlots, random_int(1, 10000), $depth);
        $definition = $encounter['definition'];
        $enemyBoss = $encounter['is_boss'];
        $enemy = new Enemy(['name' => $definition['name'], 'species_key' => $definition['species'], 'is_boss' => $enemyBoss, 'level' => $depth, 'max_mp' => 0, 'normal_attack_type' => $definition['profile'] === 'mage' ? 'magical' : 'physical']);
        $enemy->setRelation('actions', $enemyBoss ? $this->bossActions($definition) : collect());
        $battle = app(BattleService::class)->executeBattle($locked, $enemy, 0, [
            'prepared_enemy_stats' => $this->enemyStats($definition, $depth, $enemyBoss),
            'rewards_enabled' => false, 'exploration_support_enabled' => false,
            'valmon_assist_enabled' => false,
        ]);
        $drops = [];
        $advanced = false;
        $unlockedZoneName = null;
        $equipmentDrops = [];
        if ($battle->result === 'victory') {
            $dropCount = $encounter['kind'] === 'relic_goblin'
                ? (int) config('nameless_relics.relic_goblin_drop_count')
                : (random_int(1, 10000) <= (int) config($enemyBoss ? 'nameless_relics.boss_drop_chance_bps' : 'nameless_relics.drop_chance_bps') ? 1 : 0);
            for ($i = 0; $i < $dropCount; $i++) {
                $rank = $this->rankForTicket($depth, random_int(1, array_sum($this->rankWeights($depth))));
                $relic = PlayerRelic::query()->create(['character_id' => $locked->id, 'effect_key' => $zone['effects'][random_int(0, count($zone['effects']) - 1)], 'rank' => $rank]);
                $drops[] = ['id' => $relic->id, 'name' => $relic->displayName(), 'summary' => $relic->effectSummary(), 'effect_key' => $relic->effect_key, 'rank' => $rank];
            }
            if ($collection->dropsForTicket(random_int(1, 10000), $depth)) {
                $equipmentDrops[] = $collection->awardLocked($locked);
            }
            if ($boss && $depth === (int) $progress->unlocked_depth && $depth < (int) config('nameless_relics.max_depth')) {
                $progress->increment('unlocked_depth');
                $advanced = true;
                if ($depth === (int) config('nameless_relics.next_zone_unlock_depth')) {
                    $unlockedZoneName = $zone['next_zone_name'];
                }
            }
        }

        $snapshot = [
            'success' => true, 'result' => $battle->result, 'turn_count' => $battle->turnCount,
            'log' => implode("\n", $battle->logs), 'enemy' => $enemy->getAttributes() + $this->enemyStats($definition, $depth, $enemyBoss),
            'enemy_image_path' => $definition['image'] ?? null, 'encounter_kind' => $encounter['kind'], 'relic_drops' => $drops,
            'boss_guide' => $enemyBoss ? array_intersect_key($definition, array_flip(['stage', 'min_depth', 'max_depth', 'trait', 'counter', 'technique', 'recommended_relics'])) : null,
            'unlocked_zone_name' => $unlockedZoneName,
            'nameless_equipment_drops' => $equipmentDrops,
            'rare_encounters' => in_array($encounter['kind'], ['cleared_boss', 'relic_goblin'], true)
                ? [['index' => 1, 'kind' => $encounter['kind'], 'name' => $definition['name'], 'result' => $battle->result, 'relic_count' => count($drops)]] : [],
            'enemy_stat_display' => $battle->enemyStatDisplay, 'enemy_hp_after' => $battle->enemyHpAfter,
            'enemy_max_hp' => $battle->enemyMaxHp, 'job_art_v2_hud' => $battle->jobArtV2Hud,
            'exp_gained' => 0, 'gold_gained' => 0, 'job_exp_gained' => 0, 'level_up_count' => 0,
            'equipment_drops' => [], 'material_drop' => [], 'exploration_stamina' => $stamina->summary($locked),
        ];
        $drop = $drops[0] ?? null;
        return $snapshot + ['message' => $advanced ? 'ボスを倒し、深度'.($depth + 1).'を解放しました。' : ($battle->result === 'victory' ? '遺跡の探索を終えました。' : '探索を終えて帰還しました。'), 'battle_result' => $battle->result, 'zone_key' => $zoneKey, 'zone_name' => $zone['name'], 'depth' => $depth, 'boss' => $boss, 'enemy_name' => $definition['name'], 'enemy_description' => $definition['description'], 'drop' => $drop, 'advanced' => $advanced, 'unlocked_depth' => $progress->unlocked_depth, 'hp' => $battle->playerHpAfter, 'sp' => $battle->playerMpAfter, 'damage_dealt' => $battle->damageDealt, 'damage_taken' => $battle->damageTaken, 'turns' => $battle->turnCount, 'logs' => $battle->logs];
    }

    /** 区画と深度の撃破記録を混同しない。最終深度は次深度がないため操作台帳で確認する。 */
    public function bossCleared(Character $character, string $zoneKey, int $depth, NamelessRuinProgress $progress): bool
    {
        if ((int) $progress->character_id !== (int) $character->id || $progress->zone_key !== $zoneKey || $depth < 1 || $depth > (int) $progress->unlocked_depth) {
            return false;
        }
        if ($depth < (int) $progress->unlocked_depth) {
            return true;
        }
        if ($depth !== (int) config('nameless_relics.max_depth')) {
            return false;
        }
        return NamelessWorkshopOperation::query()->where('character_id', $character->id)->where('action', 'ruin')
            ->where('result->zone_key', $zoneKey)->where('result->depth', $depth)
            ->where('result->boss', true)->where('result->battle_result', 'victory')->exists();
    }

    /** 1枚の抽選で排他的に選択。空き不足や未撃破の場合は通常敵に戻す。 */
    public function encounterForTicket(array $zone, bool $cleared, int $freeSlots, int $ticket, int $depth = 1): array
    {
        if ($ticket < 1 || $ticket > 10000) {
            throw new RuntimeException('遭遇抽選値が範囲外です。');
        }
        $goblinRate = (int) config('nameless_relics.relic_goblin_encounter_bps');
        $bossRate = (int) config('nameless_relics.cleared_boss_encounter_bps');
        if ($goblinRate < 0 || $bossRate < 0 || $goblinRate + $bossRate > 10000) {
            throw new RuntimeException('遺跡の遭遇率設定が不正です。');
        }
        if ($ticket <= $goblinRate && $freeSlots >= (int) config('nameless_relics.relic_goblin_drop_count')) {
            return ['kind' => 'relic_goblin', 'definition' => (array) config('nameless_relics.relic_goblin'), 'is_boss' => false];
        }
        if ($ticket > $goblinRate && $ticket <= $goblinRate + $bossRate && $cleared) {
            return ['kind' => 'cleared_boss', 'definition' => $this->bossForDepth($zone, $depth), 'is_boss' => true];
        }
        return ['kind' => 'normal', 'definition' => $zone['enemies'][random_int(0, 3)], 'is_boss' => false];
    }
}
