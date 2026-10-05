<?php

namespace Tests\Unit;

use App\Models\PlayerRelic;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\RoomRules\BurningLifePvPRoomRule;
use App\Services\CharacterStatusService;
use App\Services\JobArtBattleSupportService;
use App\Services\NamelessRelicBattleService;
use App\Services\NamelessRelicCatalog;
use App\Services\PvPBattleService;
use Tests\TestCase;

final class NamelessRelicExpansionTest extends TestCase
{
    public function test_percentages_use_one_baseline_and_positive_cap_does_not_erase_trade_cost(): void
    {
        $catalog = app(NamelessRelicCatalog::class);
        $relics = collect(['stat_agi', 'compound_dance', 'trade_haste', 'form_machine'])->map(fn ($key) => new PlayerRelic(['effect_key' => $key, 'rank' => 9]));
        $rates = $catalog->aggregateStatRates($relics);
        $stats = $catalog->applyStatRates(['agi' => 20000, 'def' => 20000, 'max_hp' => 20000], $rates);
        $this->assertEqualsWithDelta(.20, $rates['agi'], .000001);
        $this->assertSame(24000, $stats['agi']);
        $this->assertSame(21000, $stats['def']);
        $this->assertSame(23000, $catalog->applyStatRates(['str' => 20000], ['str' => .15])['str']);
        $this->assertSame(20000, $stats['max_hp']);
    }

    public function test_brand_does_not_change_native_job_art_species_and_cleanse_preserves_form(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessBrand = ['species' => 'dragon', 'potency' => .8];
        $b->speciesKeys = ['spirit'];
        $b->namelessRelicEffects['cleanse'] = .05;
        $service = app(NamelessRelicBattleService::class);
        $service->startBattle($a, $b, $state);
        $this->assertSame(['spirit'], $b->speciesKeys);
        $this->assertSame('dragon', $b->namelessForeignSpecies);
        $service->beginAction($b, $state);
        $this->assertNull($b->namelessForeignSpecies);
        $this->assertSame(['spirit'], $b->speciesKeys);
        $service->beginAction($a, $state);
        $this->assertNull($b->namelessForeignSpecies);
    }

    public function test_species_damage_uses_max_matching_channel_and_counter_reduces_only_bonus(): void
    {
        [$a, $b] = $this->battle();
        $service = app(NamelessRelicBattleService::class);
        $a->weaponKillerEffects = [['species_key' => 'dragon', 'damage_rate' => .28], ['species_key' => 'beast', 'damage_rate' => .28]];
        $b->namelessForeignSpecies = 'dragon';
        $b->namelessForeignPotency = .8;
        $this->assertSame(1168, $service->speciesDamage(1000, $a, $b, true));
        $b->namelessRelicEffects['brand_guard'] = .60;
        $this->assertSame(1067, $service->speciesDamage(1000, $a, $b, true));
        $b->speciesKeys = ['dragon', 'beast'];
        $this->assertEqualsWithDelta(.084, $service->killerRate($a, $b, true), .000001);
        $b->namelessForeignSpecies = null;
        $b->speciesKeys = [];
        $this->assertSame(1000, $service->speciesDamage(1000, $a, $b, true));
    }

    public function test_resistance_sums_same_species_but_not_different_species_and_is_symmetric(): void
    {
        [$a, $b] = $this->battle();
        $a->isPlayer = false;
        $b->isPlayer = true;
        $a->speciesKeys = ['dragon', 'spirit'];
        $b->armorResistSpeciesKey = 'dragon';
        $b->armorSpeciesDamageReductionRate = .25;
        $b->namelessResistEffects = [['species_key' => 'dragon', 'damage_rate' => .2], ['species_key' => 'spirit', 'damage_rate' => .2]];
        $service = app(NamelessRelicBattleService::class);
        $this->assertEqualsWithDelta(.3375, $service->resistanceRate($a, $b, true), .000001);
        $this->assertSame(662, $service->speciesDamage(1000, $a, $b, true));
        $this->assertSame(650, $service->speciesDamage(1000, $a, $b, false));
    }

    public function test_pve_existing_dual_species_killers_remain_additive_and_brand_cannot_stack_on_them(): void
    {
        [$a, $b] = $this->battle();
        $a->weaponKillerEffects = [['species_key' => 'dragon', 'damage_rate' => .2], ['species_key' => 'beast', 'damage_rate' => .2]];
        $b->speciesKeys = ['dragon', 'beast'];
        $b->namelessForeignSpecies = 'dragon';
        $b->namelessForeignPotency = .8;
        $service = app(NamelessRelicBattleService::class);
        $this->assertSame(1400, $service->speciesDamage(1000, $a, $b, false));
        $this->assertSame(1150, $service->speciesDamage(1000, $a, $b, true));
    }

