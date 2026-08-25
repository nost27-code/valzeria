<?php

use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\SpeedBreakthroughService;
use App\Services\Battle\SpeedExtraActionService;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$calculator = app(DamageCalculator::class);
$breakthrough = app(SpeedBreakthroughService::class);
$extraAction = app(SpeedExtraActionService::class);

/*
 * PVP_AGILITY_CONVERSION_REVIEW_2026-08-25.md の scratchpad 前提を再現する。
 * 戦技は実測平均威力へ丸め、会心・SP・戦技選択・多段分割は再現しない。
 * 防御型は瞬舞から攻撃2,500を防御へ点数中立で振り替えた合成ビルド。
 */
$profiles = [
    'pumpkin' => ['name' => '南瓜クラス', 'hp' => 28756, 'attack' => 10223, 'defense' => 6548, 'agi' => 10152, 'power' => 166],
    'shunbu' => ['name' => '瞬舞クラス', 'hp' => 31653, 'attack' => 13445, 'defense' => 10978, 'agi' => 6592, 'power' => 160],
    'defense' => ['name' => '防御クラス', 'hp' => 31653, 'attack' => 10945, 'defense' => 13478, 'agi' => 6592, 'power' => 160],
];

$makeActor = static function (array $profile, bool $player): BattleActor {
    return new BattleActor($profile['name'], $player, [
        'hp' => $profile['hp'],
        'max_hp' => $profile['hp'],
        'mp' => 100,
        'max_mp' => 100,
        'str' => $profile['attack'],
        'def' => $profile['defense'],
        'agi' => $profile['agi'],
        'mag' => $profile['attack'],
        'spr' => $profile['defense'],
        'luk' => 100,
    ]);
};

$fight = static function (
    array $attackerProfile,
    array $defenderProfile,
    bool $speedBreakthroughEnabled,
) use ($calculator, $breakthrough, $extraAction, $makeActor): bool {
    $attacker = $makeActor($attackerProfile, true);
    $defender = $makeActor($defenderProfile, false);

    $act = static function (BattleActor $source, BattleActor $target, int $power) use (
        $calculator,
        $breakthrough,
        $speedBreakthroughEnabled,
    ): void {
        if (! $calculator->isHit($source, $target, 100, 0.08, 84, 97)) {
            return;
        }

        $additionalIgnoreRate = 0.0;
        if ($speedBreakthroughEnabled) {
            $nominalRate = $breakthrough->nominalRate($source, $target);
            $additionalIgnoreRate = $breakthrough->rates($nominalRate, 0.0)['additional_ignore_rate'];
        }

        $damage = $calculator->calculateRankBattleDamage(
            $source,
            $target,
            'physical',
            $power,
            false,
            minimumDamageGuaranteeEnabled: false,
            damageCapEnabled: false,
            baseDamageMultiplier: 0.5,
            additionalDefenseIgnoreRate: $additionalIgnoreRate,
        );
        $target->takeDamage($damage);
    };

    for ($turn = 1; $turn <= 100 && ! $attacker->isDead() && ! $defender->isDead(); $turn++) {
        $attackerFirst = ($attacker->effectiveAgi() + rand(0, 2)) >= ($defender->effectiveAgi() + rand(0, 2));
        $first = $attackerFirst
            ? [$attacker, $defender, $attackerProfile['power']]
            : [$defender, $attacker, $defenderProfile['power']];
        $second = $attackerFirst
            ? [$defender, $attacker, $defenderProfile['power']]
            : [$attacker, $defender, $attackerProfile['power']];

        $act(...$first);
        if ($attacker->isDead() || $defender->isDead()) {
            break;
        }

        $act(...$second);
        if ($attacker->isDead() || $defender->isDead()) {
            break;
        }

        foreach ([[$attacker, $defender, $attackerProfile['power']], [$defender, $attacker, $defenderProfile['power']]] as $candidate) {
            if ($extraAction->shouldGrantExtraAction($candidate[0], $candidate[1])) {
                $act(...$candidate);
                break;
            }
        }
    }

    if ($defender->isDead()) {
        return true;
    }

    return ! $attacker->isDead() && $attacker->hasHigherRemainingHpRatioThan($defender);
};

$count = filter_var(
    $argv[1] ?? 5000,
    FILTER_VALIDATE_INT,
    ['options' => ['default' => 5000, 'min_range' => 1]],
);

$run = static function (
    array $a,
    array $b,
    bool $enabled,
    int $seed,
) use ($fight, $count): float {
    mt_srand($seed);
    $wins = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($fight($a, $b, $enabled)) {
            $wins++;
        }
    }

    return $wins / $count * 100;
};

$bidirectional = static function (
    array $a,
    array $b,
    bool $enabled,
    int $seed,
) use ($run): array {
    $aToB = $run($a, $b, $enabled, $seed);
    $bAsAttacker = $run($b, $a, $enabled, $seed + 1);
    $bToA = 100 - $bAsAttacker;

    return [
        'a_to_b_a_win_rate' => round($aToB, 2),
        'b_to_a_a_win_rate' => round($bToA, 2),
        'bidirectional_average' => round(($aToB + $bToA) / 2, 2),
    ];
};

$pumpkin = $bidirectional($profiles['pumpkin'], $profiles['shunbu'], true, 20260825);
$defense = $bidirectional($profiles['defense'], $profiles['pumpkin'], true, 20260827);
$shunbuMirrorBefore = $bidirectional($profiles['shunbu'], $profiles['shunbu'], false, 20260829);
$shunbuMirrorAfter = $bidirectional($profiles['shunbu'], $profiles['shunbu'], true, 20260829);
$pumpkinMirrorBefore = $bidirectional($profiles['pumpkin'], $profiles['pumpkin'], false, 20260831);
$pumpkinMirrorAfter = $bidirectional($profiles['pumpkin'], $profiles['pumpkin'], true, 20260831);

$result = [
    'count_per_direction' => $count,
    'pumpkin_vs_shunbu' => $pumpkin + [
        'target' => 21.3,
        'tolerance_points' => 1.5,
        'passed' => abs($pumpkin['bidirectional_average'] - 21.3) <= 1.5,
    ],
    'defense_vs_pumpkin' => $defense + [
        'target' => 71.3,
        'tolerance_points' => 1.5,
        'passed' => abs($defense['bidirectional_average'] - 71.3) <= 1.5,
    ],
    'shunbu_mirror' => [
        'before' => $shunbuMirrorBefore['bidirectional_average'],
        'after' => $shunbuMirrorAfter['bidirectional_average'],
        'change_points' => round($shunbuMirrorAfter['bidirectional_average'] - $shunbuMirrorBefore['bidirectional_average'], 2),
        'passed' => abs($shunbuMirrorAfter['bidirectional_average'] - $shunbuMirrorBefore['bidirectional_average']) <= 2.0,
    ],
    'pumpkin_mirror' => [
        'before' => $pumpkinMirrorBefore['bidirectional_average'],
        'after' => $pumpkinMirrorAfter['bidirectional_average'],
        'change_points' => round($pumpkinMirrorAfter['bidirectional_average'] - $pumpkinMirrorBefore['bidirectional_average'], 2),
        'passed' => abs($pumpkinMirrorAfter['bidirectional_average'] - $pumpkinMirrorBefore['bidirectional_average']) <= 2.0,
    ],
];

$result['passed'] = $result['pumpkin_vs_shunbu']['passed']
    && $result['defense_vs_pumpkin']['passed']
    && $result['shunbu_mirror']['passed']
    && $result['pumpkin_mirror']['passed'];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($result['passed'] ? 0 : 1);
