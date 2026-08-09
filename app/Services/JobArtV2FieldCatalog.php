<?php

namespace App\Services;

use App\Models\Skill;

final class JobArtV2FieldCatalog
{
    private const CYCLE_KEYS = ['star_light', 'melody', 'sanctuary', 'silence', 'observation'];

    private const FIELDS = [
        'star_light' => [
            'name' => '星光の場',
            'effects' => [
                ['axis' => 'damage_multiplier', 'value' => 0.10, 'target' => 'owner', 'scope' => 'magical'],
            ],
        ],
        'melody' => [
            'name' => '旋律の場',
            'effects' => [
                ['axis' => 'activation_rate_delta', 'value' => 3, 'target' => 'owner', 'scope' => 'job_art'],
            ],
        ],
        'sanctuary' => [
            'name' => '聖域の場',
            'effects' => [
                ['axis' => 'heal_multiplier', 'value' => 0.10, 'target' => 'owner', 'scope' => 'all'],
            ],
        ],
        'silence' => [
            'name' => '静寂の場',
            'effects' => [
                ['axis' => 'resource_gain_delta', 'value' => -1, 'target' => 'opponent', 'scope' => 'all'],
            ],
        ],
        'observation' => [
            'name' => '天測の場',
            'effects' => [
                ['axis' => 'accuracy_delta', 'value' => 5, 'target' => 'owner', 'scope' => 'all'],
                ['axis' => 'resource_gain_delta', 'value' => 1, 'target' => 'owner', 'scope' => 'all'],
            ],
        ],
    ];

    public function __construct(
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function forArt(Skill $skill): ?array
    {
        return $this->prototypeCatalog->artFieldMetadata($skill);
    }

    public function isTrustedCurrentJobArt(?int $currentJobId, Skill $skill): bool
    {
        return $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill);
    }

    public function isPortableJob63FieldArt(?int $currentJobId, Skill $skill): bool
    {
        return $currentJobId !== null
            && (int) $skill->job_id === 63
            && in_array((int) $skill->learn_rank, [1, 5], true)
            && $this->prototypeCatalog->supportsCurrentJob($currentJobId)
            && $this->prototypeCatalog->isTrustedArtProfile($skill)
            && $this->prototypeCatalog->isSamePrimaryLineage($currentJobId, $skill);
    }

    /** @return list<string> */
    public function cycleKeys(): array
    {
        return self::CYCLE_KEYS;
    }

    /** @return array{name:string,effects:array<int,array{axis:string,value:float|int,target:string,scope:string}>}|null */
    public function field(string $key): ?array
    {
        return self::FIELDS[$key] ?? null;
    }

    public function name(string $key): string
    {
        return (string) (self::FIELDS[$key]['name'] ?? $key);
    }
}
