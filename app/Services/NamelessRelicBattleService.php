<?php

namespace App\Services;

use App\Models\Character;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;

class NamelessRelicBattleService
{
    public function attach(Character $character, BattleActor $actor): void
    {
        if (! app(NamelessWorkshopService::class)->ready()) {
            return;
        }
        $actor->namelessRelicsEnabled = true;
        $catalog = app(NamelessRelicCatalog::class);
        foreach (app(NamelessWorkshopService::class)->activeRelics($character) as $relic) {
            $effect = $catalog->definition($relic->effect_key);
            $value = $catalog->value($relic->effect_key, $relic->rank);
            foreach ($effect['killers'] ?? [] as $species) {
                $actor->weaponKillerEffects[] = ['source' => 'relic', 'species_key' => $species, 'damage_rate' => $value];
            }
            foreach ($effect['resists'] ?? [] as $species) {
                $actor->namelessResistEffects[] = ['species_key' => $species, 'damage_rate' => $value];
            }
            if (in_array($effect['category'], ['special', 'countermeasure'], true)) {
                $actor->namelessRelicEffects[$effect['target']] = $value;
            } elseif ($effect['category'] === 'brand') {
                $actor->namelessBrand = ['species' => $effect['target'], 'potency' => $value];
            } elseif ($effect['category'] === 'form') {
                $actor->speciesKeys = [$effect['target']];
                $actor->speciesKey = $effect['target'];
            }
        }
        if (isset($actor->namelessRelicEffects['convert_physical'])) {
            $actor->normalAttackType = 'physical';
        }
        if (isset($actor->namelessRelicEffects['convert_magical'])) {
            $actor->normalAttackType = 'magical';
        }
    }

    /** Admission snapshot: never read current equipment while resolving a saved raid. */
    public function snapshot(Character $character): array
    {
        $actor = new BattleActor('遺物', true, [], $character);
        $this->attach($character, $actor);

        return [
            'version' => 1,
            'enabled' => $actor->namelessRelicsEnabled,
            'effects' => $actor->namelessRelicEffects,
            'killer_effects' => $actor->weaponKillerEffects,
            'resist_effects' => $actor->namelessResistEffects,
            'brand' => $actor->namelessBrand,
            'species_keys' => $actor->speciesKeys,
            'normal_attack_type' => isset($actor->namelessRelicEffects['convert_physical'])
                || isset($actor->namelessRelicEffects['convert_magical']) ? $actor->normalAttackType : null,
        ];
    }

    public function restoreSnapshot(BattleActor $actor, array $snapshot): void
    {
        // Old snapshots have no relics. Turning OFF also stops pending snapshots.
        if (! app(NamelessWorkshopService::class)->enabled() || ! ($snapshot['enabled'] ?? false)) {
            return;
        }
        if (($snapshot['version'] ?? null) !== 1) {
            throw new \RuntimeException('未対応の遺物snapshotです。');
        }
        $actor->namelessRelicsEnabled = true;
        $actor->namelessRelicEffects = $snapshot['effects'];
        $actor->weaponKillerEffects = array_merge($actor->weaponKillerEffects, $snapshot['killer_effects']);
        $actor->namelessResistEffects = $snapshot['resist_effects'];
        $actor->namelessBrand = $snapshot['brand'];
        $actor->speciesKeys = $snapshot['species_keys'];
        $actor->speciesKey = $actor->speciesKeys[0] ?? null;
        if ($snapshot['normal_attack_type'] !== null) {
            $actor->normalAttackType = $snapshot['normal_attack_type'];
        }
    }

    public function attachOrdinaryEquipment(Character $character, BattleActor $actor): void
    {
        if (! $actor->namelessRelicsEnabled) {
            return;
        }
        $permission = app(EquipmentPermissionService::class);
        foreach ($character->characterItems()->where('is_equipped', true)->with(['item', 'affixPrefix'])->get() as $item) {
            if ($item->item?->type === 'weapon') {
                $actor->weaponKillerEffects = array_merge($actor->weaponKillerEffects, $permission->effectiveKillerEffects($character, $item));
            } elseif ($item->item?->type === 'armor') {
                $actor->armorResistSpeciesKey = $item->resist_species_key;
                $actor->armorSpeciesDamageReductionRate = $permission->effectiveSpeciesDamageReductionRate($character, $item);
            }
        }
    }

    public function startBattle(BattleActor $a, BattleActor $b, BattleState $state): void
    {
        if ($a->namelessRelicsEnabled || $b->namelessRelicsEnabled) {
            $a->namelessRelicsEnabled = $b->namelessRelicsEnabled = true;
        }
        foreach ([[$a, $b], [$b, $a]] as [$source, $target]) {
            if ($source->namelessBrand !== null) {
                $target->namelessForeignSpecies = $source->namelessBrand['species'];
                $target->namelessForeignPotency = $source->namelessBrand['potency'];
                $state->addLog('【種族刻印】'.$target->logName().' に'.e(config('enemy_species.labels.'.$target->namelessForeignSpecies)).'の刻印が刻まれた。');
            }
        }
    }

