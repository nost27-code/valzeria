<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicCatalog;
use App\Services\NamelessRuinService;
use Tests\TestCase;

class NamelessRelicBattleTest extends TestCase
{
    public function test_every_effect_and_rank_is_reachable_with_valid_ticket_boundaries(): void
    {
        $catalog = app(NamelessRelicCatalog::class);
        $ruins = app(NamelessRuinService::class);
        $this->assertCount(87, $catalog->all());
        $available = collect($ruins->zones())->flatMap(fn ($zone) => $zone['effects'])->all();
        $this->assertEqualsCanonicalizing(array_keys($catalog->all()), $available);
        $this->assertSame(30, collect($ruins->zones())->sum(fn ($zone) => count($zone['enemies'])));
        foreach ([1, 31, 32, 50, 75, 100] as $depth) {
            $previous = PHP_INT_MAX;
            $boundary = 0;
            foreach ($ruins->rankWeights($depth) as $rank => $weight) {
                $this->assertGreaterThan(0, $weight);
                if ($depth <= 31) {
                    $this->assertLessThan($previous, $weight);
                }
                $this->assertSame($rank, $ruins->rankForTicket($depth, $boundary + 1));
                $boundary += $weight;
                $this->assertSame($rank, $ruins->rankForTicket($depth, $boundary));
                $previous = $weight;
            }
        }
        foreach ($catalog->all() as $effect) {
            $previous = 0;
            for ($rank = 1; $rank <= 9; $rank++) {
                $this->assertGreaterThan($previous, $catalog->value($effect['key'], $rank));
                $this->assertNotSame('', $catalog->summary($effect['key'], $rank));
                $previous = $catalog->value($effect['key'], $rank);
            }
        }
    }

    public function test_opening_attack_and_guard_cover_one_multihit_action_without_retriggering(): void
    {
        $attacker = $this->actor();
        $defender = $this->actor();
        $attacker->namelessRelicEffects = ['opener' => .25];
        $defender->namelessRelicEffects = ['first_guard' => .20];
        $service = app(NamelessRelicBattleService::class);
        $attacker->namelessActionSerial = 1;
        $this->assertSame(0, $service->directDamage(0, $attacker, $defender));
        $this->assertNull($attacker->namelessOpenerAction);
        $this->assertNull($defender->namelessGuardAction);
        $attacker->namelessActionSerial++;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
        $attacker->namelessActionSerial++;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
        $this->assertSame(2, $attacker->namelessOpenerAction);
        $this->assertSame(spl_object_id($attacker).':2', $defender->namelessGuardAction);
    }

    public function test_opening_and_guard_individually_change_only_the_first_damaging_action(): void
    {
        $attacker = $this->actor();
        $defender = $this->actor();
        $service = app(NamelessRelicBattleService::class);
        $attacker->namelessRelicEffects = ['opener' => .25];
        $attacker->namelessActionSerial = 1;
        $this->assertSame(125, $service->directDamage(100, $attacker, $defender));
        $attacker->namelessActionSerial++;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
        $defender->namelessRelicEffects = ['first_guard' => .20];
        $this->assertSame(80, $service->directDamage(100, $attacker, $defender));
        $attacker->namelessActionSerial++;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
    }

    public function test_low_hp_threshold_is_inclusive_and_uses_the_correct_actor(): void
    {
        $attacker = $this->actor();
        $defender = $this->actor();
        $service = app(NamelessRelicBattleService::class);
        $attacker->namelessRelicEffects = ['finisher' => .20];
        $defender->namelessRelicEffects = ['last_stand' => .25];
        $defender->hp = 301;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
        $defender->hp = 300;
        $this->assertSame(90, $service->directDamage(100, $attacker, $defender));
        $defender->hp = 1000;
        $attacker->hp = 100;
        $this->assertSame(100, $service->directDamage(100, $attacker, $defender));
    }

    public function test_recovery_uses_maximum_and_caps_current_hp_sp_without_reviving(): void
    {
        $actor = $this->actor();
        $actor->namelessRelicEffects = ['victory_hp' => .02, 'victory_sp' => .02];
        $actor->hp = 995;
        $actor->mp = 970;
        $state = new BattleState($actor, $this->actor(), 'pve');
        app(NamelessRelicBattleService::class)->recoverAfterVictory($actor, $state);
        $this->assertSame(1000, $actor->hp);
        $this->assertSame(990, $actor->mp);
        $this->assertCount(2, $state->logs);
        $actor->hp = 0;
        app(NamelessRelicBattleService::class)->recoverAfterVictory($actor, $state);
        $this->assertSame(0, $actor->hp);
    }

    public function test_critical_preserves_existing_cap_and_thrift_preserves_zero_and_minimum_cost(): void
    {
        $attacker = $this->actor();
        $defender = $this->actor();
        $attacker->namelessRelicEffects = ['critical' => 10];
        $calculator = app(DamageCalculator::class);
        $this->assertSame(15.0, $calculator->criticalChance($attacker, $defender));
        $this->assertSame(30.0, $calculator->criticalChance($attacker, $defender, 100));
        $service = app(NamelessRelicBattleService::class);
        $this->assertSame(82, $service->discountFixedSp(100, .18));
        $this->assertSame(1, $service->discountFixedSp(1, .18));
        $this->assertSame(0, $service->discountFixedSp(0, .18));
        $this->assertSame(100, $service->discountFixedSp(100, 0));
    }

    public function test_thrift_reaches_actual_sp_cost_without_discounting_variable_cost_or_power(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.enabled' => true, 'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true, 'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true, 'battle.job_art_v2.rank5_v6' => true]);
        $actor = $this->actor();
        $actor->currentJobId = 60;
        $actor->spScalingEligible = true;
        $actor->spPowerReference = 10000;
        $actor->jobArtStrategy = ['sp_output' => 'standard'];
        $skill = new Skill(['job_id' => 60, 'skill_type' => 'job_art', 'learn_rank' => 1, 'power' => 100, 'effect_template' => 'PHYSICAL_DAMAGE']);
        $skill->setAttribute('id', 1001);
        $calculator = app(JobArtV2SpCostCalculator::class);
        $before = $calculator->scalingForActor($actor, $skill);
        $actor->namelessRelicEffects = ['thrift' => .18];
        $after = $calculator->scalingForActor($actor, $skill);
        $this->assertSame(23, $before->fixedCost);
        $this->assertGreaterThan(0, $before->variableCost);
        $this->assertSame(19, $after->discountedFixedCost);
        $this->assertSame($before->variableCost, $after->variableCost);
        $this->assertSame($before->totalCost - 4, $after->totalCost);
        $this->assertSame($before->bonusBps, $after->bonusBps);
    }

    private function actor(): BattleActor
    {
        return new BattleActor('遺物検証', true, ['hp' => 1000, 'max_hp' => 1000, 'mp' => 1000, 'max_mp' => 1000, 'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100]);
    }
}
