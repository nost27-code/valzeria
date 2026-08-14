<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use Tests\TestCase;

class JobArtV2ActionResolverTest extends TestCase
{
    public function test_hit_resolution_is_fail_closed_and_disabled_by_default(): void
    {
        $config = require base_path('config/battle.php');

        $this->assertFalse($config['job_art_v2']['hit_resolution']);

        foreach ([
            ['dynamic' => false, 'hit' => true, 'job' => 24],
            ['dynamic' => true, 'hit' => false, 'job' => 24],
            ['dynamic' => true, 'hit' => true, 'job' => 39],
        ] as $case) {
            config([
                'battle.job_art_v2.dynamic_single' => $case['dynamic'],
                'battle.job_art_v2.hit_resolution' => $case['hit'],
            ]);
            $random = $this->random([1]);
            $result = $this->resolver($random)->resolveJobArt(
                $this->actor($case['job']),
                $this->actor(null, false),
                $this->art(),
                'pve',
            );

            $this->assertNull($result);
            $this->assertSame(0, $random->calls);
        }
    }

    public function test_only_trusted_damaging_job_art_templates_are_resolved(): void
    {
        $this->enable();

        foreach ([
            ['template' => 'PHYSICAL_DAMAGE', 'skill_type' => 'job_art', 'resolved' => true],
            ['template' => 'DAMAGE_BUFF', 'skill_type' => 'job_art', 'resolved' => true],
            ['template' => 'HEAL', 'skill_type' => 'job_art', 'resolved' => false],
            ['template' => 'UNKNOWN_DAMAGE_WORD', 'skill_type' => 'job_art', 'resolved' => false],
            ['template' => 'PHYSICAL_DAMAGE', 'skill_type' => 'active', 'resolved' => false],
        ] as $case) {
            $random = $this->random([1]);
            $result = $this->resolver($random)->resolveJobArt(
                $this->actor(24),
                $this->actor(null, false),
                $this->art($case['template'], $case['skill_type']),
                'pve',
            );

            $this->assertSame($case['resolved'], $result === HitResult::HIT, $case['template']);
            $this->assertSame($case['resolved'] ? 1 : 0, $random->calls, $case['template']);
        }
    }

    public function test_all_known_non_damage_and_unclassified_templates_skip_hit_randomness(): void
    {
        $this->enable();

        foreach ([
            'SELF_BUFF',
            'ENEMY_DEBUFF',
            'GUARD_BARRIER',
            'HEAL',
            'HEAL_CLEANSE',
            'GUTS',
            'REWARD_GOLD',
            'REWARD_DROP',
            'REWARD_MIXED',
            'TIME_CONTROL_CURRENT_ONLY',
            'FIELD_UNCLASSIFIED',
        ] as $template) {
            $random = $this->random([1]);

            $this->assertNull($this->resolver($random)->resolveJobArt(
                $this->actor(24),
                $this->actor(null, false),
                $this->art($template),
                'pve',
            ), $template);
            $this->assertSame(0, $random->calls, $template);
        }
    }

    public function test_hit_resolution_is_independent_of_normalized_sp(): void
    {
        foreach ([false, true] as $normalizedSp) {
            $this->enable();
            config(['battle.job_art_v2.normalized_sp' => $normalizedSp]);

            $result = $this->resolver($this->random([1]))->resolveJobArt(
                $this->actor(24),
                $this->actor(null, false),
                $this->art(),
                'pve',
            );

            $this->assertSame(HitResult::HIT, $result);
        }
    }

    public function test_base_miss_does_not_roll_active_evasion(): void
    {
        $this->enable();
        $random = $this->random([100, 1]);
        $evasion = $this->evasion(100);

        $result = $this->resolver($random, $evasion)->resolveJobArt(
            $this->actor(24),
            $this->actor(null, false),
            $this->art(),
            'pve',
        );

        $this->assertSame(HitResult::MISS, $result);
        $this->assertSame(1, $random->calls);
        $this->assertSame(0, $evasion->calls);
    }

    public function test_active_evasion_is_rolled_only_after_base_hit(): void
    {
        $this->enable();
        $random = $this->random([1, 20]);
        $evasion = $this->evasion(20);

        $result = $this->resolver($random, $evasion)->resolveJobArt(
            $this->actor(53),
            $this->actor(null, false),
            $this->art(),
            'pvp',
        );

        $this->assertSame(HitResult::EVADE, $result);
        $this->assertSame(2, $random->calls);
        $this->assertSame(1, $evasion->calls);
    }

