<?php

namespace App\Services;

final readonly class JobArtV2GuardState
{
    public function __construct(
        public float $rate,
        public int $charges = 1,
        public bool $cleanseOnMitigation = false,
        public bool $expiresAtNextOwnAction = false,
        public ?int $sourceSkillId = null,
        public array $onTrigger = [],
    ) {}

    public function withCharges(int $charges): self
    {
        return new self(
            rate: $this->rate,
            charges: max(0, $charges),
            cleanseOnMitigation: $this->cleanseOnMitigation,
            expiresAtNextOwnAction: $this->expiresAtNextOwnAction,
            sourceSkillId: $this->sourceSkillId,
            onTrigger: $this->onTrigger,
        );
    }
}
