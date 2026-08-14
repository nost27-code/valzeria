<?php

namespace App\Services;

use App\Models\Skill;

final class JobArtV2DeckRoleResolution
{
    /**
     * @param array<string, JobArtV2DeckRole> $rolesByArtKey
     * @param array<string, string> $blockReasonsByArtKey
     * @param list<string> $formalLineages
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $mainLineage,
        public readonly ?string $secondaryLineage,
        private readonly array $rolesByArtKey = [],
        private readonly array $blockReasonsByArtKey = [],
        private readonly array $formalLineages = [],
    ) {}

    public static function inactive(): self
    {
        return new self(false, null, null);
    }

    public function roleFor(Skill $skill): ?JobArtV2DeckRole
    {
        return $this->rolesByArtKey[self::artKey($skill)] ?? null;
    }

    public function blockReasonFor(Skill $skill): ?string
    {
        return $this->blockReasonsByArtKey[self::artKey($skill)] ?? null;
    }

    public function isValid(): bool
    {
        return $this->active && $this->blockReasonsByArtKey === [];
    }

    public function hasFormalLineage(string $lineage): bool
    {
        return $this->active && in_array($lineage, $this->formalLineages, true);
    }

    /** @return list<string> */
    public function formalLineages(): array
    {
        return $this->formalLineages;
    }

    public static function artKey(Skill $skill): string
    {
        return (int) $skill->job_id.':'.(int) $skill->learn_rank.':'.trim((string) $skill->name);
    }
}
