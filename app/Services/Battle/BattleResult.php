<?php

namespace App\Services\Battle;

class BattleResult
{
    public string $result = ''; // 'victory', 'defeat', 'timeout'

    public array $logs = [];

    public int $exp = 0;
    public int $gold = 0;
    public int $jobExp = 0;

    public array $drops = [];

    public array $enemyStatDisplay = [];

    public array $jobResult = []; // 職業ランクアップやマスター等の情報

    public int $dropBonusPercent = 0;
    public int $rareBonusPercent = 0;

    public int $playerHpAfter = 0;
    public int $playerMpAfter = 0;
}
