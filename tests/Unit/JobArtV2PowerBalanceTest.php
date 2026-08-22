<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2PowerResolver;
use Tests\Support\JobArtV2BalanceFixture;
use Tests\TestCase;

class JobArtV2PowerBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePrototypePowerChain();
    }

    public function test_ten_lineage_fixture_matches_the_frozen_crown_master_pairs(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('database/data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
        $pairs = [];
        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $rank = (int) ($row['learn_rank'] ?? 0);
            if ($jobId >= 60 && $jobId <= 69 && in_array($rank, [5, 9], true)) {
                $pairs[$jobId][$rank] = $row;
            }
        }

        $this->assertCount(10, JobArtV2BalanceFixture::lineages());
        foreach (JobArtV2BalanceFixture::lineages() as $lineage => $fixture) {
            $jobId = (int) $fixture['job_id'];
            $this->assertSame(285, (int) ($pairs[$jobId][5]['power_hint'] ?? 0), $lineage);
            $this->assertSame($jobId === 67 ? 315 : 355, (int) ($pairs[$jobId][9]['power_hint'] ?? 0), $lineage);
            if (in_array($jobId, [67, 69], true)) {
                $this->assertNull($pairs[$jobId][9]['max_uses_per_battle'] ?? null, $lineage);
            } else {
                $this->assertSame(1, (int) ($pairs[$jobId][9]['max_uses_per_battle'] ?? 0), $lineage);
            }
        }
    }

    public function test_final_workbook_removes_only_the_three_explicit_v2_use_caps(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('database/data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
        $byKey = collect($rows)->keyBy(static fn (array $row): string => (int) $row['job_id'].':'.(int) $row['learn_rank'].':'.(string) $row['name']
        );

        foreach (['59:9:八陣無双策', '67:9:金冠ミダスフィールド', '69:9:王戦アークフォーメーション'] as $key) {
            $this->assertTrue($byKey->has($key), $key);
            $this->assertNull($byKey->get($key)['max_uses_per_battle'] ?? null, $key);
        }
    }

    public function test_only_trusted_scoped_job_arts_receive_the_v2_power(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        $sage = $this->actor(53);
        $sageRankNine = $this->art(53, 9, 320, 5_309, '星天グランドスペル');
        $this->attachAsCurrent($sage, $sageRankNine);
        $this->assertSame(320, $resolver->forExecution($sage, $sageRankNine));

        $counter = $this->actor(60);
        $counterRankNine = $this->art(60, 9, 355, 6_009, '王冠聖剣陣');
        $this->attachAsCurrent($counter, $counterRankNine);
        $this->assertSame(355, $resolver->forExecution($counter, $counterRankNine));

        $lancer = $this->actor(62);
        $lancerRankNine = $this->art(62, 9, 355, 6_209, '竜冠天穿槍');
        $this->attachAsCurrent($lancer, $lancerRankNine);
        $this->assertSame(355, $resolver->forExecution($lancer, $lancerRankNine));

        $eclipse = $this->actor(61);
        $eclipseRankNine = $this->art(61, 9, 355, 6_109, '黒冠アビスブレイク');
        $this->attachAsCurrent($eclipse, $eclipseRankNine);
        $this->assertSame(355, $resolver->forExecution($eclipse, $eclipseRankNine));

        $hunter = $this->actor(64);
        $hunterRankNine = $this->art(64, 9, 355, 6_409, '影冠終葬射');
        $this->attachAsCurrent($hunter, $hunterRankNine);
        $this->assertSame(355, $resolver->forExecution($hunter, $hunterRankNine));

        $shadowStitch = $this->actor(54);
        $shadowStitchRankFive = $this->art(54, 5, 255, 5_405, '影縫い乱舞');
        $this->attachAsCurrent($shadowStitch, $shadowStitchRankFive);
        $this->assertSame(180, $resolver->forExecution($shadowStitch, $shadowStitchRankFive));

        $aim = $this->actor(65);
        $aimRankFive = $this->art(65, 5, 285, 6_505, '鋼冠機砲');
        $this->attachAsCurrent($aim, $aimRankFive);
        $this->assertSame(255, $resolver->forExecution($aim, $aimRankFive));
        $aimRankNine = $this->art(65, 9, 355, 6_509, '鋼冠グラビトンコア');
        $this->attachAsCurrent($aim, $aimRankNine);
        $this->assertSame(355, $resolver->forExecution($aim, $aimRankNine));

        $command = $this->actor(69);
        $commandRankNine = $this->art(69, 9, 355, 6_909, '王戦アークフォーメーション');
        $this->attachAsCurrent($command, $commandRankNine);
        $this->assertSame(355, $resolver->forExecution($command, $commandRankNine));

        $transmute = $this->actor(67);
        $transmuteRankNine = $this->art(67, 9, 315, 6_709, '金冠ミダスフィールド');
        $this->attachAsCurrent($transmute, $transmuteRankNine);
        $this->assertSame(315, $resolver->forExecution($transmute, $transmuteRankNine));

        foreach ([[24, 9, 89], [66, 9, 355], [85, 9, 510], [53, 5, 255], [62, 5, 285]] as [$jobId, $rank, $power]) {
            $actor = $this->actor($jobId);
            $skill = $this->art($jobId, $rank, $power, ($jobId * 100) + $rank);
            $this->attachAsCurrent($actor, $skill);
            $this->assertSame($power, $resolver->forExecution($actor, $skill), "{$jobId}/{$rank}");
        }
    }

    public function test_loadout_display_uses_the_same_current_job_power_as_execution(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        foreach ([
            [53, 9, '星天グランドスペル', 320, 320],
            [54, 5, '影縫い乱舞', 255, 180],
            [60, 9, '王冠聖剣陣', 355, 355],
            [61, 9, '黒冠アビスブレイク', 355, 355],
            [62, 9, '竜冠天穿槍', 355, 355],
            [64, 9, '影冠終葬射', 355, 355],
            [65, 5, '鋼冠機砲', 285, 255],
            [65, 9, '鋼冠グラビトンコア', 355, 355],
            [67, 9, '金冠ミダスフィールド', 315, 315],
            [69, 9, '王戦アークフォーメーション', 355, 355],
        ] as [$jobId, $rank, $name, $masterPower, $effectivePower]) {
            $actor = $this->actor($jobId);
            $skill = $this->art($jobId, $rank, $masterPower, ($jobId * 100) + $rank, $name);
            $this->attachAsCurrent($actor, $skill);
            $skill->setAttribute('job_art_origin', 'current');

            $this->assertSame($effectivePower, $resolver->forExecution($actor, $skill));
            $this->assertSame($effectivePower, $resolver->forDisplay($jobId, $skill));
            $this->assertSame($masterPower, (int) $skill->power);
        }
    }

    public function test_shadow_stitch_rank_five_uses_the_same_l_column_power_for_every_equipped_owner(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);
        $skill = $this->art(54, 5, 255, 5_405, '影縫い乱舞');

        $current = $this->actor(54);
        $this->attachAsCurrent($current, $skill);
        $this->assertSame(180, $resolver->forExecution($current, $skill));

        $sameLineage = $this->actor(64);
        $sameLineage->jobArtOrigins[(int) $skill->id] = 'inherited';
        $this->assertSame(180, $resolver->forExecution($sameLineage, $skill));
        $skill->setAttribute('job_art_origin', 'inherited');
        $this->assertSame(180, $resolver->forDisplay(64, $skill));

        $crossLineage = $this->actor(65);
        $crossLineage->jobArtOrigins[(int) $skill->id] = 'inherited';
        $this->assertSame(180, $resolver->forExecution($crossLineage, $skill));
        $this->assertSame(180, $resolver->forDisplay(65, $skill));

        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame(180, $resolver->forExecution($sameLineage, $skill));
    }

    public function test_transmute_uses_315_while_break_keeps_355_after_effect_aware_calibration(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        foreach ([67 => 'transmute', 68 => 'break'] as $jobId => $lineage) {
            $fixture = JobArtV2BalanceFixture::lineages()[$lineage];
            $actor = $this->actor($jobId);
            $masterPower = $jobId === 67 ? 315 : 355;
            $skill = $this->art(
                $jobId,
                9,
                $masterPower,
                ($jobId * 100) + 9,
                $jobId === 67 ? '金冠ミダスフィールド' : '雷冠天鳴掌',
            );
            $this->attachAsCurrent($actor, $skill);
            $skill->setAttribute('job_art_origin', 'current');

            $expected = $jobId === 67 ? 315 : 355;
            $this->assertSame($expected, $fixture['candidate_rank9_power'], $lineage);
            $this->assertSame($expected, $resolver->forExecution($actor, $skill), $lineage);
            $this->assertSame($expected, $resolver->forDisplay($jobId, $skill), $lineage);
            $this->assertSame($expected, (int) $skill->power, $lineage);
        }
    }

    public function test_loadout_display_keeps_master_power_for_inherited_flag_off_and_unchanged_jobs(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);

        foreach ([[53, 9, 320], [60, 9, 355], [61, 9, 355], [62, 9, 355], [64, 9, 355], [65, 9, 355], [69, 9, 355]] as [$jobId, $rank, $masterPower]) {
            $skill = $this->art($jobId, $rank, $masterPower, ($jobId * 100) + $rank);
            $skill->setAttribute('job_art_origin', 'inherited');
            $this->assertSame($masterPower, $resolver->forDisplay($jobId, $skill));
        }

        foreach ([[24, 9, 89], [85, 9, 510], [53, 5, 255], [62, 5, 285]] as [$jobId, $rank, $masterPower]) {
            $skill = $this->art($jobId, $rank, $masterPower, ($jobId * 100) + $rank);
            $skill->setAttribute('job_art_origin', 'current');
            $this->assertSame($masterPower, $resolver->forDisplay($jobId, $skill), "{$jobId}/{$rank}");
        }

        $sageRankNine = $this->art(53, 9, 320, 5_309);
        $sageRankNine->setAttribute('job_art_origin', 'current');
        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertSame(320, $resolver->forDisplay(53, $sageRankNine));
    }

    public function test_loadout_power_resolution_does_not_consume_randomness(): void
    {
        $skill = $this->art(62, 9, 355, 6_209, '竜冠天穿槍');
        $skill->setAttribute('job_art_origin', 'current');
        mt_srand(19_019);
        $expected = mt_rand();

        mt_srand(19_019);
        $this->assertSame(355, app(JobArtV2PowerResolver::class)->forDisplay(62, $skill));
        $this->assertSame($expected, mt_rand());
    }

    public function test_fail_closed_paths_preserve_master_power(): void
    {
        $resolver = app(JobArtV2PowerResolver::class);
        $skill = $this->art(62, 9, 355, 6_209, '竜冠天穿槍');

        $inherited = $this->actor(62);
        $inherited->jobArtOrigins[(int) $skill->id] = 'inherited';
        $this->assertSame(355, $resolver->forExecution($inherited, $skill));

        $outside = $this->actor(60);
        $outside->jobArtOrigins[(int) $skill->id] = 'current';
        $this->assertSame(355, $resolver->forExecution($outside, $skill));

        $this->attachAsCurrent($inherited, $skill);
        config(['battle.job_art_v2.penetration' => false]);
        $this->assertSame(355, $resolver->forExecution($inherited, $skill));

        config(['battle.job_art_v2.penetration' => true, 'battle.job_art_v2.resources' => false]);
        $this->assertSame(355, $resolver->forExecution($inherited, $skill));

        config(['battle.job_art_v2.resources' => true, 'battle.job_art_v2.dynamic_single' => false]);
        $this->assertSame(355, $resolver->forExecution($inherited, $skill));
    }

    public function test_shared_execution_service_applies_current_override_after_origin_scaling(): void
    {
        $actor = $this->actor(62);
        $skill = $this->art(62, 9, 355, 6_209);
        $this->attachAsCurrent($actor, $skill);

        $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);
        $this->assertSame(355, (int) $execution->power);
        $this->assertSame(3.55, (float) $execution->power_multiplier);
        $this->assertSame(355, (int) $skill->power);

        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = 0.7;
        $legacyExecution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $skill);
        $this->assertSame(248, (int) $legacyExecution->power);
    }

    public function test_rank_sixty_two_candidate_has_the_intended_defense_curve(): void
    {
        $calculator = new DamageCalculator;
        $penetration = app(JobArtV2PenetrationService::class);
        $attacker = $this->actor(62, 1_000, 100);
        $rankFive = $this->art(62, 5, 285, 6_205);
        $rankNine = $this->art(62, 9, 470, 6_209);
        $this->attachAsCurrent($attacker, $rankFive);
        $this->attachAsCurrent($attacker, $rankNine);

        $ratios = [];
        foreach ([333, 800, 2_000, 5_000] as $def) {
            $defender = $this->actor(null, 100, $def, 100_000);
            $rankFiveDef = $penetration->defenseOverrides($attacker, $defender, $rankFive)['def'];
            $rankNineDef = $penetration->defenseOverrides($attacker, $defender, $rankNine)['def'];
            $rankFiveDamage = $this->averagePhysical($calculator, $attacker, $defender, 285, $rankFiveDef, 4_000, 62_500 + $def);
            $rankNineDamage = $this->averagePhysical($calculator, $attacker, $defender, 470, $rankNineDef, 4_000, 62_900 + $def);
            $ratios[$def] = JobArtV2BalanceFixture::activationAdjustedRatio($rankFiveDamage, $rankNineDamage);
        }

        $this->assertGreaterThanOrEqual(0.70, $ratios[333]);
        $this->assertLessThan(0.80, $ratios[333]);
        $this->assertGreaterThanOrEqual(0.75, $ratios[800]);
        $this->assertLessThan(0.85, $ratios[800]);
        $this->assertGreaterThanOrEqual(0.97, $ratios[2_000]);
        $this->assertLessThanOrEqual(1.04, $ratios[2_000]);
        $this->assertGreaterThan(1.15, $ratios[5_000]);
    }

    public function test_rank_fifty_three_uses_the_smallest_five_point_candidate_that_reaches_low_spr_competition(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(53, 1_000, 100);
        $defender = $this->actor(null, 100, 333, 100_000);
        $rankFive = $this->averageMagical($calculator, $attacker, $defender, 255, 4_000, 53_500);
        $currentRankNine = $this->averageMagical($calculator, $attacker, $defender, 320, 4_000, 53_900);
        $previousCandidate = $this->averageMagical($calculator, $attacker, $defender, 405, 4_000, 54_005);
        $acceptedCandidate = $this->averageMagical($calculator, $attacker, $defender, 410, 4_000, 54_010);

        $this->assertLessThan(0.60, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $currentRankNine));
        $this->assertLessThan(0.70, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $previousCandidate));
        $this->assertGreaterThanOrEqual(0.70, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $acceptedCandidate));
        $this->assertLessThan(0.73, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $acceptedCandidate));
    }

    public function test_eclipse_uses_the_smallest_magical_candidate_that_reaches_the_frozen_spr_curve(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(61, 1_000, 100);

        foreach ([333, 800, 2_000, 5_000] as $spr) {
            $defender = $this->actor(null, 100, $spr, 100_000);
            $rankFive = $this->averageMagical($calculator, $attacker, $defender, 285, 20_000, 6_100_000 + $spr);
            $previousCandidate = $this->averageMagical($calculator, $attacker, $defender, 580, 20_000, 6_100_000 + $spr);
            $acceptedCandidate = $this->averageMagical($calculator, $attacker, $defender, 585, 20_000, 6_100_000 + $spr);
            $previousRatio = JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $previousCandidate);
            $acceptedRatio = JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $acceptedCandidate);

            if ($spr === 2_000) {
                $this->assertLessThan(0.90, $previousRatio);
                $this->assertGreaterThanOrEqual(0.90, $acceptedRatio);
            }
            $this->assertGreaterThanOrEqual(0.89, $acceptedRatio, (string) $spr);
            $this->assertLessThanOrEqual(1.03, $acceptedRatio, (string) $spr);
        }
    }

    public function test_hunt_keeps_the_smallest_five_point_physical_candidate(): void
    {
        $calculator = new DamageCalculator;
        $attacker = $this->actor(64, 1_000, 100);
        $defender = $this->actor(null, 100, 800, 100_000);
        $rankFive = $this->averagePhysical($calculator, $attacker, $defender, 285, null, 10_000, 6_400_005);
        $previousCandidate = $this->averagePhysical($calculator, $attacker, $defender, 455, null, 10_000, 6_400_455);
        $acceptedCandidate = $this->averagePhysical($calculator, $attacker, $defender, 460, null, 10_000, 6_400_460);

        $this->assertLessThan(0.70, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $previousCandidate));
        $this->assertGreaterThanOrEqual(0.70, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $acceptedCandidate));
        $this->assertLessThan(0.73, JobArtV2BalanceFixture::activationAdjustedRatio($rankFive, $acceptedCandidate));
    }

    public function test_eclipse_and_hunt_use_their_displayed_power_in_pvp(): void
    {
        $calculator = new DamageCalculator;

        foreach ([61 => ['magical', 585], 64 => ['physical', 460]] as $jobId => [$damageType, $rankNinePower]) {
            $attacker = $this->actor($jobId, 1_000, 500, 10_000);
            $defender = $this->actor(null, 1_000, 800, 10_000);

            foreach ([285, $rankNinePower] as $power) {
                for ($i = 0; $i < 100; $i++) {
                    $seed = ($jobId * 100_000) + ($power * 100) + $i;
                    mt_srand($seed);
                    $normalEquivalent = $calculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        $damageType,
                        100,
                        false,
                    );
                    mt_srand($seed);
                    $actual = $calculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        $damageType,
                        $power,
                        false,
                        isSkill: true,
                    );

                    $this->assertSame(intdiv($normalEquivalent * $power, 100), $actual);
                }
            }
        }
    }

    public function test_rank_sixty_two_pvp_penetration_keeps_displayed_power_authoritative(): void
    {
        $calculator = new DamageCalculator;
        $penetration = app(JobArtV2PenetrationService::class);
        $attacker = $this->actor(62, 1_000, 100);
        $defender = $this->actor(null, 100, 10_000, 10_000);
        $rankFive = $this->art(62, 5, 285, 6_205);
        $rankNine = $this->art(62, 9, 470, 6_209);
        $this->attachAsCurrent($attacker, $rankFive);
        $this->attachAsCurrent($attacker, $rankNine);

        $rankFiveDef = $penetration->defenseOverrides($attacker, $defender, $rankFive)['def'];
        $rankNineDef = $penetration->defenseOverrides($attacker, $defender, $rankNine)['def'];
        foreach ([[285, $rankFiveDef], [470, $rankNineDef]] as [$power, $overrideDef]) {
            for ($i = 0; $i < 100; $i++) {
                $seed = ($power * 1_000) + $i;
                mt_srand($seed);
                $normalEquivalent = $calculator->calculateRankBattleDamage(
                    $attacker,
                    $defender,
                    'physical',
                    100,
                    false,
                    overrideDef: $overrideDef,
                );
                mt_srand($seed);
                $actual = $calculator->calculateRankBattleDamage(
                    $attacker,
                    $defender,
                    'physical',
                    $power,
                    false,
                    overrideDef: $overrideDef,
                    isSkill: true,
                );

                $this->assertSame(intdiv($normalEquivalent * $power, 100), $actual);
            }
        }
    }

    private function averagePhysical(
        DamageCalculator $calculator,
        BattleActor $attacker,
        BattleActor $defender,
        int $power,
        ?int $overrideDef,
        int $iterations,
        int $seed,
    ): float {
        mt_srand($seed);
        $sum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $critical = $calculator->isCritical($attacker, $defender);
            $sum += $calculator->calculatePhysicalDamage($attacker, $defender, $power, $critical, null, $overrideDef);
        }

        return $sum / $iterations;
    }

    private function averageMagical(
        DamageCalculator $calculator,
        BattleActor $attacker,
        BattleActor $defender,
        int $power,
        int $iterations,
        int $seed,
    ): float {
        mt_srand($seed);
        $sum = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $critical = $calculator->isCritical($attacker, $defender);
            $sum += $calculator->calculateMagicalDamage($attacker, $defender, $power, $critical);
        }

        return $sum / $iterations;
    }

    private function attachAsCurrent(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function art(int $jobId, int $rank, int $power, int $id, ?string $name = null): Skill
    {
        $skill = new Skill([
            'name' => $name ?? "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function actor(?int $jobId, int $str = 1_000, int $def = 500, int $hp = 10_000): BattleActor
    {
        return new BattleActor('actor', true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 400,
            'max_mp' => 400,
            'str' => $str,
            'def' => $def,
            'agi' => 100,
            'mag' => $str,
            'spr' => $def,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function enablePrototypePowerChain(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.penetration' => true,
        ]);
    }
}
