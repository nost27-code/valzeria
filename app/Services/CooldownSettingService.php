<?php

namespace App\Services;

class CooldownSettingService
{
    public function explorationBattleSeconds(): int
    {
        return $this->explorationBattleSecondsForDepthKey('surface');
    }

    public function explorationBattleSecondsForDepthKey(?string $depthKey): int
    {
        return match ($depthKey) {
            'inner' => 15,
            'deep', 'deepest', 'otherworld' => 20,
            default => 10,
        };
    }

    public function explorationBattleSecondsForDepthTier(?array $tier): int
    {
        return $this->explorationBattleSecondsForDepthKey((string) ($tier['key'] ?? 'surface'));
    }

    public function innSeconds(): int
    {
        return 0;
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