    public function test_base_hit_and_failed_active_evasion_returns_hit(): void
    {
        $this->enable();
        $random = $this->random([1, 21]);
        $evasion = $this->evasion(20);

        $result = $this->resolver($random, $evasion)->resolveJobArt(
            $this->actor(53),
            $this->actor(null, false),
            $this->art(),
            'pvp',
        );

        $this->assertSame(HitResult::HIT, $result);
        $this->assertSame(2, $random->calls);
        $this->assertSame(1, $evasion->calls);
    }

    public function test_sure_hit_skips_only_the_base_miss_roll(): void
    {
        $this->enable();
        $skill = $this->art();
        $skill->setAttribute('sure_hit', true);
        $random = $this->random([1]);

        $result = $this->resolver($random, $this->evasion(100))->resolveJobArt(
            $this->actor(62),
            $this->actor(null, false),
            $skill,
            'champ',
        );

        $this->assertSame(HitResult::EVADE, $result);
        $this->assertSame(1, $random->calls);
    }

    public function test_metadata_free_arts_reuse_each_legacy_job_art_hit_chance(): void
    {
        $this->enable();

        foreach ([
            'pve' => [90, HitResult::HIT, HitResult::MISS],
            'boss' => [90, HitResult::HIT, HitResult::MISS],
            'tower' => [90, HitResult::HIT, HitResult::MISS],
        ] as $context => [$boundary, $atBoundary, $afterBoundary]) {
            foreach ([[$boundary, $atBoundary], [$boundary + 1, $afterBoundary]] as [$roll, $expected]) {
                $result = $this->resolver($this->random([$roll]))->resolveJobArt(
                    $this->actor(85),
                    $this->actor(null, false),
                    $this->art(),
                    $context,
                );
                $this->assertSame($expected, $result, $context . ':' . $roll);
            }
        }

        foreach (['pvp', 'champ', 'arena_npc'] as $context) {
            $random = $this->random([100]);
            $result = $this->resolver($random)->resolveJobArt(
                $this->actor(85),
                $this->actor(null, false),
                $this->art(),
                $context,
            );

            $this->assertSame(HitResult::HIT, $result, $context);
            $this->assertSame(1, $random->calls, $context);
        }
    }

    public function test_metadata_free_pve_contexts_match_the_pure_legacy_chance_for_unequal_speed(): void
    {
        $this->enable();
        $attacker = $this->actor(24);
        $attacker->agi = 106;
        $defender = $this->actor(null, false);
        $legacyChance = (new DamageCalculator())->calculateHitChance($attacker, $defender);

        $this->assertSame(93.0, $legacyChance);
        foreach (['pve', 'boss', 'tower'] as $context) {
            $this->assertSame(HitResult::HIT, $this->resolver($this->random([(int) $legacyChance]))->resolveJobArt(
                $attacker,
                $defender,
                $this->art(),
                $context,
            ), $context . ':hit');
            $this->assertSame(HitResult::MISS, $this->resolver($this->random([(int) $legacyChance + 1]))->resolveJobArt(
                $attacker,
                $defender,
                $this->art(),
                $context,
            ), $context . ':miss');
        }
    }

    public function test_explicit_accuracy_alone_uses_the_formal_context_hit_formula(): void
    {
        $this->enable();

        foreach ([
            'pve' => 98,
            'boss' => 98,
            'tower' => 98,
            'pvp' => 97,
            'champ' => 98,
            'arena_npc' => 97,
        ] as $context => $boundary) {
            $skill = $this->art();
            $skill->setAttribute('accuracy', 110);

            $this->assertSame(HitResult::HIT, $this->resolver($this->random([$boundary]))->resolveJobArt(
                $this->actor(85),
                $this->actor(null, false),
                $skill,
                $context,
            ), $context . ':hit');
            $this->assertSame(HitResult::MISS, $this->resolver($this->random([$boundary + 1]))->resolveJobArt(
                $this->actor(85),
                $this->actor(null, false),
                $skill,
                $context,
            ), $context . ':miss');
        }

        $this->assertStringNotContainsString(
            'COMPATIBILITY_SKILL_ACCURACY',
            file_get_contents(base_path('app/Services/Battle/ActionResolver.php')),
        );
    }

    public function test_legacy_guaranteed_base_hit_path_can_still_be_actively_evaded(): void
    {
        $this->enable();
        $random = $this->random([100, 1]);

        $result = $this->resolver($random, $this->evasion(100))->resolveJobArt(
            $this->actor(24),
            $this->actor(null, false),
            $this->art(),
            'pvp',
        );

        $this->assertSame(HitResult::EVADE, $result);
        $this->assertSame(2, $random->calls);
    }

