<?php

namespace App\Services;

final class FieldOperationResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly ?FieldEvent $event = null,
        public readonly ?string $fieldKey = null,
        public readonly ?int $remainingRounds = null,
        public readonly int|string|null $sourceActionId = null,
        public readonly ?string $blockedReason = null,
    ) {
    }

    public static function unchanged(?string $reason = null): self
    {
        return new self(false, blockedReason: $reason);
    }
}