    public function beginAction(BattleActor $actor, BattleState $state): void
    {
        $actor->namelessActionSerial++;
        if ($actor->namelessActionSerial === 1 && ($actor->namelessRelicEffects['cleanse'] ?? 0) > 0 && $actor->namelessForeignSpecies !== null) {
            $actor->namelessForeignSpecies = null;
            $actor->namelessForeignPotency = 0;
            $state->addLog('【浄刻の鏡】'.$actor->logName().' の敵からの刻印が消えた。');
        }
    }

    /** 刻印は自然種族へ加算しない。PvEの複数自然種族は従来の合計、PvPは最大一致を使う。 */
    public function killerRate(BattleActor $attacker, BattleActor $defender, bool $pvp): float
    {
        $rate = $this->matchingKillerRate($attacker, $defender, $pvp);
        return min($pvp ? (float) config('nameless_relics.pvp_killer_cap') : (float) config('equipment_affix.weapon_killer_damage_rate_cap', .55),
            $rate * ($pvp ? (float) config('nameless_relics.pvp_species_scale') : 1))
            * (1 - ($defender->namelessRelicEffects['brand_guard'] ?? 0));
    }

    /** Uncapped match; each battle mode applies its existing cap exactly once. */
    public function matchingKillerRate(BattleActor $attacker, BattleActor $defender, bool $pvp = false): float
    {
        $bySpecies = [];
        foreach ($attacker->weaponKillerEffects as $effect) {
            $key = $effect['species_key'];
            $bySpecies[$key] = ($bySpecies[$key] ?? 0) + (float) $effect['damage_rate'];
        }
        $nativeRate = $foreignRate = 0.0;
        foreach ($bySpecies as $species => $value) {
            if (in_array($species, $defender->speciesKeys, true)) {
                $nativeRate = $pvp ? max($nativeRate, $value) : $nativeRate + $value;
            } elseif ($defender->namelessForeignSpecies === $species) {
                $foreignRate = max($foreignRate, $value * $defender->namelessForeignPotency);
            }
        }
        return max($nativeRate, $foreignRate);
    }

    public function resistanceRate(BattleActor $attacker, BattleActor $defender, bool $pvp): float
    {
        $effects = $defender->namelessResistEffects;
        if ($defender->armorResistSpeciesKey) {
            $effects[] = ['species_key' => $defender->armorResistSpeciesKey, 'damage_rate' => $defender->armorSpeciesDamageReductionRate];
        }
        $species = array_unique(array_filter(array_merge($attacker->speciesKeys, [$attacker->namelessForeignSpecies])));
        $matched = [];
        foreach ($effects as $effect) {
            if (in_array($effect['species_key'], $species, true)) {
                $matched[$effect['species_key']] = ($matched[$effect['species_key']] ?? 0) + $effect['damage_rate'];
            }
        }

        return min((float) config('nameless_relics.resist_cap'), max([0, ...array_values($matched)]) * ($pvp ? (float) config('nameless_relics.pvp_species_scale') : 1));
    }

    public function speciesDamage(int $damage, BattleActor $attacker, BattleActor $defender, bool $pvp): int
    {
        if ($damage <= 0) {
            return $damage;
        }

        return max(1, (int) floor(round($damage * (1 + $this->killerRate($attacker, $defender, $pvp)) * (1 - $this->resistanceRate($attacker, $defender, $pvp)), 6)));
    }

