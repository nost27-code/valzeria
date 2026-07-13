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

    public array $eventData = [];

    public array $jobResult = []; // 職業ランクアップやマスター等の情報

    public int $dropBonusPercent = 0;
    public int $rareBonusPercent = 0;

    public int $playerHpAfter = 0;
    public int $playerMpAfter = 0;
    public ?array $explorationSupportSnapshot = null;

    // 戦闘ログ観測用のテレメトリ（log_textの解析なしで集計できるようにする）。
    public int $turnCount = 0;
    public int $playerHpBefore = 0;
    public int $damageDealt = 0; // プレイヤーが敵に与えた合計ダメージ
    public int $damageTaken = 0; // プレイヤーが受けた合計ダメージ
    public array $enemyDurability = []; // ['hp'=>float,'def_spr'=>float,'atk_mag'=>float,'tier'=>string]
}
