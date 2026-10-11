<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\Battle\RelicDamageTrace;
use App\Services\BattleService;
use App\Services\NamelessRelicBattleService;
use App\Services\PvPBattleService;
use ReflectionMethod;
use Tests\TestCase;

final class NamelessRelicDamageLogTest extends TestCase
{
    public function test_combined_trace_retains_offense_armor_caps_and_rounding(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->weaponKillerEffects = [['species_key' => 'dragon', 'damage_rate' => .4]];
        $a->speciesKeys = ['dragon'];
        $a->namelessRelicEffects = ['opener' => .26, 'finisher' => .2, 'blood_pact' => .25];
        $b->speciesKeys = ['dragon'];
        $b->armorResistSpeciesKey = 'dragon';
        $b->armorSpeciesDamageReductionRate = .2;
        $b->namelessResistEffects = [['species_key' => 'dragon', 'damage_rate' => .2]];
        $b->namelessRelicEffects = ['brand_guard' => .5, 'first_guard' => .2, 'last_stand' => .25, 'mirror_guard' => .12];
        $b->hp = 200;
        $relics = app(NamelessRelicBattleService::class);
        $trace = new RelicDamageTrace(1000);
        $speciesDamage = $relics->speciesDamage(1000, $a, $b, false, $trace);
        $this->assertSame(780, $speciesDamage);
        $this->assertSame(1120, $trace->damageWithoutMitigation);
        $this->assertSame(778, $relics->directDamage($speciesDamage, $a, $b, $trace));
        $this->assertSame(1338, $trace->mitigatedDamage);
        $relics->logMitigation($b, $state, $trace);
        $this->assertStringContainsString('1,338軽減', implode('', $state->logs));
    }

    public function test_pvp_species_scale_is_used_for_only_the_relic_contribution(): void
    {
        [$a, $b, $state] = $this->battle('pvp');
        $a->speciesKeys = $b->speciesKeys = ['dragon'];
        $a->weaponKillerEffects = [['species_key' => 'dragon', 'damage_rate' => .4]];
        $b->armorResistSpeciesKey = 'dragon';
        $b->armorSpeciesDamageReductionRate = .2;
        $b->namelessResistEffects = [['species_key' => 'dragon', 'damage_rate' => .2]];
        $b->namelessRelicEffects = ['brand_guard' => .5];
        $service = app(RelicLogPvpHarness::class);
        $result = $service->hit($a, $b, $state, 1000);
        $this->assertSame(805, $result->requestedDamage);
        $this->assertSame(195, $b->hp);
        $this->assertStringContainsString('300軽減', implode('', $state->logs));
    }

    public function test_armor_already_at_cap_and_minimum_damage_do_not_claim_a_reduction(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->speciesKeys = ['dragon'];
        $b->armorResistSpeciesKey = 'dragon';
        $b->armorSpeciesDamageReductionRate = .4;
        $b->namelessResistEffects = [['species_key' => 'dragon', 'damage_rate' => .2]];
        $service = app(RelicLogPveHarness::class);
        $this->assertSame(65, $service->hit($a, $b, $state, 100)->requestedDamage);
        $this->assertSame([], $state->logs);
        $b->namelessRelicEffects = ['first_guard' => .28, 'mirror_guard' => .12];
        $this->assertSame(1, $service->hit($a, $b, $state, 1)->requestedDamage);
        $this->assertSame([], $state->logs);
    }

    public function test_support_incense_is_retained_in_the_display_comparison(): void
    {
        [$a, $b, $state] = $this->battle();
        $state->explorationSupportSnapshot = ['item_key' => 'support_guard_incense'];
        $b->namelessRelicEffects = ['first_guard' => .2];
        $result = app(RelicLogPveHarness::class)->hit($a, $b, $state, 100);
        $this->assertSame(73, $result->requestedDamage);
        $this->assertStringContainsString('19軽減', implode('', $state->logs));
    }

    public function test_each_hit_logs_once_and_counter_does_not_retrigger_or_chain(): void
    {
        [$a, $b, $state] = $this->battle();
        $state->beginSourceAction();
        $b->namelessRelicEffects = ['first_guard' => .2, 'counter' => 1];
        $a->namelessRelicEffects = ['counter' => 1];
        $service = app(RelicLogPveHarness::class);
        for ($hit = 1; $hit <= 2; $hit++) {
            $service->hit($a, $b, $state, 100);
            $state->addDamageLog('本体'.$hit.'撃目');
        }
        $this->assertSame(980, $a->hp);
        $this->assertSame(840, $b->hp);
        $this->assertCount(2, array_filter($state->logs, fn ($log) => str_contains($log, '20軽減')));
        $this->assertCount(1, array_filter($state->logs, fn ($log) => str_contains($log, '【返刃】')));
        $this->assertSame('本体1撃目', $state->logs[0]);
        $this->assertStringContainsString('20ダメージ', $state->logs[2]);
        $this->assertSame([], $state->pullDeferredDamageLogs());
    }

