<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidSeededRandom;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationRaidRulesTest extends TestCase
{
    private NationRaidRules $rules;

    protected function setUp(): void
    {
        $this->rules = new NationRaidRules;
    }

    public function test_stage_endpoints_and_breakpoints_are_exact(): void
    {
        $this->assertSame(2_200, $this->rules->stageParameters(1)['attack']);
        $this->assertSame(3_520, $this->rules->stageParameters(20)['attack']);
        $this->assertSame(3_520, $this->rules->stageParameters(20)['magic']);

        $expected = [
            1 => ['微睡', ['observation', 'observation', 'observation'], 0],
            4 => ['微睡', ['observation', 'observation', 'observation'], 0],
            5 => ['胎動', ['observation', 'observation', 'counter'], 4],
            8 => ['胎動', ['observation', 'observation', 'counter'], 4],
            9 => ['覚醒', ['observation', 'counter', 'counter'], 8],
            12 => ['覚醒', ['observation', 'counter', 'counter'], 8],
            13 => ['侵界', ['counter', 'counter', 'counter'], 12],
            16 => ['侵界', ['counter', 'counter', 'counter'], 12],
            17 => ['暴界', ['counter', 'counter', 'counter'], 16],
            19 => ['暴界', ['counter', 'counter', 'counter'], 16],
            20 => ['真醒', ['counter', 'counter', 'counter'], 20],
        ];

        foreach ($expected as $stage => [$name, $slots, $bonus]) {
            $actual = $this->rules->stageParameters($stage);
            $this->assertSame($name, $actual['stage_name'], "stage {$stage}");
            $this->assertSame($slots, array_values($actual['reserved_slots']), "stage {$stage}");
            $this->assertSame($bonus, $actual['form_action_weight_bonus'], "stage {$stage}");
        }
    }

    public function test_attack_growth_is_a_sweep_parameter_and_changes_ruleset_hash(): void
    {
        $lowerGrowth = new NationRaidRules(0.40);

        $this->assertSame(3_080, $lowerGrowth->stageParameters(20)['attack']);
        $this->assertNotSame($this->rules->rulesetHash(), $lowerGrowth->rulesetHash());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->rules->rulesetHash());
    }

    public function test_twenty_stage_hp_curve_totals_six_hundred_million(): void
    {
        $expected = [...array_fill(0, 4, 10_000_000), ...array_fill(0, 4, 20_000_000),
            ...array_fill(0, 4, 30_000_000), ...array_fill(0, 4, 40_000_000), ...array_fill(0, 4, 50_000_000)];
        $snapshot = $this->rules->rulesetSnapshot();
        foreach ($expected as $index => $hp) {
            $stage = $index + 1;
            $this->assertSame($hp, $this->rules->stageMaxHp($stage));
            $this->assertSame($hp, $snapshot['stages'][$index]['max_hp']);
            foreach ($this->rules->formKeys() as $form) {
                $this->assertSame($form, $this->rules->formForHp(
                    $this->rules->canonicalCycleCurrentHpForForm($form, $stage), $hp));
            }
        }
        $this->assertSame(600_000_000, $this->rules->totalTargetHp());
        $this->assertSame(600_000_000, $snapshot['fixed']['total_target_hp']);
    }

    public function test_only_the_exact_legacy_hp_only_ruleset_remains_combat_compatible(): void
    {
        $legacyHash = '1d681aa8069dabf7a976070378e27c0136ef497b5ace0317f191492619c03dda';
        $this->assertTrue($this->rules->matchesCombatRulesetHash($legacyHash));
        $this->assertTrue($this->rules->matchesCombatRulesetHash($this->rules->rulesetHash()));
        $this->assertFalse($this->rules->matchesCombatRulesetHash(str_repeat('0', 64)));
        $this->assertFalse((new NationRaidRules(0.4))->matchesCombatRulesetHash($legacyHash));
    }

    public function test_killer_boost_and_nation_coordination_rates_are_exact(): void
    {
        $this->assertSame(0.60, NationRaidRules::raidKillerDamageRate(0.30));
        $this->assertSame(1.0, NationRaidRules::raidKillerDamageRate(0.525));
        $this->assertSame(0.0, NationRaidRules::coordinationDamageRate(1));
        $this->assertSame(0.03, NationRaidRules::coordinationDamageRate(2));
        $this->assertSame(0.06, NationRaidRules::coordinationDamageRate(3));
        $this->assertSame(0.09, NationRaidRules::coordinationDamageRate(4));
        $this->assertSame(0.12, NationRaidRules::coordinationDamageRate(5));
        $this->assertSame(0.12, NationRaidRules::coordinationDamageRate(40));
    }

    #[DataProvider('formBoundaryProvider')]
    public function test_form_hp_boundaries_are_exact(int $hp, string $expected): void
    {
        $this->assertSame($expected, $this->rules->formForHp($hp, 5_000_000));
    }

    public function test_canonical_form_starting_hp_uses_the_same_boundaries(): void
    {
        $expected = [
            NationRaidRules::FORM_SEALED_SCALE => 10_000_000,
            NationRaidRules::FORM_SPLIT_WING => 7_000_000,
            NationRaidRules::FORM_LINEAGE_INVASION => 4_000_000,
            NationRaidRules::FORM_EXPOSED_CORE => 1_000_000,
        ];

        foreach ($expected as $form => $hp) {
            $this->assertSame($hp, $this->rules->canonicalCycleCurrentHpForForm($form));
            $this->assertSame($form, $this->rules->formForHp($hp));
        }
    }

    /** @return array<string, array{int,string}> */
    public static function formBoundaryProvider(): array
    {
        return [
            '100 percent' => [5_000_000, NationRaidRules::FORM_SEALED_SCALE],
            'over 70 percent' => [3_500_001, NationRaidRules::FORM_SEALED_SCALE],
            'exactly 70 percent' => [3_500_000, NationRaidRules::FORM_SPLIT_WING],
            'exactly 40 percent' => [2_000_000, NationRaidRules::FORM_LINEAGE_INVASION],
            'exactly 10 percent' => [500_000, NationRaidRules::FORM_EXPOSED_CORE],
            'one hp' => [1, NationRaidRules::FORM_EXPOSED_CORE],
        ];
    }

    public function test_four_forms_have_the_approved_assets_and_fixed_parameters(): void
    {
        $expected = [
            NationRaidRules::FORM_SEALED_SCALE => ['封鱗', 0.85, 0.00, 'images/raid/valgreid_form_01.webp'],
            NationRaidRules::FORM_SPLIT_WING => ['裂翼', 1.00, 0.05, 'images/raid/valgreid_form_02.webp'],
            NationRaidRules::FORM_LINEAGE_INVASION => ['十系侵蝕', 1.15, 0.10, 'images/raid/valgreid_form_03.webp'],
            NationRaidRules::FORM_EXPOSED_CORE => ['露核', 1.30, 0.00, 'images/raid/valgreid_form_04.webp'],
        ];

        foreach ($expected as $key => [$name, $outgoing, $incoming, $image]) {
            $actual = $this->rules->formParameters($key);
            $this->assertSame($name, $actual['name']);
            $this->assertSame($outgoing, $actual['outgoing_multiplier']);
            $this->assertSame($incoming, $actual['incoming_reduction']);
            $this->assertSame($image, $actual['image_path']);
        }

        $this->assertSame(10_000_000, NationRaidRules::BOSS_MAX_HP);
        $this->assertSame('dragon', NationRaidRules::BOSS_SPECIES_KEY);
        $this->assertSame(100, NationRaidRules::BOSS_MAX_SP);
        $this->assertSame(100, NationRaidRules::BOSS_DEFENSE);
        $this->assertSame(100, NationRaidRules::BOSS_SPIRIT);
        $this->assertSame(1_000, NationRaidRules::BOSS_AGILITY);
        $this->assertSame(100, NationRaidRules::BOSS_LUCK);
    }

    public function test_four_form_assets_exist_with_the_reviewed_sha256(): void
    {
        $expected = [
            NationRaidRules::FORM_SEALED_SCALE => '19c9861a2c60f766615ba4f214e46c250bab8556fae0bd14e7658c264299b0ec',
            NationRaidRules::FORM_SPLIT_WING => '25a5a0b51dc10677d7026a224dfc7696ea19009620d8e754e011a28aa9efc410',
            NationRaidRules::FORM_LINEAGE_INVASION => '398a3caa270cc0d9dc9f00968abadc3e2a256e63d5327de51975be63589721e2',
            NationRaidRules::FORM_EXPOSED_CORE => '82012cf8660399f181ab2a4e7dfab268e95b6376328cd9ab4dbbe3cbfe2a2e71',
        ];
        $root = dirname(__DIR__, 5);

        foreach ($expected as $form => $sha256) {
            $path = $root.'/public/'.$this->rules->formParameters($form)['image_path'];
            $this->assertFileExists($path);
            $this->assertSame($sha256, hash_file('sha256', $path));
        }
    }

    public function test_all_400_stage_form_turn_band_cells_are_complete_and_unique(): void
    {
        $cells = $this->rules->stateCells();
        $this->assertCount(400, $cells);

        $keys = [];
        foreach ($cells as $cell) {
            $key = implode(':', [$cell['stage'], $cell['form'], $cell['turn_band']]);
            $this->assertArrayNotHasKey($key, $keys);
            $keys[$key] = true;
            $this->assertGreaterThanOrEqual(2_200, $cell['attack']);
            $this->assertLessThanOrEqual(3_520, $cell['attack']);
            $this->assertLessThanOrEqual(0.25, $cell['boss_incoming_reduction']);
        }

        $terminal = array_values(array_filter($cells, static fn (array $cell): bool => $cell['stage'] === 20
            && $cell['form'] === NationRaidRules::FORM_LINEAGE_INVASION
            && $cell['turn_band'] === 'turn_20'));
        $this->assertCount(1, $terminal);
        $this->assertSame(0.25, $terminal[0]['boss_incoming_reduction']);
        $this->assertEqualsWithDelta(1.725, $terminal[0]['boss_outgoing_multiplier'], 0.000001);
        $this->assertSame(0.40, $terminal[0]['action_cap_rate']);
    }

    public function test_seven_basic_actions_are_exact(): void
    {
        $actions = $this->rules->basicActions();
        $this->assertSame([
            'black_sky_claw' => ['name' => '黒天裂爪', 'hits' => [['type' => 'physical', 'power' => 70]], 'effect' => null, 'can_be_guarded' => false],
            'void_corrosion_orb' => ['name' => '虚蝕弾', 'hits' => [['type' => 'magical', 'power' => 70]], 'effect' => null, 'can_be_guarded' => false],
            'sealed_quake' => ['name' => '封鱗震', 'hits' => [['type' => 'physical', 'power' => 60]], 'effect' => 'defense_down_10_two_actions', 'can_be_guarded' => false],
            'split_wing_combo' => ['name' => '裂翼連爪', 'hits' => [['type' => 'physical', 'power' => 55], ['type' => 'physical', 'power' => 55]], 'effect' => null, 'can_be_guarded' => false],
            'lineage_roar' => ['name' => '侵系咆哮', 'hits' => [['type' => 'magical', 'power' => 85]], 'effect' => 'healing_down_25_two_actions', 'can_be_guarded' => false],
            'dragon_core_backlight' => ['name' => '竜核逆光', 'hits' => [['type' => 'physical', 'power' => 60], ['type' => 'magical', 'power' => 60]], 'effect' => null, 'can_be_guarded' => false],
            'ten_lineage_end' => ['name' => '十系終焉・ヴァルグレイド', 'hits' => [['type' => 'physical', 'power' => 90], ['type' => 'magical', 'power' => 90]], 'effect' => null, 'can_be_guarded' => true],
        ], $actions);
    }

    public function test_ten_counter_actions_are_exact_and_guardable(): void
    {
        $actions = $this->rules->counterActions();
        $summary = array_map(static fn (array $action): array => [
            $action['boss_lineage'], $action['action_id'], $action['name'], $action['hits'],
            $action['effect'], $action['preparation_kind'], $action['can_be_guarded'],
        ], $actions);

        $this->assertSame([
            'counter' => ['field', 'silent_black_field', '無響黒界', [['type' => 'magical', 'power' => 80]], 'counter_damage_down_50', null, true],
            'field' => ['command', 'world_law_severance', '界律断令', [['type' => 'physical', 'power' => 70]], 'field_remove_and_extension_block', null, true],
            'command' => ['aim', 'command_core_snipe', '司令核狙撃', [['type' => 'magical', 'power' => 75]], 'current_sp_down_8', null, true],
            'aim' => ['counter', 'black_mirror_counter', '黒鏡返し', [['type' => 'physical', 'power' => 60]], 'nonlethal_reflect_max_hp_8', 'reflect', true],
            'guardian' => ['break', 'guardian_world_breaker', '護界砕爪', [['type' => 'physical', 'power' => 75]], 'defense_spirit_healing_down_25_two_actions', null, true],
            'break' => ['transmute', 'reverse_transmutation_scale', '逆錬成鱗', [['type' => 'magical', 'power' => 65]], 'cleanse_and_guard_per_debuff', 'cleanse_guard', true],
            'transmute' => ['dark', 'corrosion_absorption_ring', '腐蝕吸環', [['type' => 'magical', 'power' => 70]], 'hp_sp_healing_down_50_two_actions', null, true],
            'dark' => ['pierce', 'blood_pact_piercing_horn', '血盟穿角', [['type' => 'physical', 'power' => 100, 'defense_ignore' => 0.50]], 'drain_healing_down_50_one_action', null, true],
            'pierce' => ['hunt', 'phantom_scale_hunt_mark', '幻鱗狩印', [['type' => 'physical', 'power' => 65]], 'next_direct_damage_down_30', null, true],
            'hunt' => ['guardian', 'purified_hunt_dragon_circle', '浄狩竜陣', [['type' => 'magical', 'power' => 60]], 'clear_marks_and_next_multihit_down_25', null, true],
        ], $summary);
    }

    public function test_eleven_counterplay_exact_identities_are_fixed(): void
    {
        $this->assertSame([
            '28:5:無拍子' => ['name' => '無拍子', 'effect' => 'counter_intercept', 'category' => 'defense'],
            '30:5:暗黒剣' => ['name' => '暗黒剣', 'effect' => 'eclipse_backlash', 'category' => 'offense'],
            '32:5:ドラゴンダイブ' => ['name' => 'ドラゴンダイブ', 'effect' => 'pierce_opening', 'category' => 'offense'],
            '53:5:星詠みの光' => ['name' => '星詠みの光', 'effect' => 'field_suppression', 'category' => 'intercept'],
            '54:5:影縫い乱舞' => ['name' => '影縫い乱舞', 'effect' => 'hunt_cancel', 'category' => 'intercept'],
            '4:5:狙い撃ち' => ['name' => '狙い撃ち', 'effect' => 'aim_sp_pressure', 'category' => 'intercept'],
            '15:5:ガーディアンブロウ' => ['name' => 'ガーディアンブロウ', 'effect' => 'ultimate_guard', 'category' => 'defense'],
            '15:9:不落要塞' => ['name' => '不落要塞', 'effect' => 'fortress_guard', 'category' => 'defense'],
            '49:5:大錬成爆装' => ['name' => '大錬成爆装', 'effect' => 'transmute_resource_slow', 'category' => 'intercept'],
            '33:5:羅刹連撃' => ['name' => '羅刹連撃', 'effect' => 'break_preparation', 'category' => 'intercept'],
            '48:5:王戦の号令' => ['name' => '王戦の号令', 'effect' => 'readiness_delay', 'category' => 'intercept'],
        ], $this->rules->counterplayArts());
    }

    public function test_stage_twenty_action_weights_match_sealed_and_exposed_examples(): void
    {
        $this->assertSame(
            ['basic_physical' => 35, 'basic_magical' => 35, 'form_action' => 30],
            $this->rules->actionWeights(20, NationRaidRules::FORM_SEALED_SCALE),
        );
        $this->assertSame(
            ['basic_physical' => 20, 'basic_magical' => 20, 'form_action' => 60],
            $this->rules->actionWeights(20, NationRaidRules::FORM_EXPOSED_CORE),
        );
    }

    public function test_seeded_random_is_local_and_reproducible(): void
    {
        $first = new NationRaidSeededRandom(20260901);
        $second = new NationRaidSeededRandom(20260901);
        $third = new NationRaidSeededRandom(20260902);

        $a = array_map(static fn (): int => $first->nextInt(1, 100), range(1, 10));
        $b = array_map(static fn (): int => $second->nextInt(1, 100), range(1, 10));
        $c = array_map(static fn (): int => $third->nextInt(1, 100), range(1, 10));

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_boss_set_snapshot_preserves_slot_order_and_rejects_more_than_five_slots(): void
    {
        $snapshot = new NationRaidPlayerSnapshot(
            maxHp: 100,
            defense: 10,
            spirit: 10,
            bossSetExactIdentities: [null, '4:5:狙い撃ち', null, '15:9:不落要塞'],
        );
        $this->assertSame([null, '4:5:狙い撃ち', null, '15:9:不落要塞'], $snapshot->bossSetExactIdentities);

        $this->expectException(InvalidArgumentException::class);
        new NationRaidPlayerSnapshot(
            maxHp: 100,
            defense: 10,
            spirit: 10,
            bossSetExactIdentities: ['a', 'b', 'c', 'd', 'e', 'f'],
        );
    }
}
