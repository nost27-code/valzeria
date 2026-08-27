<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\DamageCalculator;
use App\Services\JobArtV2PenetrationService;
use Tests\TestCase;

class JobArtV2PenetrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.normalized_sp' => false,
            'battle.job_art_v2.fields' => false,
        ]);
    }

    public function test_penetration_is_default_off_and_fails_closed_on_every_dependency(): void
    {
        $config = require base_path('config/battle.php');
        $this->assertFalse($config['job_art_v2']['penetration']);

        $actor = $this->actor(62);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'penetration'] as $disabled) {
            foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'penetration'] as $flag) {
                config(["battle.job_art_v2.{$flag}" => $flag !== $disabled]);
            }

            $this->assertFalse($this->service()->enabledFor($actor), $disabled);
        }

        $this->enableAll();
        $this->assertTrue($this->service()->enabledFor($actor));
        $this->assertFalse($this->service()->enabledFor($this->actor(70)));
        $this->assertFalse(config('battle.job_art_v2.normalized_sp'));
        $this->assertFalse(config('battle.job_art_v2.fields'));
    }

    public function test_only_current_job_sixty_two_rank_five_and_nine_have_trusted_rates(): void
    {
        $actor = $this->actor(62);
        $rankOne = $this->art(1);
        $rankFive = $this->art(5);
        $rankNine = $this->art(9);
        $this->markCurrent($actor, $rankOne, $rankFive, $rankNine);

        $this->assertNull($this->service()->trustedRateFor($actor, $rankOne));
        $this->assertSame(0.35, $this->service()->trustedRateFor($actor, $rankFive));
        $this->assertSame(0.50, $this->service()->trustedRateFor($actor, $rankNine));

        unset($actor->jobArtOrigins[(int) $rankFive->id]);
        $this->assertNull($this->service()->trustedRateFor($actor, $rankFive));
        $actor->jobArtOrigins[(int) $rankFive->id] = 'current';

        $otherJob = $this->actor(53);
        $this->markCurrent($otherJob, $rankFive);
        $this->assertNull($this->service()->trustedRateFor($otherJob, $rankFive));

        $actor->jobArtOrigins[(int) $rankFive->id] = 'inherited';
        $this->assertNull($this->service()->trustedRateFor($actor, $rankFive));

        $notAnArt = $this->art(5);
        $notAnArt->skill_type = 'active';
        $this->markCurrent($actor, $notAnArt);
        $this->assertNull($this->service()->trustedRateFor($actor, $notAnArt));
    }

    public function test_double_pierce_ignores_twenty_five_percent_of_physical_defense_on_both_hits(): void
    {
        $attacker = $this->actor(2);
        $defender = $this->actor(null, 101, 87);
        $skill = new Skill([
            'name' => '二段穿ち',
            'skill_type' => 'job_art',
            'job_id' => 2,
            'learn_rank' => 5,
            'effect_template' => 'MULTI_HIT',
            'damage_type' => 'physical',
            'power' => 145,
            'power_multiplier' => 1.45,
            'hit_count' => 2,
            'def_ignore_percent' => 0,
        ]);
        $skill->setAttribute('id', 2_005);
        $this->markCurrent($attacker, $skill);

        $firstHit = $this->service()->defenseOverrides($attacker, $defender, $skill);
        $secondHit = $this->service()->defenseOverrides($attacker, $defender, $skill);

        $this->assertSame(0.25, $this->service()->trustedRateFor($attacker, $skill));
        $this->assertSame(['def' => 75, 'spr' => null, 'penetration_rate' => 0.25], $firstHit);
        $this->assertSame($firstHit, $secondHit);
        $this->assertSame(101, $defender->def);
    }

    public function test_formula_a_floors_once_reduces_only_def_and_never_mutates_the_actor(): void
    {
        $attacker = $this->actor(62);
        $defender = $this->actor(null, 101, 87);
        $rankFive = $this->art(5);
        $rankNine = $this->art(9);
        $this->markCurrent($attacker, $rankFive, $rankNine);

        $rankFiveOverrides = $this->service()->defenseOverrides($attacker, $defender, $rankFive);
        $rankNineOverrides = $this->service()->defenseOverrides($attacker, $defender, $rankNine);

        $this->assertSame(65, $rankFiveOverrides['def']);
        $this->assertSame(50, $rankNineOverrides['def']);
        $this->assertNull($rankFiveOverrides['spr']);
        $this->assertNull($rankNineOverrides['spr']);
        $this->assertSame(101, $defender->def);
        $this->assertSame(87, $defender->spr);
    }

    public function test_existing_penetration_competes_by_maximum_and_the_combined_rate_is_capped_at_fifty_percent(): void
    {
        $attacker = $this->actor(62);
        $defender = $this->actor(null, 100, 80);

        $rankFive = $this->art(5, 40);
        $this->markCurrent($attacker, $rankFive);
        $overrides = $this->service()->defenseOverrides($attacker, $defender, $rankFive);
        $this->assertSame(60, $overrides['def']);
        $this->assertSame(0.40, $overrides['penetration_rate']);

        $aboveCap = $this->art(5, 80);
        $this->markCurrent($attacker, $aboveCap);
        $overrides = $this->service()->defenseOverrides($attacker, $defender, $aboveCap);
        $this->assertSame(50, $overrides['def']);
        $this->assertSame(0.50, $overrides['penetration_rate']);
    }

    public function test_legacy_def_and_spr_ignore_is_byte_for_byte_preserved_when_the_gate_is_off(): void
    {
        config(['battle.job_art_v2.penetration' => false]);
        $attacker = $this->actor(62);
        $defender = $this->actor(null, 101, 87);
        $skill = $this->art(5, 20);
        $this->markCurrent($attacker, $skill);

        mt_srand(6210);
        $expectedNext = mt_rand();
        mt_srand(6210);
        $overrides = $this->service()->defenseOverrides($attacker, $defender, $skill);

        $this->assertSame(80, $overrides['def']);
        $this->assertSame(69, $overrides['spr']);
        $this->assertNull($overrides['penetration_rate']);
        $this->assertSame($expectedNext, mt_rand());
    }

    public function test_pve_critical_halves_the_already_penetrated_defense_and_keeps_reductions(): void
    {
        $attacker = $this->actor(62, 10, 10, 1000);
        $defender = $this->actor(null, 101, 100);
        $skill = $this->art(5);
        $this->markCurrent($attacker, $skill);
        $override = $this->service()->defenseOverrides($attacker, $defender, $skill)['def'];
        $calculator = new DamageCalculator;

        mt_srand(6255);
        $variance = rand(85, 115) / 100;
        $criticalDef = (int) ($override * 0.5);
        $expected = max(1, (int) (((1000 - ($criticalDef / 2)) * 2.85 * 1.5) * $variance));

        mt_srand(6255);
        $actual = $calculator->calculatePhysicalDamage($attacker, $defender, 285, true, null, $override);
        $this->assertSame($expected, $actual);

        $defender->isDefending = true;
        $defender->damageReductionRate = 20;
        mt_srand(6255);
        $reduced = $calculator->calculatePhysicalDamage($attacker, $defender, 285, true, null, $override);
        $this->assertLessThan($actual, $reduced);
        $this->assertGreaterThanOrEqual((int) floor($actual * 0.39), $reduced);
        $this->assertLessThanOrEqual((int) ceil($actual * 0.41), $reduced);
    }

    public function test_pvp_penetration_uses_physical_defense_without_hp_floors_or_caps(): void
    {
        $attacker = $this->actor(62, 10, 10, 1000, 10_000);
        $rankFive = $this->art(5);
        $rankNine = $this->art(9);
        $this->markCurrent($attacker, $rankFive, $rankNine);
        $calculator = new DamageCalculator;

        $standard = $this->actor(null, 1000, 400, 10, 10_000);
        $r5 = $this->service()->defenseOverrides($attacker, $standard, $rankFive);
        $this->assertSame(650, $r5['def']);

        $highDefense = $this->actor(null, 10_000, 400, 10, 10_000);
        mt_srand(6201);
        $normal = $calculator->calculateRankBattleDamage($attacker, $highDefense, 'physical', 285, false, 1.0, null, null, null, true, 1);
        mt_srand(6201);
        $r5Damage = $calculator->calculateRankBattleDamage($attacker, $highDefense, 'physical', 285, false, 1.0, null, $this->service()->defenseOverrides($attacker, $highDefense, $rankFive)['def'], null, true, 1);
        mt_srand(6201);
        $r9Damage = $calculator->calculateRankBattleDamage($attacker, $highDefense, 'physical', 285, false, 1.0, null, $this->service()->defenseOverrides($attacker, $highDefense, $rankNine)['def'], null, true, 1);
        $this->assertGreaterThan($normal, $r5Damage);
        $this->assertGreaterThan($r5Damage, $r9Damage);

        $lowHpTarget = $this->actor(null, 1, 1, 10, 1000);
        mt_srand(6202);
        $normalDamage = $calculator->calculateRankBattleDamage($attacker, $lowHpTarget, 'physical', 285, false, 1.0, null, null, null, true, 1);
        mt_srand(6202);
        $penetratedDamage = $calculator->calculateRankBattleDamage($attacker, $lowHpTarget, 'physical', 285, false, 1.0, null, 0, null, true, 1);

        $this->assertGreaterThanOrEqual($normalDamage, $penetratedDamage);
        $this->assertNotSame(350, $normalDamage);
    }

    public function test_numeric_characterization_preserves_the_frozen_damage_curve(): void
    {
        $attacker = $this->actor(62, 10, 10, 1000);
        $rankFive = $this->art(5);
        $rankNine = $this->art(9);
        $this->markCurrent($attacker, $rankFive, $rankNine);
        $calculator = new DamageCalculator;
        $rows = [];

        foreach ([3.0, 2.0, 1.0, 0.75, 0.5] as $ratio) {
            $defender = $this->actor(null, (int) round(1000 / $ratio), 100);
            $rows[(string) $ratio] = [
                $this->pveDamage($calculator, $attacker, $defender, null),
                $this->pveDamage($calculator, $attacker, $defender, $this->service()->defenseOverrides($attacker, $defender, $rankFive)['def']),
                $this->pveDamage($calculator, $attacker, $defender, $this->service()->defenseOverrides($attacker, $defender, $rankNine)['def']),
                $this->pveDamage($calculator, $attacker, $defender, 0),
            ];
        }

        foreach ($rows as [$normal, $r5, $r9, $fullIgnore]) {
            $this->assertLessThanOrEqual($r5, $normal);
            $this->assertLessThanOrEqual($r9, $r5);
            $this->assertLessThan($fullIgnore, $r9);
        }

        [$lowNormal, , $lowR9] = $rows['3'];
        [$highNormal, , $highR9] = $rows['0.5'];
        $this->assertLessThan(0.15, ($lowR9 - $lowNormal) / $lowNormal);
        $this->assertGreaterThan(1.0, ($highR9 - $highNormal) / $highNormal);
    }

    public function test_penetration_resolution_consumes_no_randomness(): void
    {
        $attacker = $this->actor(62);
        $defender = $this->actor(null, 999, 777);
        $skill = $this->art(9, 30);
        $this->markCurrent($attacker, $skill);

        mt_srand(6299);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(6299);
        $this->service()->trustedRateFor($attacker, $skill);
        $this->service()->defenseOverrides($attacker, $defender, $skill);
        $actual = [mt_rand(), mt_rand()];

        $this->assertSame($expected, $actual);
    }

    public function test_extreme_magic_ignores_twenty_five_percent_of_spr_only_in_v2(): void
    {
        $attacker = $this->actor(29, 40, 40, 40);
        $defender = $this->actor(null, 120, 101);
        $skill = new Skill([
            'name' => '極大魔法',
            'skill_type' => 'job_art',
            'job_id' => 29,
            'learn_rank' => 9,
            'effect_template' => 'MULTI_HIT',
            'damage_type' => 'physical',
            'power' => 315,
            'power_multiplier' => 3.15,
            'hit_count' => 2,
            'def_ignore_percent' => 0,
        ]);
        $skill->setAttribute('id', 2_909);

        mt_srand(29_025);
        $expectedNext = mt_rand();
        mt_srand(29_025);
        $overrides = $this->service()->defenseOverrides($attacker, $defender, $skill);

        $this->assertNull($overrides['def']);
        $this->assertSame(75, $overrides['spr']);
        $this->assertSame(0.25, $overrides['penetration_rate']);
        $this->assertSame(120, $defender->def);
        $this->assertSame(101, $defender->spr);
        $this->assertSame($expectedNext, mt_rand());

        $calculator = new DamageCalculator;
        mt_srand(29_026);
        $normalDamage = $calculator->calculateRankBattleDamage($attacker, $defender, 'magical');
        mt_srand(29_026);
        $penetratedDamage = $calculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'magical',
            overrideSpr: $overrides['spr'],
        );
        $this->assertGreaterThan($normalDamage, $penetratedDamage);

        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame(
            ['def' => null, 'spr' => null, 'penetration_rate' => null],
            $this->service()->defenseOverrides($attacker, $defender, $skill),
        );
    }

    public function test_magic_cannon_ignores_fifteen_percent_of_spirit_without_changing_defense_or_rng(): void
    {
        $attacker = $this->actor(35, 40, 40, 40);
        $defender = $this->actor(null, 120, 101);
        $skill = new Skill([
            'name' => '魔導砲',
            'skill_type' => 'job_art',
            'job_id' => 35,
            'learn_rank' => 5,
            'effect_template' => 'MAGICAL_DAMAGE',
            'damage_type' => 'magical',
            'power' => 185,
            'power_multiplier' => 1.85,
            'hit_count' => 1,
            'def_ignore_percent' => 0,
        ]);
        $skill->setAttribute('id', 3_505);

        mt_srand(35_015);
        $expectedNext = mt_rand();
        mt_srand(35_015);
        $overrides = $this->service()->defenseOverrides($attacker, $defender, $skill);

        $this->assertNull($overrides['def']);
        $this->assertSame(85, $overrides['spr']);
        $this->assertSame(0.15, $overrides['penetration_rate']);
        $this->assertSame(120, $defender->def);
        $this->assertSame(101, $defender->spr);
        $this->assertSame($expectedNext, mt_rand());

        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame(
            ['def' => null, 'spr' => null, 'penetration_rate' => null],
            $this->service()->defenseOverrides($attacker, $defender, $skill),
        );
    }

    private function enableAll(): void
    {
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'penetration'] as $flag) {
            config(["battle.job_art_v2.{$flag}" => true]);
        }
    }

    private function service(): JobArtV2PenetrationService
    {
        return app(JobArtV2PenetrationService::class);
    }

    private function art(int $rank, int $existingIgnore = 0): Skill
    {
        $skill = new Skill([
            'name' => "job-62-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => 62,
            'learn_rank' => $rank,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => 285,
            'power_multiplier' => 2.85,
            'hit_count' => 1,
            'def_ignore_percent' => $existingIgnore,
        ]);
        $skill->setAttribute('id', 6200 + $rank + $existingIgnore);

        return $skill;
    }

    private function actor(?int $jobId, int $def = 100, int $spr = 100, int $str = 100, int $hp = 100_000): BattleActor
    {
        return new BattleActor('actor', true, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 400,
            'max_mp' => 400,
            'str' => $str,
            'def' => $def,
            'agi' => 100,
            'mag' => 100,
            'spr' => $spr,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function markCurrent(BattleActor $actor, Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
        }
    }

    private function pveDamage(DamageCalculator $calculator, BattleActor $attacker, BattleActor $defender, ?int $overrideDef): int
    {
        mt_srand(6211);

        return $calculator->calculatePhysicalDamage($attacker, $defender, 285, false, null, $overrideDef);
    }
}
