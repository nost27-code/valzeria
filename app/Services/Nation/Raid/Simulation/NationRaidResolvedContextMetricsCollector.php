<?php

namespace App\Services\Nation\Raid\Simulation;

/**
 * 1回のPhase 2 sweepで要求されたcontextと、正本cacheの生成・参照状況を集計する。
 *
 * Character keyやprofile keyは成果物へ出さず、planの基底contextだけを保持する。
 */
final class NationRaidResolvedContextMetricsCollector
{
    public const VERSION = 'nation-raid-resolved-context-cache-metrics-v1';

    /** @var array<string, array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string}> */
    private array $plannedContexts = [];

    /** @var array<string, array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string,request_count:int}> */
    private array $runtimeContexts = [];

    private int $characterCount;

    private int $profilesPerContext;

    private int $generatedProfileCount = 0;

    private int $completeCharacterCount = 0;

    private bool $runtimeStarted = false;

    private int $runtimeContextRequests = 0;

    private int $cacheLookupRequests = 0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    public function __construct(
        array $snapshot,
        private readonly NationRaidResolvedContextPlan $contextPlan,
        private readonly bool $authoritativeCache,
    ) {
        $contexts = is_array($snapshot['resolved_context_plan'] ?? null)
            ? $snapshot['resolved_context_plan']
            : [];
        foreach ($this->contextPlan->normalize($contexts) as $context) {
            $this->plannedContexts[$this->baseKey($context)] = $context;
        }

        $characters = is_array($snapshot['characters'] ?? null)
            ? array_values($snapshot['characters'])
            : [];
        $this->characterCount = count($characters);
        $this->profilesPerContext = is_int($snapshot['resolved_context_profiles_per_context'] ?? null)
            ? max(0, $snapshot['resolved_context_profiles_per_context'])
            : 0;
        $expectedPerCharacter = count($this->plannedContexts) * $this->profilesPerContext;
        foreach ($characters as $character) {
            $profiles = is_array($character['resolved_context_profiles'] ?? null)
                ? $character['resolved_context_profiles']
                : [];
            $this->generatedProfileCount += count($profiles);
            if ($expectedPerCharacter > 0 && count($profiles) === $expectedPerCharacter) {
                $this->completeCharacterCount++;
            }
        }
    }

    public function startRuntime(): void
    {
        $this->runtimeStarted = true;
    }

    /**
     * $cacheHit=nullは参考profile経路で、cache lookupを行っていないことを表す。
     */
    public function record(NationRaidResolvedProfileContext $context, ?bool $cacheHit): void
    {
        $this->runtimeContextRequests++;
        $baseKey = $context->baseKey();
        if (! isset($this->runtimeContexts[$baseKey])) {
            $this->runtimeContexts[$baseKey] = [
                'stage' => $context->stage,
                'starting_form' => $context->startingForm,
                'strategy' => $context->strategy,
                'dominant_lineage' => $context->dominantLineage,
                'request_count' => 0,
            ];
        }
        $this->runtimeContexts[$baseKey]['request_count']++;

        if ($cacheHit === null) {
            return;
        }
        $this->cacheLookupRequests++;
        if ($cacheHit) {
            $this->cacheHits++;
        } else {
            $this->cacheMisses++;
        }
    }