    public function test_normal_procs_use_actual_hp_loss_once_and_do_not_add_action_or_chain(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicEffects = ['drain' => .12, 'echo_sp' => .012, 'chase' => .25];
        $a->hp = 500;
        $a->mp = 500;
        $a->namelessActionSerial = 1;
        $b->namelessRelicEffects = ['counter' => .25, 'mirror_guard' => .12];
        $service = app(NamelessRelicBattleService::class);
        $service->directDamage(1000, $a, $b);
        $result = new DamageApplicationResult(1000, 1000, 800, 200, 0, false, DamageSourceType::NORMAL_ATTACK, null, HitResult::HIT, 1, 5);
        $reactions = [];
        $damage = function ($from, $to, $amount, $type) use (&$reactions) {
            $reactions[] = [$amount, $type];
        };
        for ($i = 0; $i < 5; $i++) {
            $service->completeDirectHit($a, $b, $state, $result, $damage, fn ($actor, $amount) => $actor->healHp($amount), fn () => 1);
        }
        $this->assertSame(524, $a->hp);
        $this->assertSame(512, $a->mp);
        $this->assertSame([[60, DamageSourceType::OTHER], [50, DamageSourceType::COUNTER], [30, DamageSourceType::REFLECT]], $reactions);
        $this->assertSame(1, $a->namelessActionSerial);
        $this->assertSame(0, $b->namelessActionSerial);
    }

    public function test_zero_actual_hp_loss_cannot_generate_drain_or_chase_but_can_restore_sp(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->hp = 500;
        $a->mp = 500;
        $a->namelessRelicEffects = ['drain' => .12, 'echo_sp' => .012, 'chase' => 1];
        $result = new DamageApplicationResult(1000, 1000, 1000, 0, 0, false, DamageSourceType::NORMAL_ATTACK, null, HitResult::HIT, 1, 1);
        app(NamelessRelicBattleService::class)->completeDirectHit($a, $b, $state, $result, fn () => $this->fail('Shield cannot be drained or chased'), fn () => $this->fail('No HP loss'), fn () => 1);
        $this->assertSame(500, $a->hp);
        $this->assertSame(512, $a->mp);
    }

    public function test_empty_loadout_does_not_add_random_rolls_and_enemy_cannot_revive_after_relic_reaction(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicsEnabled = true;
        $service = app(NamelessRelicBattleService::class);
        $service->startBattle($a, $b, $state);
        $result = new DamageApplicationResult(200, 1000, 800, 200, 0, false, DamageSourceType::NORMAL_ATTACK, null, HitResult::HIT, 1, 1);
        $service->completeDirectHit($a, $b, $state, $result, fn () => $this->fail('No relic reaction'), fn () => $this->fail('No relic healing'), fn () => $this->fail('No extra RNG'));
        $b->takeDamage(2000);
        $this->assertSame(0, $b->healHp(1000));
        $this->assertSame(0, $b->hp);
    }

    public function test_survival_is_shared_with_guts_and_burning_life_self_damage_still_kills(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicEffects['survive'] = .08;
        $a->takeDamage(2000);
        $this->assertSame(80, $a->hp);
        $a->takeDamage(2000);
        $this->assertSame(0, $a->hp);
        $b->namelessRelicEffects['survive'] = .08;
        $b->gutsReady = true;
        $b->takeDamage(2000);
        $this->assertSame(1, $b->hp);
        $b->takeDamage(2000);
        $this->assertSame(0, $b->hp);
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicEffects['survive'] = .08;
        $a->hp = 10;
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($a, $b, $state);
        $rule->onActionEnd($a, $b, $state);
        $this->assertSame(0, $a->hp);
        $this->assertFalse($a->namelessSurvivalUsed);
    }

    public function test_blood_pact_blocks_battle_hp_healing_but_not_pve_victory_recovery(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->hp = 500;
        $a->namelessRelicEffects = ['blood_pact' => .25, 'victory_hp' => .02];
        $this->assertSame(0, $a->healHp(200));
        $service = app(NamelessRelicBattleService::class);
        $this->assertSame(125, $service->directDamage(100, $a, $b));
        $service->recoverAfterVictory($a, $state);
        $this->assertSame(520, $a->hp);
    }

    public function test_lethal_counter_stops_remaining_hits_and_post_skill_healing(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicsEnabled = $b->namelessRelicsEnabled = true;
        $a->hp = 1;
        $a->str = 10000;
        $b->hp = $b->maxHp = 100000;
        $b->namelessRelicEffects['counter'] = 1;
        $service = new NamelessRelicPvPHarness(app(CharacterStatusService::class), app(DamageCalculator::class), app(JobArtBattleSupportService::class));
        $service->skill($a, $b, $state, new Skill(['id' => 999999, 'name' => '検証技', 'damage_type' => 'physical', 'power_multiplier' => 1, 'hit_count' => 2, 'heal_percent' => 100]));
        $this->assertSame(0, $a->hp);
        $this->assertSame(0, $a->healHp(1000));
        $this->assertCount(1, array_filter($state->logs, fn ($line) => str_contains($line, 'B に <span')));
        $this->assertSame(0, $a->totalHpHealed);
    }

