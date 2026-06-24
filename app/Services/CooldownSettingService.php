<?php

namespace App\Services;

class CooldownSettingService
{
    public function explorationBattleSeconds(): int
    {
        return $this->seconds('cooldown.exploration_battle_seconds', 20, 0, 3600);
    }

    public function innSeconds(): int
    {
        return $this->seconds('cooldown.inn_seconds', 40, 0, 3600);
    }

    public function arenaRankBattleSeconds(): int
    {
        return $this->seconds('cooldown.arena_rank_battle_seconds', 300, 0, 86400);
    }

    public function champBattleSeconds(): int
    {
        return $this->seconds('cooldown.champ_battle_seconds', 600, 0, 86400);
    }

    private function seconds(string $key, int $default, int $min, int $max): int
    {
        $value = app(GameSettingService::class)->getInt($key, $default);

        return max($min, min($max, $value));
    }
}