    /** 命中後の追加効果。callbackは既存のダメージ/回復経路を通す。反応からは呼ばない。 */
    public function completeDirectHit(BattleActor $attacker, BattleActor $defender, BattleState $state, DamageApplicationResult $result, callable $damage, callable $heal, callable $roll): void
    {
        if ($result->hitResult === HitResult::MISS || $result->hitResult === HitResult::EVADE) {
            return;
        }
        $action = spl_object_id($attacker).':'.$attacker->namelessActionSerial;
        $actual = $result->actualHpLoss;
        if ($result->sourceType === DamageSourceType::NORMAL_ATTACK && ! isset($attacker->namelessProcActions['normal:'.$action])) {
            $attacker->namelessProcActions['normal:'.$action] = true;
            if (! $attacker->isDead()) {
                $drain = (int) floor($actual * ($attacker->namelessRelicEffects['drain'] ?? 0));
                if ($drain > 0) {
                    $recovered = $heal($attacker, $drain);
                    $state->addLog('【血杯】'.$attacker->logName().' のHPが'.$recovered.'回復した。');
                }
                $sp = (int) floor($attacker->maxMp * ($attacker->namelessRelicEffects['echo_sp'] ?? 0));
                if ($sp > 0) {
                    $before = $attacker->mp;
                    $attacker->mp = min($attacker->maxMp, $attacker->mp + $sp);
                    $state->addLog('【反響】SPが'.($attacker->mp - $before).'回復した。');
                }
                if ($actual > 0 && ! $defender->isDead() && ($attacker->namelessRelicEffects['chase'] ?? 0) > 0 && $roll() <= $attacker->namelessRelicEffects['chase'] * 10000) {
                    $damage($attacker, $defender, max(1, (int) floor($actual * config('nameless_relics.chase_damage_rate'))), DamageSourceType::OTHER);
                    $state->addLog('【追響】'.$attacker->logName().' の遺物が追撃した。');
                }
            }
        }
        if (! $defender->isDead() && ! $attacker->isDead() && $actual > 0 && ! isset($defender->namelessProcActions['counter:'.$action])) {
            $defender->namelessProcActions['counter:'.$action] = true;
            if (($defender->namelessRelicEffects['counter'] ?? 0) > 0 && $roll() <= $defender->namelessRelicEffects['counter'] * 10000) {
                $damage($defender, $attacker, max(1, (int) floor($actual * config('nameless_relics.counter_damage_rate'))), DamageSourceType::COUNTER);
                $state->addLog('【返刃】'.$defender->logName().' の遺物が反撃した。');
            }
        }
        $blocked = $defender->namelessMirrorBlocked[$action] ?? 0;
        if ($blocked > 0 && ! $defender->isDead() && ! $attacker->isDead() && ! isset($defender->namelessProcActions['mirror:'.$action])) {
            $defender->namelessProcActions['mirror:'.$action] = true;
            $damage($defender, $attacker, max(1, (int) floor($blocked * config('nameless_relics.mirror_return_rate'))), DamageSourceType::REFLECT);
            $state->addLog('【鏡守】軽減した攻撃の一部が返った。');
        }
    }

    public function discountFixedSp(int $fixed, float $rate): int
    {
        if ($fixed <= 0 || $rate <= 0) {
            return $fixed;
        }

        return max(1, (int) ceil($fixed * (1 - min(1, $rate))));
    }

    /** 多段技は同一の行動番号で扱う。状態異常の継続ダメージ・反射からは呼ばない。 */
    public function directDamage(int $damage, BattleActor $attacker, BattleActor $defender): int
    {
        if ($damage <= 0) {
            return $damage;
        }
        $multiplier = 1.0;
        if (($attacker->namelessRelicEffects['opener'] ?? 0) > 0) {
            $attacker->namelessOpenerAction ??= $attacker->namelessActionSerial;
            if ($attacker->namelessOpenerAction === $attacker->namelessActionSerial) {
                $multiplier *= 1 + $attacker->namelessRelicEffects['opener'];
            }
        }
        if (($defender->namelessRelicEffects['first_guard'] ?? 0) > 0) {
            $action = spl_object_id($attacker).':'.$attacker->namelessActionSerial;
            $defender->namelessGuardAction ??= $action;
            if ($defender->namelessGuardAction === $action) {
                $multiplier *= 1 - $defender->namelessRelicEffects['first_guard'];
            }
        }
        $threshold = (float) config('nameless_relics.low_hp_threshold');
        if (($defender->namelessReferenceHp ?? $defender->hp) <= $defender->maxHp * $threshold) {
            $multiplier *= 1 + ($attacker->namelessRelicEffects['finisher'] ?? 0);
            $multiplier *= 1 - ($defender->namelessRelicEffects['last_stand'] ?? 0);
        }
        $multiplier *= 1 + ($attacker->namelessRelicEffects['blood_pact'] ?? 0);
        $guard = (float) ($defender->namelessRelicEffects['mirror_guard'] ?? 0);
        if ($guard > 0) {
            $action = spl_object_id($attacker).':'.$attacker->namelessActionSerial;
            $defender->namelessMirrorBlocked[$action] = (int) floor($damage * $multiplier * $guard);
            $multiplier *= 1 - $guard;
        }

        // 十進の倍率が浮動小数点誤差だけで1ダメージ落ちることを避けて切り捨てる。
        return max(1, (int) floor(round($damage * $multiplier, 6)));
    }

    public function recoverAfterVictory(BattleActor $actor, BattleState $state): void
    {
        foreach (['hp' => ['maxHp', 'victory_hp', 'HP'], 'mp' => ['maxMp', 'victory_sp', 'SP']] as $resource => [$maximum, $effect, $label]) {
            $rate = (float) ($actor->namelessRelicEffects[$effect] ?? 0);
            if ($rate <= 0 || $actor->isDead()) {
                continue;
            }
            $amount = min(max(0, $actor->{$maximum} - $actor->{$resource}), max(1, (int) floor($actor->{$maximum} * $rate)));
            if ($amount > 0) {
                $actor->{$resource} += $amount;
                $state->addLog('<span class="text-emerald-700">遺物の力で'.$label.'が'.$amount.'回復した。</span>');
            }
        }
    }
}