    public function test_resolution_does_not_mutate_battle_actors_or_skill(): void
    {
        $this->enable();
        $attacker = $this->actor(24);
        $defender = $this->actor(null, false);
        $skill = $this->art('MULTI_HIT');
        $before = [serialize($attacker), serialize($defender), serialize($skill)];

        $result = $this->resolver($this->random([1]))->resolveJobArt($attacker, $defender, $skill, 'pve');

        $this->assertSame(HitResult::HIT, $result);
        $this->assertSame($before, [serialize($attacker), serialize($defender), serialize($skill)]);
    }

    public function test_damage_calculator_legacy_hit_keeps_one_rand_call_and_the_same_result(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(24);
        $defender = $this->actor(null, false);
        mt_srand(20260806);
        $expectedRoll = rand(1, 100);
        $expectedNext = rand(1, 100);

        mt_srand(20260806);
        $actual = $calculator->isHit($attacker, $defender);
        $actualNext = rand(1, 100);

        $this->assertSame($expectedRoll <= 90, $actual);
        $this->assertSame($expectedNext, $actualNext);
    }

    public function test_pure_hit_chance_calculation_consumes_no_randomness_or_state(): void
    {
        $calculator = new DamageCalculator();
        $attacker = $this->actor(24);
        $defender = $this->actor(null, false);
        $before = [serialize($attacker), serialize($defender)];
        mt_srand(20260806);
        $expectedNext = rand(1, 100);

        mt_srand(20260806);
        $chance = $calculator->calculateHitChance($attacker, $defender);
        $actualNext = rand(1, 100);

        $this->assertSame(90.0, $chance);
        $this->assertSame($expectedNext, $actualNext);
        $this->assertSame($before, [serialize($attacker), serialize($defender)]);
    }

    public function test_single_and_multi_hit_average_damage_matches_legacy_for_every_context(): void
    {
        $this->enable();

        foreach ([24, 53, 60, 61, 62, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $context) {
                foreach ([1, 5] as $hits) {
                    $trials = 10_000;
                    $legacyChance = in_array($context, ['pvp', 'champ', 'arena_npc'], true) ? 100 : 90;
                    $legacyRandom = $this->cyclingRandom();
                    $legacyDamage = 0;
                    for ($battle = 0; $battle < $trials; $battle++) {
                        for ($hit = 0; $hit < $hits; $hit++) {
                            if ($legacyRandom->percentRoll() <= $legacyChance) {
                                $legacyDamage += 100;
                            }
                        }
                    }

                    $v2Random = $this->cyclingRandom();
                    $resolver = $this->resolver($v2Random);
                    $v2Damage = 0;
                    for ($battle = 0; $battle < $trials; $battle++) {
                        if ($resolver->resolveJobArt(
                            $this->actor($jobId),
                            $this->actor(null, false),
                            $this->art($hits > 1 ? 'MULTI_HIT' : 'PHYSICAL_DAMAGE'),
                            $context,
                        ) === HitResult::HIT) {
                            $v2Damage += 100 * $hits;
                        }
                    }

                    $this->assertSame($legacyDamage, $v2Damage, "job={$jobId},context={$context},hits={$hits}");
                    $this->assertSame($trials, $v2Random->calls, "job={$jobId},context={$context},hits={$hits}");
                }
            }
        }
    }

    private function enable(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
        ]);
    }

    private function resolver(
        JobArtV2HitRandomSource $random,
        ?JobArtV2ActiveEvasionProvider $evasion = null,
    ): ActionResolver {
        return new ActionResolver(
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            new DamageCalculator(),
            $random,
            $evasion ?? new JobArtV2ActiveEvasionProvider(),
        );
    }

    private function random(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls)
            {
            }

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };
    }

    private function cyclingRandom(): JobArtV2HitRandomSource
    {
        return new class extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function percentRoll(): int
            {
                return ($this->calls++ % 100) + 1;
            }
        };
    }

    private function evasion(float $rate): JobArtV2ActiveEvasionProvider
    {
        return new class($rate) extends JobArtV2ActiveEvasionProvider
        {
            public int $calls = 0;

            public function __construct(private readonly float $rate)
            {
            }

            public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
            {
                $this->calls++;

                return $this->rate;
            }
        };
    }

    private function actor(?int $jobId, bool $isPlayer = true): BattleActor
    {
        return new BattleActor($isPlayer ? 'player' : 'enemy', $isPlayer, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'agi' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(string $template = 'PHYSICAL_DAMAGE', string $skillType = 'job_art'): Skill
    {
        $skill = new Skill([
            'name' => '試作奥義',
            'skill_type' => $skillType,
            'effect_template' => $template,
            'hit_count' => 3,
        ]);
        $skill->setAttribute('id', 9001);

        return $skill;
    }
}