    public function test_lethal_counter_displays_actual_hp_loss_and_prevents_mirror(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->hp = 10;
        $b->namelessRelicEffects = ['counter' => 1, 'mirror_guard' => .12];
        app(RelicLogPveHarness::class)->hit($a, $b, $state, 100);
        $this->assertSame(0, $a->hp);
        $this->assertStringContainsString('10ダメージ', implode('', $state->logs));
        $this->assertStringNotContainsString('【鏡守】', implode('', $state->logs));
    }

    public function test_mirror_and_counter_display_separate_actual_hp_losses(): void
    {
        [$a, $b, $state] = $this->battle();
        $b->namelessRelicEffects = ['counter' => 1, 'mirror_guard' => .12];
        app(RelicLogPveHarness::class)->hit($a, $b, $state, 200);
        $this->assertSame(950, $a->hp);
        $this->assertStringContainsString('44ダメージ', implode('', $state->logs));
        $this->assertStringContainsString('6ダメージ', implode('', $state->logs));
    }

    public function test_survival_can_make_actual_counter_hp_loss_zero(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->hp = 10;
        $a->namelessRelicEffects = ['survive' => .08];
        $b->namelessRelicEffects = ['counter' => 1];
        app(RelicLogPveHarness::class)->hit($a, $b, $state, 100);
        $this->assertSame(80, $a->hp);
        $this->assertTrue($a->namelessSurvivalUsed);
        $this->assertStringContainsString('0ダメージ', implode('', $state->logs));
    }

    public function test_pvp_lethal_mirror_displays_remaining_hp_and_keeps_its_type(): void
    {
        [$a, $b, $state] = $this->battle('pvp');
        $a->hp = 2;
        $b->namelessRelicEffects = ['mirror_guard' => .12];
        app(RelicLogPvpHarness::class)->hit($a, $b, $state, 200);
        $this->assertSame(0, $a->hp);
        $this->assertStringContainsString('【鏡守】', implode('', $state->logs));
        $this->assertStringContainsString('反射し', implode('', $state->logs));
        $this->assertStringContainsString('2ダメージ', implode('', $state->logs));
    }

    public function test_miss_and_dot_do_not_report_direct_relic_mitigation_or_responses(): void
    {
        [$a, $b, $state] = $this->battle();
        $b->namelessRelicEffects = ['first_guard' => .2, 'counter' => 1, 'mirror_guard' => .12];
        app(RelicLogPveHarness::class)->damageOnly($a, $b, $state, 100, DamageSourceType::DOT);
        $this->assertSame(900, $b->hp);
        $result = new DamageApplicationResult(0, 900, 900, 0, 0, false, DamageSourceType::NORMAL_ATTACK, null, HitResult::MISS, 1, 1);
        app(NamelessRelicBattleService::class)->completeDirectHit($a, $b, $state, $result,
            fn () => $this->fail('Miss must not apply response'), fn () => 0, fn () => $this->fail('Miss must not roll'));
        $this->assertSame([], $state->logs);
    }

    public function test_off_has_no_new_logs_and_names_are_escaped(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicsEnabled = $b->namelessRelicsEnabled = false;
        app(RelicLogPveHarness::class)->hit($a, $b, $state, 100);
        $this->assertSame([], $state->logs);
        $b->namelessRelicsEnabled = true;
        $b->name = '<script>冒険者</script>';
        $b->namelessRelicEffects = ['first_guard' => .2, 'counter' => 1];
        $a->namelessActionSerial++;
        app(RelicLogPveHarness::class)->hit($a, $b, $state, 100);
        $this->assertStringContainsString('&lt;script&gt;', implode('', $state->logs));
        $this->assertStringNotContainsString('<script>', implode('', $state->logs));
    }

    private function battle(string $type = 'pve'): array
    {
        $stats = ['hp' => 1000, 'max_hp' => 1000, 'mp' => 100, 'max_mp' => 100, 'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100];
        $a = new BattleActor('攻撃側', false, $stats);
        $b = new BattleActor('防御側', true, $stats);
        $a->namelessRelicsEnabled = $b->namelessRelicsEnabled = true;
        $a->namelessActionSerial = 1;

        return [$a, $b, new BattleState($b, $a, $type)];
    }
}

class RelicLogPveHarness extends BattleService
{
    public function hit(BattleActor $a, BattleActor $b, BattleState $state, int $amount): ?DamageApplicationResult
    {
        $amount = (new ReflectionMethod(BattleService::class, 'applyPveKillerDamage'))->invoke($this, $amount, $a, $b, $state);

        return $this->applyResolvedDamage($a, $b, $state, $amount, DamageSourceType::NORMAL_ATTACK, hitResult: HitResult::HIT, isDirect: true, damageCategory: 'physical');
    }

    public function damageOnly(BattleActor $a, BattleActor $b, BattleState $state, int $amount, DamageSourceType $source): ?DamageApplicationResult
    {
        return $this->applyResolvedDamage($a, $b, $state, $amount, $source);
    }
}

class RelicLogPvpHarness extends PvPBattleService
{
    public function hit(BattleActor $a, BattleActor $b, BattleState $state, int $amount): ?DamageApplicationResult
    {
        return $this->applyResolvedDamage($a, $b, $state, $amount, DamageSourceType::NORMAL_ATTACK, hitResult: HitResult::HIT, isDirect: true, damageCategory: 'physical');
    }
}
