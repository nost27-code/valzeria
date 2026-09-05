<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** context別profile cacheを生成する、review済みplanの正規化・hash契約。 */
final class NationRaidResolvedContextPlan
{
    public const SCHEMA_VERSION = 'nation-raid-resolved-context-plan-v1';

    /**
     * @param  list<array<string, mixed>>  $contexts
     * @return list<array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string}>
     */
    public function normalize(array $contexts): array
    {
        if (! array_is_list($contexts)) {
            throw new InvalidArgumentException('Resolved context plan must be a JSON list.');
        }

        $normalized = [];
        $requiredKeys = ['dominant_lineage', 'stage', 'starting_form', 'strategy'];
        foreach ($contexts as $context) {
            if (! is_array($context)) {
                throw new InvalidArgumentException('Resolved context plan entries must be objects.');
            }

            $actualKeys = array_keys($context);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $requiredKeys
                || ! is_int($context['stage'])
                || ! is_string($context['starting_form'])
                || ! is_string($context['strategy'])
                || (! is_null($context['dominant_lineage']) && ! is_string($context['dominant_lineage']))
            ) {
                throw new InvalidArgumentException('Resolved context plan entry has an invalid shape.');
            }

            $value = new NationRaidResolvedProfileContext(
                stage: $context['stage'],
                startingForm: $context['starting_form'],
                strategy: $context['strategy'],
                dominantLineage: $context['dominant_lineage'],
                profileNo: 1,
                sortieSeed: 1,
            );
            if (isset($normalized[$value->baseKey()])) {
                throw new InvalidArgumentException('Resolved context plan contains a duplicate entry.');
            }
            $normalized[$value->baseKey()] = [
                'stage' => $value->stage,
                'starting_form' => $value->startingForm,
                'strategy' => $value->strategy,
                'dominant_lineage' => $value->dominantLineage,
            ];
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /** @param list<array<string, mixed>> $contexts */
    public function hash(array $contexts, bool $coverageComplete): string
    {
        return hash('sha256', NationRaidJson::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            'coverage_complete' => $coverageComplete,
            'contexts' => $this->normalize($contexts),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{contexts:list<array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string}>,coverage_complete:bool,source_sha256:string}
     */
    public function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Resolved context plan file is not readable.');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Resolved context plan file could not be read.');
        }
        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Resolved context plan file is not valid JSON.', previous: $exception);
        }
        if (! is_array($payload)
            || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($payload['context_contract_hash'] ?? null) !== NationRaidResolvedProfileContext::contractHash()
            || ! is_bool($payload['coverage_complete'] ?? null)
            || ! is_array($payload['contexts'] ?? null)
        ) {
            throw new RuntimeException('Resolved context plan document contract is invalid.');
        }

        $contexts = $this->normalize($payload['contexts']);
        if ($payload['coverage_complete'] && $contexts === []) {
            throw new RuntimeException('Resolved context coverage cannot be complete with an empty plan.');
        }

        return [
            'contexts' => $contexts,
            'coverage_complete' => $payload['coverage_complete'],
            'source_sha256' => hash('sha256', $contents),
        ];
    }
}