    /** @return array<string, mixed> */
    public function report(bool $coverageCompleteClaimed): array
    {
        ksort($this->plannedContexts, SORT_STRING);
        ksort($this->runtimeContexts, SORT_STRING);

        $plannedKeys = array_keys($this->plannedContexts);
        $runtimeKeys = array_keys($this->runtimeContexts);
        $referencedPlannedKeys = array_values(array_intersect($plannedKeys, $runtimeKeys));
        $unusedKeys = array_values(array_diff($plannedKeys, $runtimeKeys));
        $unplannedKeys = array_values(array_diff($runtimeKeys, $plannedKeys));

        $plannedContextCount = count($plannedKeys);
        $expectedProfiles = $this->characterCount * $plannedContextCount * $this->profilesPerContext;
        $generationRate = $expectedProfiles > 0
            ? $this->generatedProfileCount / $expectedProfiles
            : null;
        $characterCompletionRate = $this->characterCount > 0
            ? $this->completeCharacterCount / $this->characterCount
            : null;
        $generationComplete = $expectedProfiles > 0
            && $this->generatedProfileCount === $expectedProfiles
            && $this->completeCharacterCount === $this->characterCount;
        $cacheHitRate = $this->cacheLookupRequests > 0
            ? $this->cacheHits / $this->cacheLookupRequests
            : null;
        $planUtilizationRate = $plannedContextCount > 0
            ? count($referencedPlannedKeys) / $plannedContextCount
            : null;

        $reachableContexts = array_values($this->runtimeContexts);
        $candidateContexts = $this->contextPlan->normalize(array_map(
            static fn (array $context): array => [
                'stage' => $context['stage'],
                'starting_form' => $context['starting_form'],
                'strategy' => $context['strategy'],
                'dominant_lineage' => $context['dominant_lineage'],
            ],
            $reachableContexts,
        ));
        $candidatePlan = [
            'schema_version' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            // 実測範囲の候補にすぎない。reviewerが確認するまでtrueへ上げない。
            'coverage_complete' => false,
            'contexts' => $candidateContexts,
        ];

        $operationalGateMet = $this->authoritativeCache
            && $coverageCompleteClaimed
            && $generationComplete
            && $this->runtimeStarted
            && $this->runtimeContextRequests > 0
            && $this->cacheLookupRequests === $this->runtimeContextRequests
            && $this->cacheMisses === 0
            && $unplannedKeys === [];

        return [
            'version' => self::VERSION,
            'mode' => $this->authoritativeCache ? 'authoritative_cache' : 'reference_reachability',
            'cache_generation_completion_rate' => $generationRate,
            'runtime_cache_hit_rate' => $cacheHitRate,
            'plan_utilization_rate' => $planUtilizationRate,
            'cache_operational_gate_met' => $operationalGateMet,
            'generation' => [
                'planned_contexts' => $plannedContextCount,
                'characters' => $this->characterCount,
                'profiles_per_context' => $this->profilesPerContext,
                'expected_profiles' => $expectedProfiles,
                'generated_profiles' => $this->generatedProfileCount,
                'complete_characters' => $this->completeCharacterCount,
                'character_completion_rate' => $characterCompletionRate,
                'coverage_complete_claimed' => $coverageCompleteClaimed,
                'complete' => $generationComplete,
            ],
            'runtime_cache' => [
                'runtime_started' => $this->runtimeStarted,
                'context_requests' => $this->runtimeContextRequests,
                'lookup_requests' => $this->cacheLookupRequests,
                'hits' => $this->cacheHits,
                'misses' => $this->cacheMisses,
                'all_lookups_hit' => $this->authoritativeCache && $this->cacheLookupRequests > 0
                    ? $this->cacheMisses === 0
                    : null,
            ],
            'plan_utilization' => [
                'planned_contexts' => $plannedContextCount,
                'unique_referenced_contexts' => count($runtimeKeys),
                'referenced_planned_contexts' => count($referencedPlannedKeys),
                'unplanned_referenced_contexts' => count($unplannedKeys),
                'plan_covers_runtime' => $plannedContextCount > 0 && $unplannedKeys === [],
                'unused_context_keys' => $unusedKeys,
                'unplanned_context_keys' => $unplannedKeys,
            ],
            'reachability' => [
                'authoritative' => $this->authoritativeCache,
                'context_requests' => $this->runtimeContextRequests,
                'unique_contexts' => count($reachableContexts),
                'contexts' => $reachableContexts,
                'candidate_plan_hash' => $this->contextPlan->hash($candidateContexts, false),
                'review_candidate_plan' => $candidatePlan,
            ],
        ];
    }

    /** @param array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string} $context */
    private function baseKey(array $context): string
    {
        return (new NationRaidResolvedProfileContext(
            stage: $context['stage'],
            startingForm: $context['starting_form'],
            strategy: $context['strategy'],
            dominantLineage: $context['dominant_lineage'],
            profileNo: 1,
            sortieSeed: 1,
        ))->baseKey();
    }
}
