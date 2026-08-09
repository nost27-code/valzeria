<?php

namespace App\Services;

class ResourceChangeResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly ?string $resourceKey = null,
        public readonly ?string $resourceName = null,
        public readonly int $delta = 0,
        public readonly int $before = 0,
        public readonly int $after = 0,
        public readonly int $cap = 0,
        public readonly ?ResourceEvent $event = null,
        public readonly ?int $sourceActionId = null,
        public readonly ?string $blockedReason = null,
    ) {
    }

    public static function unchanged(?string $blockedReason = null): self
    {
        return new self(false, blockedReason: $blockedReason);
    }

    public function logMessage(): ?string
    {
        if (!$this->applied || $this->resourceName === null || $this->delta === 0) {
            return null;
        }

        $signed = $this->delta > 0 ? "+{$this->delta}" : (string) $this->delta;

        return "{$this->resourceName} {$signed}（{$this->after}/{$this->cap}）";
    }
}
