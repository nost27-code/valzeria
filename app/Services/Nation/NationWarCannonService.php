<?php

namespace App\Services\Nation;

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;

final class NationWarCannonService
{
    public function __construct(private readonly DamageCalculator $damageCalculator) {}

    /** @return array{damage:int,direct_hit:bool,attack_type:string,attack_power:int} */
    public function fire(BattleActor $target, int $level): array
    {
        $settings = app(NationWarSettingsService::class);
        $spec = $settings->cannonSpec($level);
        $targetDamage = max(1, (int) floor($target->maxHp * $spec['ratio']));
        // 防御・精神の高い側を正式な敵→プレイヤー式へ渡し、どちらか一方の低さだけで
        // 砲撃耐久を抜けないようにする。乱数はDamageCalculatorの85〜115%をそのまま使う。
        $magical = $target->effectivePercentageSpr() >= $target->effectivePercentageDef();
        $defense = (int) round($magical ? $target->effectivePercentageSpr() : $target->effectivePercentageDef());
        $attack = $this->damageCalculator->attackPowerForPveEnemyTargetDamage($targetDamage, $defense);
        $cannon = new BattleActor('魔導砲', false, [
            'hp' => 1, 'max_hp' => 1, 'mp' => 0, 'max_mp' => 0,
            'str' => $attack, 'def' => 0, 'agi' => 1, 'mag' => $attack, 'spr' => 0, 'luk' => 1,
        ]);
        $damage = $magical
            ? $this->damageCalculator->calculateMagicalDamage($cannon, $target, 100, false, $attack, $defense)
            : $this->damageCalculator->calculatePhysicalDamage($cannon, $target, 100, false, $attack, $defense);
        $direct = random_int(1, 10000) <= (int) round($settings->cannonDirectHitRate() * 100);
        if ($direct) $damage = max(1, (int) floor($damage * $settings->cannonDirectHitMultiplier()));

        return ['damage' => $damage, 'direct_hit' => $direct, 'attack_type' => $magical ? 'magical' : 'physical', 'attack_power' => $attack];
    }

    public function firesOnTurn(int $level, int $turn): bool
    {
        return in_array($turn, app(NationWarSettingsService::class)->cannonSpec($level)['turns'], true);
    }
}
