<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedContextPlan;
use App\Services\Nation\Raid\Simulation\NationRaidResolvedProfileContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NationRaidResolvedProfileContextTest extends TestCase
{
    public function test_boss_set_cache_is_distinct_from_legacy_strategy_cache(): void
    {
        $characterKey = 'nrc2_'.str_repeat('a', 32);
        $bossSet = NationRaidResolvedProfileContext::forProfile($characterKey, 1, NationRaidRules::FORM_SEALED_SCALE,
            NationRaidRules::STRATEGY_BOSS_SET, null, 1);
        $legacy = NationRaidResolvedProfileContext::forProfile($characterKey, 1, NationRaidRules::FORM_SEALED_SCALE,
            NationRaidRules::STRATEGY_ASSAULT, null, 1);
        $this->assertNotSame($legacy->key(), $bossSet->key());
        $this->assertNotSame($legacy->sortieSeed, $bossSet->sortieSeed);
        $this->assertEquals($bossSet, NationRaidResolvedProfileContext::fromArray($bossSet->toArray(), $characterKey));
        $this->assertNotSame('b82bb0cc972b7e0df676f5e7fa24c746f6f459ab9b1317816ede49ea8a162d6e', NationRaidResolvedProfileContext::contractHash());
    }

    public function test_context_key_and_seed_are_stable_and_profile_specific(): void
    {
        $first = NationRaidResolvedProfileContext::forProfile(
            'nrc2_'.str_repeat('1', 32),
            9,
            NationRaidRules::FORM_LINEAGE_INVASION,
            NationRaidRules::STRATEGY_INTERCEPT,
            'counter',
            1,
        );
        $repeat = NationRaidResolvedProfileContext::forProfile(
            'nrc2_'.str_repeat('1', 32),
            9,
            NationRaidRules::FORM_LINEAGE_INVASION,
            NationRaidRules::STRATEGY_INTERCEPT,
            'counter',
            1,
        );
        $nextProfile = NationRaidResolvedProfileContext::forProfile(
            'nrc2_'.str_repeat('1', 32),
            9,
            NationRaidRules::FORM_LINEAGE_INVASION,
            NationRaidRules::STRATEGY_INTERCEPT,
            'counter',
            2,
        );

        $this->assertSame($first->sortieSeed, $repeat->sortieSeed);
        $this->assertSame($first->key(), $repeat->key());
        $this->assertNotSame($first->sortieSeed, $nextProfile->sortieSeed);
        $this->assertNotSame($first->key(), $nextProfile->key());
        $this->assertEquals($first, NationRaidResolvedProfileContext::fromArray(
            $first->toArray(),
            'nrc2_'.str_repeat('1', 32),
        ));
    }

    #[DataProvider('formBoundaryProvider')]
    public function test_canonical_cycle_hp_selects_the_requested_starting_form(string $form): void
    {
        $context = NationRaidResolvedProfileContext::forProfile(
            'nrc2_'.str_repeat('2', 32),
            1,
            $form,
            NationRaidRules::STRATEGY_ASSAULT,
            null,
            1,
        );

        $this->assertSame(
            $form,
            (new NationRaidRules)->formForHp(
                $context->canonicalCycleCurrentHp(),
                NationRaidRules::BOSS_MAX_HP,
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function formBoundaryProvider(): iterable
    {
        yield 'sealed scale' => [NationRaidRules::FORM_SEALED_SCALE];
        yield 'split wing' => [NationRaidRules::FORM_SPLIT_WING];
        yield 'lineage invasion' => [NationRaidRules::FORM_LINEAGE_INVASION];
        yield 'exposed core' => [NationRaidRules::FORM_EXPOSED_CORE];
    }

    public function test_plan_rejects_duplicates_instead_of_silently_deduplicating(): void
    {
        $context = [
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ];

        $this->expectException(InvalidArgumentException::class);

        (new NationRaidResolvedContextPlan)->normalize([$context, $context]);
    }

    public function test_plan_rejects_non_string_dominant_lineage_instead_of_treating_it_as_null(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NationRaidResolvedContextPlan)->normalize([[
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => 0,
        ]]);
    }

    public function test_plan_hash_includes_the_human_coverage_ruling(): void
    {
        $plan = new NationRaidResolvedContextPlan;
        $contexts = [[
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ]];

        $this->assertNotSame(
            $plan->hash($contexts, false),
            $plan->hash($contexts, true),
        );
    }

    public function test_plan_loader_requires_matching_schema_and_context_contract(): void
    {
        $plan = new NationRaidResolvedContextPlan;
        $contexts = [[
            'stage' => 1,
            'starting_form' => NationRaidRules::FORM_SEALED_SCALE,
            'strategy' => NationRaidRules::STRATEGY_ASSAULT,
            'dominant_lineage' => null,
        ]];
        $path = tempnam(sys_get_temp_dir(), 'raid-context-plan-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            'schema_version' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            'coverage_complete' => true,
            'contexts' => $contexts,
        ], JSON_THROW_ON_ERROR));

        try {
            $loaded = $plan->load($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame($plan->normalize($contexts), $loaded['contexts']);
        $this->assertTrue($loaded['coverage_complete']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loaded['source_sha256']);
    }

    public function test_repository_example_plan_matches_the_current_contract(): void
    {
        $loaded = (new NationRaidResolvedContextPlan)->load(
            dirname(__DIR__, 6).'/docs/examples/nation-raid-resolved-context-plan.example.json',
        );

        $this->assertFalse($loaded['coverage_complete']);
        $this->assertCount(1, $loaded['contexts']);
    }
}
