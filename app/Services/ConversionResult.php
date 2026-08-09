<?php

namespace App\Services;

final readonly class ConversionResult
{
    public function __construct(
        public int $sourceActionId,
        public string $actorKey,
        public int $hpBefore,
        public int $requestedHpCost,
        public int $actualHpLoss,
        public int $hpAfter,
        public int $spBeforeConversion,
        public int $requestedSpGain,
        public int $actualSpGain,
        public int $spAfterConversion,
        public bool $success,
    ) {}

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'source_action_id' => $this->sourceActionId,
            'actor_key' => $this->actorKey,
            'hp_before' => $this->hpBefore,
            'requested_hp_cost' => $this->requestedHpCost,
            'actual_hp_loss' => $this->actualHpLoss,
            'hp_after' => $this->hpAfter,
            'sp_before_conversion' => $this->spBeforeConversion,
            'requested_sp_gain' => $this->requestedSpGain,
            'actual_sp_gain' => $this->actualSpGain,
            'sp_after_conversion' => $this->spAfterConversion,
            'success' => $this->success,
        ];
    }
}
