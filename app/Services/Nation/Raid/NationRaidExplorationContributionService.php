<?php

namespace App\Services\Nation\Raid;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\NationMembership;
use App\Models\NationRaidEvent;
use App\Models\NationRaidInvasionDamage;
use Illuminate\Support\Facades\Log;

/** 探索本体へ失敗を波及させず、兵站準備と復興の監査記録を追加する。 */
final readonly class NationRaidExplorationContributionService
{
    public function __construct(
        private NationRaidPreparationService $preparation,
        private NationRaidReconstructionService $reconstruction,
    ) {}

    public function record(Character $character, BattleLog $battleLog): void
    {
        if (NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_SCHEDULED)
            ->whereNotNull('preparation_frozen_at')->where('starts_at', '>', now())->exists()) {
            $this->attempt('preparation', fn () => $this->preparation->recordNormalExplorationVictory($character, $battleLog), $battleLog);
        }

        $nationId = NationMembership::query()->where('character_id', $character->id)->value('nation_id');
        if ($nationId !== null && NationRaidInvasionDamage::query()->where('nation_id', $nationId)->outstanding()->exists()) {
            $this->attempt('reconstruction', fn () => $this->reconstruction->recordNormalExplorationVictory($character, $battleLog), $battleLog);
        }
    }

    private function attempt(string $type, callable $callback, BattleLog $battleLog): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('Nation raid exploration contribution failed', [
                'type' => $type,
                'battle_log_id' => $battleLog->id,
                'error_class' => $exception::class,
            ]);
        }
    }
}
