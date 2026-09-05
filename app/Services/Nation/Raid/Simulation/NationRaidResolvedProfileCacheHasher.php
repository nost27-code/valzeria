<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use InvalidArgumentException;

/** random_int()を含むbridge出力のcompact cacheを、内容hash付きの正本へ固定する。 */
final class NationRaidResolvedProfileCacheHasher
{
    /** @param list<array<string, mixed>> $profiles @return list<array<string, mixed>> */
    public function sealProfiles(array $profiles): array
    {
        foreach ($profiles as &$profile) {
            if (! is_array($profile)) {
                throw new InvalidArgumentException('Resolved context profile must be an array.');
            }
            $profile['profile_hash'] = $this->profileHash($profile);
        }
        unset($profile);

        usort($profiles, static fn (array $left, array $right): int => ((string) ($left['context_key'] ?? '')) <=> ((string) ($right['context_key'] ?? ''))
        );

        return $profiles;
    }

    /** @param array<string, mixed> $profile */
    public function profileHash(array $profile): string
    {
        unset($profile['profile_hash']);

        return $this->hash($profile);
    }

    /** @param list<array<string, mixed>> $profiles */
    public function characterCacheHash(array $profiles): string
    {
        return $this->hash($profiles);
    }

    /** @param list<array<string, mixed>> $characters */
    public function rootCacheHash(
        string $rulesetHash,
        string $integrationHash,
        string $modelVersion,
        string $contextContractHash,
        array $characters,
    ): string {
        $entries = [];
        foreach ($characters as $character) {
            $entries[] = [
                'character_key' => $character['character_key'] ?? null,
                'cache_hash' => $character['resolved_context_profile_cache_hash'] ?? null,
            ];
        }
        usort($entries, static fn (array $left, array $right): int => ((string) $left['character_key']) <=> ((string) $right['character_key'])
        );

        return $this->hash([
            'ruleset_hash' => $rulesetHash,
            'integration_hash' => $integrationHash,
            'resolved_context_profile_model' => $modelVersion,
            'resolved_context_contract_hash' => $contextContractHash,
            'characters' => $entries,
        ]);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', NationRaidJson::encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $entry): mixed => $this->canonicalize($entry), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
    }
}