    private function battle(): array
    {
        $a = new BattleActor('A', true, ['hp' => 1000, 'max_hp' => 1000, 'mp' => 1000, 'max_mp' => 1000, 'str' => 100, 'mag' => 100, 'def' => 100, 'spr' => 100, 'agi' => 100, 'luk' => 100]);
        $b = clone $a;
        $b->name = 'B';

        return [$a, $b, new BattleState($a, $b, 'pvp')];
    }

    public function test_relic_penetration_respects_existing_ignore_and_speed_cap_in_both_categories(): void
    {
        [$a, $b] = $this->battle();
        $a->str = $a->mag = 20000;
        $b->def = $b->spr = 20000;
        $calculator = app(DamageCalculator::class);
        foreach (['physical', 'magical'] as $category) {
            $a->namelessRelicEffects = [];
            mt_srand(20261004);
            $baseline = $calculator->calculateRankBattleDamage($a, $b, $category);
            $a->namelessRelicEffects['pierce_'.$category] = .20;
            mt_srand(20261004);
            $piercing = $calculator->calculateRankBattleDamage($a, $b, $category);
            $this->assertGreaterThan($baseline, $piercing);
            // 技が30%無視し、敏捷突破が残りを30%無視: 合計51%。既存分を戻さず遺物は追加しない。
            mt_srand(20261004);
            $withRelic = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 14000, overrideSpr: 14000, additionalDefenseIgnoreRate: .30);
            $a->namelessRelicEffects = [];
            mt_srand(20261004);
            $withoutRelic = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 14000, overrideSpr: 14000, additionalDefenseIgnoreRate: .30);
            $this->assertSame($withoutRelic, $withRelic);
            // 既存30%+残りへの20%=44%、50%上限より低い。
            $a->namelessRelicEffects['pierce_'.$category] = .20;
            mt_srand(20261004);
            $composed = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 14000, overrideSpr: 14000);
            $a->namelessRelicEffects = [];
            mt_srand(20261004);
            $expected = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 11200, overrideSpr: 11200);
            $this->assertSame($expected, $composed);
            // 灼命などで守りが増えていても、技の50%貫通を60%へ増やさない。
            $a->namelessExistingIgnoreRate = .50;
            $a->namelessRelicEffects['pierce_'.$category] = .20;
            mt_srand(20261004);
            $withRelic = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 14000, overrideSpr: 14000);
            $a->namelessRelicEffects = [];
            mt_srand(20261004);
            $withoutRelic = $calculator->calculateRankBattleDamage($a, $b, $category, overrideDef: 14000, overrideSpr: 14000);
            $this->assertSame($withoutRelic, $withRelic);
            $a->namelessExistingIgnoreRate = null;
        }
    }

    public function test_pvp_drain_uses_burning_life_healing_rule_and_reaction_does_not_end_an_action(): void
    {
        [$a, $b, $state] = $this->battle();
        $a->namelessRelicsEnabled = $b->namelessRelicsEnabled = true;
        $a->namelessRelicEffects = ['drain' => .12, 'chase' => 1];
        $a->hp = 500;
        $a->namelessActionSerial = 1;
        $b->namelessRelicEffects = ['counter' => 1];
        $service = new NamelessRelicPvPHarness(app(CharacterStatusService::class), app(DamageCalculator::class), app(JobArtBattleSupportService::class));
        $rule = new BurningLifePvPRoomRule;
        $rule->onBattleStart($a, $b, $state);
        $rule->onActualHpLoss($b, $a, $state, 150, DamageSourceType::NORMAL_ATTACK);
        $service->bind($state, $rule);
        $service->hit($a, $b, $state, 200);
        // 24HP drain is reduced to22 at burning stack1. 50HP counter is not a new turn.
        $this->assertSame(472, $a->hp);
        $this->assertSame(740, $b->hp);
        $this->assertSame(1, $a->namelessActionSerial);
        $this->assertSame(0, $b->namelessActionSerial);
    }
}

final class NamelessRelicPvPHarness extends PvPBattleService
{
    public function skill(BattleActor $a, BattleActor $b, BattleState $state, Skill $skill): void
    {
        $this->executeSkillAction($a, $b, $state, $skill);
    }

    public function bind(BattleState $state, PvPRoomRuleInterface $rule): void
    {
        $this->associateRoomRule($state, $rule);
    }

    public function hit(BattleActor $a, BattleActor $b, BattleState $state, int $amount): void
    {
        $this->applyResolvedDamage($a, $b, $state, $amount, DamageSourceType::NORMAL_ATTACK, hitResult: HitResult::HIT, isDirect: true, damageCategory: 'physical');
    }
}
