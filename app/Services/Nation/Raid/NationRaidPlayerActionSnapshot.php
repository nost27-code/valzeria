<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/**
 * 現行player engineが1手番について確定した、レイドへ渡す読み取り専用snapshot。
 *
 * damageはレイド固有軽減を適用する前の整数値。装備中の5枠はplayer snapshot側で固定し、
 * この手番で既存auto selectionが実際に選んだ対抗戦技のexact identityだけを保持する。
 */
final readonly class NationRaidPlayerActionSnapshot
{
    /** @var list<array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage:?int}> */
    public array $damageSources;

    /** @var list<string> */
    public array $bossDebuffKeysApplied;

    public ?string $selectedCounterplayIdentity;

    /**
     * @param  list<array{kind:string,damage:int,hit_count?:int,defense_ignore_50_damage?:int}>  $damageSources
     * @param  list<string>  $bossDebuffKeysApplied
     */
    public function __construct(
        public int $turn,
        array $damageSources = [],
        ?string $selectedCounterplayIdentity = null,
        array $bossDebuffKeysApplied = [],
        public bool $counterplayHit = true,
        public int $huntingMarkCount = 0,
        public int $breakMarkCount = 0,
    ) {
        if ($turn < 1 || $turn > NationRaidRules::MAX_TURNS) {
            throw new InvalidArgumentException('Player action turn must be between 1 and 20.');
        }

        $normalizedSources = [];
        foreach ($damageSources as $source) {
            $kind = (string) ($source['kind'] ?? '');
            $damage = (int) ($source['damage'] ?? -1);
            $hitCount = (int) ($source['hit_count'] ?? 1);
            $ignoreDamage = array_key_exists('defense_ignore_50_damage', $source)
                ? (int) $source['defense_ignore_50_damage']
                : null;
            if (! in_array($kind, self::allowedDamageKinds(), true) || $damage < 0 || $hitCount < 1
                || ($ignoreDamage !== null && $ignoreDamage < 0)) {
                throw new InvalidArgumentException('Invalid raid player damage source.');
            }
            $normalizedSources[] = [
                'kind' => $kind,
                'damage' => $damage,
                'hit_count' => $hitCount,
                'defense_ignore_50_damage' => $ignoreDamage,
            ];
        }

        $selectedIdentity = trim((string) ($selectedCounterplayIdentity ?? ''));
        $this->selectedCounterplayIdentity = $selectedIdentity === '' ? null : $selectedIdentity;
        $this->damageSources = $normalizedSources;
        $this->bossDebuffKeysApplied = self::normalizeIdentities($bossDebuffKeysApplied);
    }

    public static function empty(int $turn): self
    {
        return new self($turn);
    }

    /** @return list<string> */
    private static function allowedDamageKinds(): array
    {
        return [
            NationRaidRules::DAMAGE_DIRECT,
            NationRaidRules::DAMAGE_SIMULTANEOUS,
            NationRaidRules::DAMAGE_DOT,
            NationRaidRules::DAMAGE_COUNTER,
            NationRaidRules::DAMAGE_ECLIPSE_BACKLASH,
        ];
    }

    /** @param list<string> $identities @return list<string> */
    private static function normalizeIdentities(array $identities): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $identity): string => trim((string) $identity),
            $identities,
        ))));
    }
}
