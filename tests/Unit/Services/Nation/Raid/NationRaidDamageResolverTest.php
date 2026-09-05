<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidDamageResolver;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRandomSource;
use App\Services\Nation\Raid\NationRaidRules;
use RuntimeException;
use Tests\TestCase;

final class NationRaidDamageResolverTest extends TestCase
{
    private NationRaidRules $rules;

    private NationRaidDamageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new NationRaidRules;
        $this->resolver = new NationRaidDamageResolver($this->rules);
    }

    public function test_enemy_damage_order_is_hit_critical_variance_floor_sum_cap_then_final_reduction(): void
    {
        $player = new NationRaidPlayerSnapshot(
            maxHp: 5_000,
            defense: 1_000,
            spirit: 1_000,
            enemyHitChancePercent: 100,
            enemyCriticalChancePercent: 50,
            finalDamageReductionRate: 0.20,
        );
        // hit1: HIT, critical, 115%; hit2: HIT, non-critical, 85%.
        $random = new SequenceRaidRandom([1, 1, 115, 1, 100, 85]);
        $result = $this->resolver->resolveEnemyAction(
            action: $this->rules->basicAction('split_wing_combo'),
            stage: 1,
            form: NationRaidRules::FORM_SPLIT_WING,
            turn: 11,
            player: $player,
            random: $random,
        );

        $critical = (int) floor(
            ((2_200 * 2_200) / (2_200 + (3.5 * 500))) * 0.55 * 1.5 * 1.15,
        );
        $normal = (int) floor(
            ((2_200 * 2_200) / (2_200 + (3.5 * 1_000))) * 0.55 * 0.85,
        );
        $cap = (int) floor(5_000 * 0.13);

        $this->assertSame($critical, $result->hits[0]['damage']);
        $this->assertSame($normal, $result->hits[1]['damage']);
        $this->assertSame($critical + $normal, $result->beforeCap);
        $this->assertSame($cap, $result->afterCap);
        $this->assertSame((int) floor($cap * 0.80), $result->finalDamage);
    }

    public function test_raid_defense_coefficient_is_independent_from_runtime_pve_config(): void
    {
        $player = new NationRaidPlayerSnapshot(
            maxHp: 10_000,
            defense: 1_000,
            spirit: 1_000,
            enemyHitChancePercent: 100,
            enemyCriticalChancePercent: 0,
        );
        $action = $this->rules->basicAction('black_sky_claw');

        config(['battle.pve_enemy_percentage_defense.defense_coefficient' => 3.5]);
        $baseline = $this->resolver->resolveEnemyAction(
            $action, 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            $player, new SequenceRaidRandom([1, 100, 100]),
        );

        config(['battle.pve_enemy_percentage_defense.defense_coefficient' => 9.75]);
        $changedPveConfig = $this->resolver->resolveEnemyAction(
            $action, 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            $player, new SequenceRaidRandom([1, 100, 100]),
        );

        $this->assertSame(3.5, NationRaidRules::DEFENSE_COEFFICIENT);
        $this->assertSame($baseline->toArray(), $changedPveConfig->toArray());
    }

    public function test_miss_and_evade_do_not_roll_critical_or_variance(): void
    {
        $missPlayer = new NationRaidPlayerSnapshot(maxHp: 10_000, defense: 0, spirit: 0, enemyHitChancePercent: 50);
        $miss = $this->resolver->resolveEnemyAction(
            $this->rules->basicAction('black_sky_claw'), 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            $missPlayer, new SequenceRaidRandom([100]),
        );
        $this->assertSame('miss', $miss->hits[0]['outcome']);
        $this->assertSame(0, $miss->finalDamage);

        $evadePlayer = new NationRaidPlayerSnapshot(
            maxHp: 10_000, defense: 0, spirit: 0,
            enemyHitChancePercent: 100, enemyEvadeChancePercent: 100,
        );
        $evade = $this->resolver->resolveEnemyAction(
            $this->rules->basicAction('black_sky_claw'), 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            $evadePlayer, new SequenceRaidRandom([1, 1]),
        );
        $this->assertSame('evade', $evade->hits[0]['outcome']);
        $this->assertSame(0, $evade->finalDamage);
    }

    public function test_multihit_action_is_clamped_once_after_hit_sum(): void
    {
        $player = new NationRaidPlayerSnapshot(
            maxHp: 1_000,
            defense: 0,
            spirit: 0,
            enemyHitChancePercent: 100,
            enemyCriticalChancePercent: 0,
        );
        $result = $this->resolver->resolveEnemyAction(
            $this->rules->basicAction('ten_lineage_end'), 20, NationRaidRules::FORM_EXPOSED_CORE, 20,
            $player, new SequenceRaidRandom([1, 100, 100, 1, 100, 100]),
        );

        $this->assertGreaterThan(400, $result->hits[0]['damage']);
        $this->assertGreaterThan(800, $result->beforeCap);
        $this->assertSame(400, $result->cap);
        $this->assertSame(400, $result->afterCap);
        $this->assertSame(400, $result->finalDamage);
    }

    public function test_attached_effect_requires_hit_and_final_damage_of_at_least_one(): void
    {
        $action = $this->rules->basicAction('sealed_quake');
        $miss = $this->resolver->resolveEnemyAction(
            $action, 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            new NationRaidPlayerSnapshot(maxHp: 100, defense: 0, spirit: 0, enemyHitChancePercent: 0),
            new SequenceRaidRandom([100]),
        );
        $this->assertSame([], $miss->appliedEffects);

        $zeroAfterCap = $this->resolver->resolveEnemyAction(
            $action, 1, NationRaidRules::FORM_SEALED_SCALE, 1,
            new NationRaidPlayerSnapshot(maxHp: 1, defense: 0, spirit: 0, enemyHitChancePercent: 100, enemyCriticalChancePercent: 0),
            new SequenceRaidRandom([1, 100, 100]),
        );
        $this->assertSame(0, $zeroAfterCap->finalDamage);
        $this->assertSame([], $zeroAfterCap->appliedEffects);
    }

    public function test_suppression_keeps_base_damage_but_removes_unique_effect(): void
    {
        $player = new NationRaidPlayerSnapshot(maxHp: 10_000, defense: 0, spirit: 0, enemyHitChancePercent: 100, enemyCriticalChancePercent: 0);
        $normal = $this->resolver->resolveEnemyAction(
            $this->rules->basicAction('sealed_quake'), 1, NationRaidRules::FORM_SEALED_SCALE, 6,
            $player, new SequenceRaidRandom([1, 100, 100]),
        );
        $suppressed = $this->resolver->resolveEnemyAction(
            $this->rules->basicAction('sealed_quake'), 1, NationRaidRules::FORM_SEALED_SCALE, 6,
            $player, new SequenceRaidRandom([1, 100, 100]), suppressUniqueEffect: true,
        );

        $this->assertSame($normal->finalDamage, $suppressed->finalDamage);
        $this->assertSame(['defense_down_10_two_actions'], $normal->appliedEffects);
        $this->assertSame([], $suppressed->appliedEffects);
    }

    public function test_fortress_blocks_stat_and_sp_interference_but_not_unrelated_reflect_effect(): void
    {
        $player = new NationRaidPlayerSnapshot(maxHp: 10_000, defense: 0, spirit: 0, enemyHitChancePercent: 100, enemyCriticalChancePercent: 0);
        $spInterference = $this->resolver->resolveEnemyAction(
            $this->rules->counterAction('command'), 13, NationRaidRules::FORM_SEALED_SCALE, 6,
            $player, new SequenceRaidRandom([1, 100, 100]), blockAttachedInterference: true,
        );
        $reflect = $this->resolver->resolveEnemyAction(
            $this->rules->counterAction('aim'), 13, NationRaidRules::FORM_SEALED_SCALE, 6,
            $player, new SequenceRaidRandom([1, 100, 100]), blockAttachedInterference: true,
        );

        $this->assertSame([], $spInterference->appliedEffects);
        $this->assertSame(['nonlethal_reflect_max_hp_8'], $reflect->appliedEffects);
    }

    public function test_player_sources_apply_raid_reduction_individually_and_max_action_excludes_later_sources(): void
    {
        $sources = [
            ['kind' => NationRaidRules::DAMAGE_DIRECT, 'damage' => 1_000, 'hit_count' => 1, 'defense_ignore_50_damage' => null],
            ['kind' => NationRaidRules::DAMAGE_SIMULTANEOUS, 'damage' => 200, 'hit_count' => 1, 'defense_ignore_50_damage' => null],
            ['kind' => NationRaidRules::DAMAGE_DOT, 'damage' => 300, 'hit_count' => 1, 'defense_ignore_50_damage' => null],
            ['kind' => NationRaidRules::DAMAGE_COUNTER, 'damage' => 400, 'hit_count' => 1, 'defense_ignore_50_damage' => null],
            ['kind' => NationRaidRules::DAMAGE_ECLIPSE_BACKLASH, 'damage' => 500, 'hit_count' => 1, 'defense_ignore_50_damage' => null],
        ];
        $result = $this->resolver->resolvePlayerAction(
            $sources, 11, NationRaidRules::FORM_LINEAGE_INVASION,
        );

        $this->assertSame([800, 160, 240, 320, 400], array_column($result['sources'], 'applied_damage'));
        $this->assertSame(1_920, $result['total_damage']);
        $this->assertSame(960, $result['max_one_action_damage']);
        $this->assertSame(0.20, $result['incoming_reduction']);
    }

    public function test_dragon_dive_uses_precomputed_defense_ignore_damage_then_final_multiplier(): void
    {
        $sources = [[
            'kind' => NationRaidRules::DAMAGE_DIRECT,
            'damage' => 1_000,
            'hit_count' => 1,
            'defense_ignore_50_damage' => 1_200,
        ]];
        $result = $this->resolver->resolvePlayerAction(
            $sources,
            1,
            NationRaidRules::FORM_SEALED_SCALE,
            responseDamageMultiplier: 1.15,
            responseDefenseIgnoreRate: 0.50,
        );

        $this->assertSame(1_380, $result['total_damage']);
    }
}

final class SequenceRaidRandom implements NationRaidRandomSource
{
    /** @param list<int> $values */
    public function __construct(private array $values) {}

    public function nextInt(int $minimum, int $maximum): int
    {
        if ($this->values === []) {
            throw new RuntimeException('SequenceRaidRandom was exhausted.');
        }
        $value = array_shift($this->values);
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException("Scripted value {$value} is outside {$minimum}..{$maximum}.");
        }

        return $value;
    }
}
