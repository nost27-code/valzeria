<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\FieldState;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2ProgressionService;
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

    public function test_metadata_free_arts_use_the_new_competitive_base_chance_and_keep_npc_rank_sure_hit(): void
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

        foreach (['pvp', 'champ'] as $context) {
            foreach ([[90, HitResult::HIT], [91, HitResult::MISS]] as [$roll, $expected]) {
                $random = $this->random([$roll]);
                $result = $this->resolver($random)->resolveJobArt(
                    $this->actor(85),
                    $this->actor(null, false),
                    $this->art(),
                    $context,
                );

                $this->assertSame($expected, $result, $context.':'.$roll);
                $this->assertSame(1, $random->calls, $context.':'.$roll);
            }
        }

        foreach (['arena_npc'] as $context) {
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
            'champ' => 97,
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

    public function test_competitive_ordinary_job_arts_are_bounded_between_eighty_four_and_ninety_seven_percent(): void
    {
        $this->enable();

        foreach (['pvp', 'champ'] as $context) {
            $fast = $this->actor(24);
            $fast->agi = $fast->baseAgi = 10_000;
            $slowTarget = $this->actor(null, false);
            $slowTarget->agi = $slowTarget->baseAgi = 0;

            $this->assertSame(HitResult::HIT, $this->resolver($this->random([97]))->resolveJobArt(
                $fast,
                $slowTarget,
                $this->art(),
                $context,
            ), $context.':upper-hit');
            $this->assertSame(HitResult::MISS, $this->resolver($this->random([98]))->resolveJobArt(
                $fast,
                $slowTarget,
                $this->art(),
                $context,
            ), $context.':upper-miss');

            $slow = $this->actor(24);
            $slow->agi = $slow->baseAgi = 0;
            $fastTarget = $this->actor(null, false);
            $fastTarget->agi = $fastTarget->baseAgi = 10_000;

            $this->assertSame(HitResult::HIT, $this->resolver($this->random([84]))->resolveJobArt(
                $slow,
                $fastTarget,
                $this->art(),
                $context,
            ), $context.':lower-hit');
            $this->assertSame(HitResult::MISS, $this->resolver($this->random([85]))->resolveJobArt(
                $slow,
                $fastTarget,
                $this->art(),
                $context,
            ), $context.':lower-miss');
        }
    }

    public function test_precision_shot_turns_accuracy_over_one_hundred_into_capped_vital_hit_chance(): void
    {
        $this->enableAimMetadata();
        $skill = $this->precisionShot();

        $pvpRandom = $this->random([99, 12]);
        $pvp = $this->resolver($pvpRandom)->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'pvp',
        );
        $this->assertSame(HitResult::HIT, $pvp?->hitResult);
        $this->assertSame(105.0, $pvp?->rawHitChance);
        $this->assertSame(99.0, $pvp?->effectiveHitChance);
        $this->assertSame(5.0, $pvp?->accuracyOverflow);
        $this->assertSame(12.0, $pvp?->vitalHitChance, '5 overflow + 10 card bonus is capped at 12% in PvP.');
        $this->assertTrue($pvp?->vitalHit ?? false);
        $this->assertSame(2, $pvpRandom->calls);

        $champRandom = $this->random([99, 15]);
        $champ = $this->resolver($champRandom)->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'champ',
        );
        $this->assertSame(15.0, $champ?->vitalHitChance);
        $this->assertTrue($champ?->vitalHit ?? false);
        $this->assertSame(2, $champRandom->calls);

        $failedVital = $this->resolver($this->random([99, 16]))->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'champ',
        );
        $this->assertFalse($failedVital?->vitalHit ?? true);
    }

    public function test_competitive_aim_art_does_not_roll_vital_hit_after_miss_or_active_evasion(): void
    {
        $this->enableAimMetadata();
        $skill = $this->precisionShot();

        $missRandom = $this->random([100, 1]);
        $miss = $this->resolver($missRandom)->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'pvp',
        );
        $this->assertSame(HitResult::MISS, $miss?->hitResult);
        $this->assertFalse($miss?->vitalHit ?? true);
        $this->assertSame(1, $missRandom->calls);

        $evadeRandom = $this->random([99, 1, 1]);
        $evaded = $this->resolver($evadeRandom, $this->evasion(100))->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'pvp',
        );
        $this->assertSame(HitResult::EVADE, $evaded?->hitResult);
        $this->assertFalse($evaded?->vitalHit ?? true);
        $this->assertSame(2, $evadeRandom->calls);
    }

    public function test_observation_field_stacks_with_precision_shot_and_reaches_the_champ_vital_cap(): void
    {
        $this->enableAimMetadata();
        config(['battle.job_art_v2.fields' => true]);
        $attacker = $this->actor(4);
        $defender = $this->actor(null, false);
        $state = new BattleState($attacker, $defender, 'champ');
        $state->replacePrimaryField(new FieldState('observation', 'player', 3, 1, 1, 1));
        app(JobArtBattleSupportService::class)->beginAction($attacker, $state);
        $random = $this->random([99, 20]);

        $resolution = $this->resolver($random)->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $this->precisionShot(),
            'champ',
            $state,
        );

        $this->assertSame(HitResult::HIT, $resolution?->hitResult);
        $this->assertSame(110.0, $resolution?->rawHitChance);
        $this->assertSame(10.0, $resolution?->accuracyOverflow);
        $this->assertSame(20.0, $resolution?->vitalHitChance);
        $this->assertTrue($resolution?->vitalHit ?? false);
        $this->assertSame(2, $random->calls);
    }

    public function test_next_aim_accuracy_bonus_stacks_once_with_card_accuracy_before_vital_cap(): void
    {
        $this->enableAimMetadata();
        config(['battle.job_art_v2.rank5_v6' => true]);
        $attacker = $this->actor(81);
        $defender = $this->actor(null, false);
        $state = new BattleState($attacker, $defender, 'pvp');
        $attacker->jobArtV2ProgressionState()->rank5V6NextAimAccuracyBonus = 25.0;
        app(JobArtBattleSupportService::class)->beginAction($attacker, $state);
        $skill = $this->precisionShot();
        app(JobArtV2ProgressionService::class)->beginJobArtCast($attacker, $state, $skill);
        $random = $this->random([99, 12]);

        $resolution = $this->resolver($random)->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $skill,
            'pvp',
            $state,
        );

        $this->assertSame(0.0, $attacker->jobArtV2ProgressionState()->rank5V6NextAimAccuracyBonus);
        $this->assertSame(130.0, $resolution?->rawHitChance);
        $this->assertSame(30.0, $resolution?->accuracyOverflow);
        $this->assertSame(12.0, $resolution?->vitalHitChance);
        $this->assertTrue($resolution?->vitalHit ?? false);
    }

    public function test_rank_five_aim_accuracy_metadata_is_limited_to_player_competitive_routes(): void
    {
        $this->enableAimMetadata();
        config(['battle.job_art_v2.rank5_v6' => true]);
        $skill = new Skill([
            'job_id' => 81,
            'learn_rank' => 5,
            'name' => '黒焔魔皇破',
            'skill_type' => 'job_art',
            'effect_template' => 'PHYSICAL_DAMAGE',
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', 9_815);
        $attacker = $this->actor(81);
        $defender = $this->actor(null, false);

        $pvp = $this->resolver($this->random([99, 10]))->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $skill,
            'pvp',
        );
        $this->assertSame(100.0, $pvp?->rawHitChance);
        $this->assertSame(10.0, $pvp?->vitalHitChance);
        $this->assertTrue($pvp?->vitalHit ?? false);

        $npc = $this->resolver($this->random([100]))->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $skill,
            'arena_npc',
        );
        $this->assertSame(HitResult::HIT, $npc?->hitResult);
        $this->assertSame(100.0, $npc?->effectiveHitChance);
        $this->assertSame(0.0, $npc?->vitalHitChance);

        $pve = $this->resolver($this->random([91]))->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $skill,
            'pve',
        );
        $this->assertSame(HitResult::MISS, $pve?->hitResult);
        $this->assertSame(90.0, $pve?->rawHitChance);
    }

    public function test_explicit_sure_hit_never_creates_accuracy_overflow_or_vital_hit(): void
    {
        $this->enableAimMetadata();
        $skill = $this->precisionShot();
        $skill->setAttribute('sure_hit', true);
        $random = $this->random([1]);

        $result = $this->resolver($random)->resolveJobArtWithDetails(
            $this->actor(4),
            $this->actor(null, false),
            $skill,
            'pvp',
        );

        $this->assertSame(HitResult::HIT, $result?->hitResult);
        $this->assertTrue($result?->sureHit ?? false);
        $this->assertNull($result?->rawHitChance);
        $this->assertNull($result?->effectiveHitChance);
        $this->assertSame(0.0, $result?->accuracyOverflow);
        $this->assertSame(0.0, $result?->vitalHitChance);
        $this->assertFalse($result?->vitalHit ?? true);
        $this->assertSame(0, $random->calls);
    }

    public function test_npc_rank_guaranteed_base_hit_path_can_still_be_actively_evaded(): void
    {
        $this->enable();
        $random = $this->random([100, 1]);

        $result = $this->resolver($random, $this->evasion(100))->resolveJobArt(
            $this->actor(24),
            $this->actor(null, false),
            $this->art(),
            'arena_npc',
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

    public function test_single_and_multi_hit_average_damage_matches_each_context_base_chance(): void
    {
        $this->enable();

        foreach ([24, 53, 60, 61, 62, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $context) {
                foreach ([1, 5] as $hits) {
                    $trials = 10_000;
                    $legacyChance = $context === 'arena_npc' ? 100 : 90;
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

    private function enableAimMetadata(): void
    {
        $this->enable();
        config([
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
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

    private function precisionShot(): Skill
    {
        $skill = new Skill([
            'job_id' => 4,
            'learn_rank' => 1,
            'name' => '精密射撃',
            'skill_type' => 'job_art',
            'effect_template' => 'PHYSICAL_DAMAGE',
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', 9401);

        return $skill;
    }
}
