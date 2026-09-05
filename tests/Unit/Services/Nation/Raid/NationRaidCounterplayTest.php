<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidCounterplayContext;
use App\Services\Nation\Raid\NationRaidCounterplayResolver;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidStrategySelector;
use App\Services\Nation\Raid\NationRaidTelegraphPreparationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationRaidCounterplayTest extends TestCase
{
    private NationRaidCounterplayResolver $resolver;

    private NationRaidStrategySelector $selector;

    protected function setUp(): void
    {
        $this->resolver = new NationRaidCounterplayResolver(new NationRaidRules);
        $this->selector = new NationRaidStrategySelector(new NationRaidRules, $this->resolver);
    }

    #[DataProvider('simpleEffectProvider')]
    public function test_simple_counterplay_effects_are_exact(
        string $identity,
        string $property,
        mixed $expected,
    ): void {
        $resolution = $this->resolver->resolve($identity, $this->context());
        $this->assertTrue($resolution->applied);
        $this->assertSame($expected, $resolution->{$property});
    }

    /** @return array<string, array{string,string,mixed}> */
    public static function simpleEffectProvider(): array
    {
        return [
            'no rhythm 20 percent' => ['28:5:無拍子', 'additionalTelegraphReduction', 0.20],
            'dark sword 5000' => ['30:5:暗黒剣', 'postResolutionDamage', 5_000],
            'dragon dive multiplier' => ['32:5:ドラゴンダイブ', 'playerDamageMultiplier', 1.15],
            'star light suppression' => ['53:5:星詠みの光', 'suppressUniqueEffect', true],
            'hunter delay' => ['54:5:影縫い乱舞', 'delay', true],
            'aim loss' => ['4:5:狙い撃ち', 'bossSpLoss', 3],
            'guardian reduction' => ['15:5:ガーディアンブロウ', 'telegraphReductionOverride', 0.35],
            'fortress reduction' => ['15:9:不落要塞', 'telegraphReductionOverride', 0.50],
            'transmute two charges' => ['49:5:大錬成爆装', 'bossRecoverySlowCharges', 2],
            'command delay' => ['48:5:王戦の号令', 'delay', true],
        ];
    }

    public function test_dragon_dive_also_ignores_fifty_percent_defense(): void
    {
        $resolution = $this->resolver->resolve('32:5:ドラゴンダイブ', $this->context());
        $this->assertSame(0.50, $resolution->bossDefenseIgnoreRate);
    }

    public function test_fortress_blocks_attached_interference(): void
    {
        $resolution = $this->resolver->resolve('15:9:不落要塞', $this->context());
        $this->assertTrue($resolution->blockAttachedInterference);
    }

    public function test_three_guard_dependent_arts_are_not_selectable_for_unguardable_observation(): void
    {
        $context = new NationRaidCounterplayContext(
            hit: true,
            canBeGuarded: false,
            bossSp: 100,
        );
        foreach (['28:5:無拍子', '15:5:ガーディアンブロウ', '15:9:不落要塞'] as $identity) {
            $this->assertFalse($this->resolver->isSelectable($identity, $context), $identity);
        }
    }

    public function test_hit_dependent_effects_do_not_apply_on_miss(): void
    {
        foreach (['30:5:暗黒剣', '54:5:影縫い乱舞', '4:5:狙い撃ち', '49:5:大錬成爆装'] as $identity) {
            $resolution = $this->resolver->resolve($identity, $this->context(hit: false));
            $this->assertFalse($resolution->applied, $identity);
            $this->assertSame('miss_or_evade', $resolution->notAppliedReason, $identity);
        }
    }

    public function test_rasetsu_destroys_only_a_raid_preparation_and_consumes_one_mark(): void
    {
        $preparation = $this->preparation();
        $resolution = $this->resolver->resolve(
            '33:5:羅刹連撃',
            $this->context(preparation: $preparation),
        );

        $this->assertTrue($resolution->applied);
        $this->assertTrue($resolution->preparationDestroyed);
        $this->assertSame(1, $resolution->breakMarksConsumed);
        $this->assertTrue($preparation->destroyed());
        $this->assertSame('pending-6', $preparation->pendingEnemyActionId);
    }

    public function test_rasetsu_is_not_selectable_without_preparation_and_does_not_consume_mark(): void
    {
        $context = $this->context(preparation: null);
        $this->assertFalse($this->resolver->isSelectable('33:5:羅刹連撃', $context));

        $resolution = $this->resolver->resolve('33:5:羅刹連撃', $context);
        $this->assertFalse($resolution->applied);
        $this->assertSame(0, $resolution->breakMarksConsumed);
    }

    public function test_shadow_delay_requires_hunting_mark_and_command_cannot_delay_same_pending_twice(): void
    {
        $this->assertFalse($this->resolver->isSelectable(
            '54:5:影縫い乱舞',
            $this->context(huntingMarkCount: 0),
        ));
        $this->assertFalse($this->resolver->isSelectable(
            '48:5:王戦の号令',
            $this->context(alreadyDelayed: true),
        ));
    }

    public function test_strategies_only_select_equipped_and_eligible_candidates(): void
    {
        $context = $this->context();
        $equipped = ['30:5:暗黒剣', '15:9:不落要塞'];
        $eligible = ['30:5:暗黒剣', '15:9:不落要塞', '48:5:王戦の号令'];

        $this->assertSame('30:5:暗黒剣', $this->selector->select(
            NationRaidRules::STRATEGY_ASSAULT, $equipped, $eligible, $context,
        ));
        $this->assertSame('15:9:不落要塞', $this->selector->select(
            NationRaidRules::STRATEGY_FORTIFY, $equipped, $eligible, $context,
        ));
        $this->assertContains(
            $this->selector->select(NationRaidRules::STRATEGY_INTERCEPT, $equipped, $eligible, $context),
            $equipped,
        );
        $this->assertNull($this->selector->select(
            NationRaidRules::STRATEGY_INTERCEPT,
            ['30:5:暗黒剣'],
            ['48:5:王戦の号令'],
            $context,
        ));
    }

    private function context(
        bool $hit = true,
        int $huntingMarkCount = 1,
        ?NationRaidTelegraphPreparationState $preparation = null,
        bool $alreadyDelayed = false,
    ): NationRaidCounterplayContext {
        return new NationRaidCounterplayContext(
            hit: $hit,
            canBeGuarded: true,
            bossSp: 100,
            huntingMarkCount: $huntingMarkCount,
            breakMarkCount: 1,
            preparation: $preparation,
            alreadyDelayed: $alreadyDelayed,
        );
    }

    private function preparation(): NationRaidTelegraphPreparationState
    {
        return new NationRaidTelegraphPreparationState(
            preparationId: 'prep-6',
            pendingEnemyActionId: 'pending-6',
            kind: 'reflect',
            sourceCycleId: 'cycle-1',
            createdTurn: 5,
            expiresOn: 7,
        );
    }
}
