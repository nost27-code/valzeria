<?php

namespace App\Services\Battle;

class BattleState
{
    public BattleActor $player;
    public BattleActor $enemy;

    public int $turnCount = 0;
    public int $maxTurns = 50;

    public array $logs = [];
    public int $goldBonusPercent = 0;
    public int $dropBonusPercent = 0;
    public int $rareBonusPercent = 0;
    public int $materialBonusPercent = 0;
    public array $jobArtCooldowns = [];
    public array $jobArtUseCounts = [];
    public bool $valmonAssistUsed = false;
    public string $battleType;

    public function __construct(BattleActor $player, BattleActor $enemy, string $battleType = 'pve')
    {
        $this->player = $player;
        $this->enemy = $enemy;
        $this->battleType = $battleType;
    }

    public function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    public function isBattleEnded(): bool
    {
        return $this->player->isDead() || $this->enemy->isDead() || $this->turnCount >= $this->maxTurns;
    }
}
