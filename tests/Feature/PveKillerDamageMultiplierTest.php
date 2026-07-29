<?php

namespace Tests\Feature;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 種族特攻(applyPveKillerDamage)は武器のランク比例補正とは独立した仕組みであり、
 * 今回の武器調整によって多重適用や崩れが起きていないことを確認する回帰テスト。
 */
class PveKillerDamageMultiplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_killer_damage_rate_is_applied_exactly_once_when_species_matches(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_species_key' => 'dragon',
            'weapon_killer_damage_rate' => 0.30, // 銘V逸品相当
        ]);
        $defender = new BattleActor('竜', false, [
            'str' => 100,
            'species_key' => 'dragon',
        ]);
        $state = new BattleState($attacker, $defender, 'pve');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        // 1000 * (1 + 0.30) = 1300 であり、1000 * (1.30 ^ 2) のような二重適用にはならない
        $this->assertSame(1300, $result);
    }

    public function test_killer_damage_rate_does_not_apply_when_species_does_not_match(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_species_key' => 'dragon',
            'weapon_killer_damage_rate' => 0.30,
        ]);
        $defender = new BattleActor('スライム', false, [
            'str' => 100,
            'species_key' => 'slime',
        ]);
        $state = new BattleState($attacker, $defender, 'pve');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        $this->assertSame(1000, $result);
    }

    public function test_innate_and_affix_killer_rates_add_once_for_the_same_species(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_effects' => [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.12],
                ['source' => 'affix', 'species_key' => 'dragon', 'damage_rate' => 0.30],
            ],
        ]);
        $defender = new BattleActor('竜', false, [
            'str' => 100,
            'species_key' => 'dragon',
        ]);
        $state = new BattleState($attacker, $defender, 'boss');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        $this->assertSame(1420, $result);
    }

    public function test_only_the_matching_killer_effect_activates_for_different_species(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_effects' => [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.12],
                ['source' => 'affix', 'species_key' => 'machine', 'damage_rate' => 0.30],
            ],
        ]);
        $defender = new BattleActor('機械兵', false, [
            'str' => 100,
            'species_key' => 'machine',
        ]);
        $state = new BattleState($attacker, $defender, 'pve');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        $this->assertSame(1300, $result);
    }

    public function test_combined_killer_rate_is_capped_at_fifty_five_percent(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_effects' => [
                ['source' => 'innate', 'species_key' => 'dragon', 'damage_rate' => 0.25],
                ['source' => 'affix', 'species_key' => 'dragon', 'damage_rate' => 0.40],
            ],
        ]);
        $defender = new BattleActor('竜', false, [
            'str' => 100,
            'species_key' => 'dragon',
        ]);
        $state = new BattleState($attacker, $defender, 'pve');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        $this->assertSame(1550, $result);
    }

    public function test_killer_damage_rate_is_not_applied_in_rank_battle_type(): void
    {
        $service = app(BattleService::class);

        $attacker = new BattleActor('プレイヤー', true, [
            'str' => 100,
            'weapon_killer_species_key' => 'dragon',
            'weapon_killer_damage_rate' => 0.30,
        ]);
        $defender = new BattleActor('竜', false, [
            'str' => 100,
            'species_key' => 'dragon',
        ]);
        $state = new BattleState($attacker, $defender, 'rank');

        $result = $this->invokePrivate($service, 'applyPveKillerDamage', [1000, $attacker, $defender, $state]);

        $this->assertSame(1000, $result);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
