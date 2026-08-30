<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\JobArtV2PreparedEffectState;
use App\Services\JobArtV2TimedEffectState;
use PHPUnit\Framework\TestCase;

class JobArtV2TimedEffectStateTest extends TestCase
{
    public function test_timed_effect_exposes_its_source_and_skips_the_applied_round(): void
    {
        $state = new JobArtV2TimedEffectState(
            key: 'counter_sheathed_tempo',
            statModifiers: ['str' => 0.08],
            appliedRound: 4,
            remainingRounds: 2,
            sourceActionId: 91,
            sourceSkillId: 74,
            removable: true,
            strength: 8,
        );

        $this->assertSame('counter_sheathed_tempo', $state->key);
        $this->assertSame(['str' => 0.08], $state->statModifiers);
        $this->assertSame(4, $state->appliedRound);
        $this->assertSame(2, $state->remainingRounds);
        $this->assertSame(91, $state->sourceActionId);
        $this->assertSame(74, $state->sourceSkillId);
        $this->assertTrue($state->removable);
        $this->assertSame(8.0, $state->strength);

        $this->assertFalse($state->advanceAtRoundEnd(4));
        $this->assertSame(2, $state->remainingRounds);
        $this->assertFalse($state->isExpired());

        $this->assertTrue($state->advanceAtRoundEnd(5));
        $this->assertSame(1, $state->remainingRounds);
        $this->assertFalse($state->advanceAtRoundEnd(5), 'The same round must not be processed twice.');
        $this->assertSame(1, $state->remainingRounds);

        $this->assertTrue($state->advanceAtRoundEnd(6));
        $this->assertSame(0, $state->remainingRounds);
        $this->assertTrue($state->isExpired());
    }

    public function test_prepared_effect_tracks_trigger_contract_duration_and_charge(): void
    {
        $state = new JobArtV2PreparedEffectState(
            key: 'pierce_flexible_prep',
            multiplier: 1.10,
            appliedRound: 7,
            remainingRounds: 3,
            charges: 1,
            sourceActionId: 120,
            sourceSkillId: 125,
            targetLineage: 'pierce',
            targetRanks: [5, 9],
            strictNextAction: false,
            group: 'pierce_prep',
        );

        $this->assertSame('pierce_flexible_prep', $state->key);
        $this->assertSame(1.10, $state->multiplier);
        $this->assertSame(7, $state->appliedRound);
        $this->assertSame(3, $state->remainingRounds);
        $this->assertSame(1, $state->charges);
        $this->assertSame(120, $state->sourceActionId);
        $this->assertSame(125, $state->sourceSkillId);
        $this->assertSame('pierce', $state->targetLineage);
        $this->assertSame([5, 9], $state->targetRanks);
        $this->assertFalse($state->strictNextAction);
        $this->assertSame('pierce_prep', $state->group);

        $this->assertFalse($state->advanceAtRoundEnd(7));
        $this->assertSame(3, $state->remainingRounds);
        $this->assertTrue($state->advanceAtRoundEnd(8));
        $this->assertSame(2, $state->remainingRounds);

        $this->assertTrue($state->consumeCharge());
        $this->assertSame(0, $state->charges);
        $this->assertTrue($state->isExpired());
        $this->assertFalse($state->consumeCharge());
    }

    public function test_prepared_effect_can_expire_by_own_action_opportunities_without_round_expiry(): void
    {
        $state = new JobArtV2PreparedEffectState(
            key: 'counter_focus',
            multiplier: 1.20,
            appliedRound: 7,
            remainingRounds: null,
            charges: 2,
            sourceActionId: 120,
            sourceSkillId: 125,
            targetLineage: 'counter',
            targetRanks: [5, 9],
            strictNextAction: false,
            group: 'counter_focus',
            remainingActionOpportunities: 6,
        );

        $this->assertFalse($state->advanceAtRoundEnd(99));
        $this->assertSame(6, $state->remainingActionOpportunities);
        for ($remaining = 5; $remaining >= 0; $remaining--) {
            $this->assertTrue($state->consumeActionOpportunity());
            $this->assertSame($remaining, $state->remainingActionOpportunities);
            $this->assertSame($remaining === 0, $state->isExpired());
        }
        $this->assertFalse($state->consumeActionOpportunity());
        $this->assertSame(2, $state->charges, 'Opportunity expiry does not spend the prepared charge.');
    }

    public function test_prepared_effect_without_a_round_limit_expires_only_after_its_charge_is_spent(): void
    {
        $state = new JobArtV2PreparedEffectState(
            key: 'pierce_burst_prep',
            multiplier: 1.15,
            appliedRound: 2,
            remainingRounds: null,
            charges: 1,
            sourceActionId: 22,
            sourceSkillId: 47,
            targetLineage: 'pierce',
            targetRanks: [5, 9],
            strictNextAction: true,
            group: 'pierce_prep',
        );

        $this->assertFalse($state->advanceAtRoundEnd(99));
        $this->assertNull($state->remainingRounds);
        $this->assertFalse($state->isExpired());
        $this->assertTrue($state->consumeCharge());
        $this->assertTrue($state->isExpired());
    }

    public function test_battle_actor_replaces_lists_and_removes_effects_by_key(): void
    {
        $actor = $this->actor();
        $first = $this->timed('shared-buff', 0.08, 2, 10);
        $replacement = $this->timed('shared-buff', 0.15, 3, 11);
        $other = $this->timed('other-buff', 0.05, 3, 12);

        $actor->replaceJobArtV2TimedEffect($first);
        $actor->replaceJobArtV2TimedEffect($replacement);
        $actor->replaceJobArtV2TimedEffect($other);

        $this->assertSame($replacement, $actor->jobArtV2TimedEffect('shared-buff'));
        $this->assertSame([$replacement, $other], $actor->jobArtV2TimedEffects());
        $this->assertSame($replacement, $actor->removeJobArtV2TimedEffect('shared-buff'));
        $this->assertNull($actor->jobArtV2TimedEffect('shared-buff'));
        $this->assertNull($actor->removeJobArtV2TimedEffect('missing'));

        $burst = $this->prepared('pierce_burst_prep', true, 20);
        $flexible = $this->prepared('pierce_flexible_prep', false, 21);
        $actor->replaceJobArtV2PreparedEffect($burst);
        $actor->replaceJobArtV2PreparedEffect($flexible);

        $this->assertSame($burst, $actor->jobArtV2PreparedEffect('pierce_burst_prep'));
        $this->assertSame([$burst, $flexible], $actor->jobArtV2PreparedEffects());
        $this->assertSame($burst, $actor->removeJobArtV2PreparedEffect('pierce_burst_prep'));
        $this->assertNull($actor->jobArtV2PreparedEffect('pierce_burst_prep'));
        $this->assertNull($actor->removeJobArtV2PreparedEffect('missing'));
    }

    public function test_direct_attack_damage_received_snapshot_is_consumed_once(): void
    {
        $actor = $this->actor();

        $this->assertFalse($actor->consumeDirectAttackDamageReceivedSinceOwnActionSnapshot());

        $actor->markDirectAttackDamageReceivedSinceOwnAction();
        $actor->markDirectAttackDamageReceivedSinceOwnAction();

        $this->assertTrue($actor->consumeDirectAttackDamageReceivedSinceOwnActionSnapshot());
        $this->assertFalse($actor->consumeDirectAttackDamageReceivedSinceOwnActionSnapshot());
    }

    private function actor(): BattleActor
    {
        return new BattleActor('tester', true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ]);
    }

    private function timed(string $key, float $rate, int $rounds, int $sourceActionId): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: ['str' => $rate],
            appliedRound: 1,
            remainingRounds: $rounds,
            sourceActionId: $sourceActionId,
            sourceSkillId: 100 + $sourceActionId,
            removable: true,
            strength: $rate * 100,
        );
    }

    private function prepared(string $key, bool $strictNextAction, int $sourceActionId): JobArtV2PreparedEffectState
    {
        return new JobArtV2PreparedEffectState(
            key: $key,
            multiplier: $strictNextAction ? 1.15 : 1.10,
            appliedRound: 1,
            remainingRounds: $strictNextAction ? null : 3,
            charges: 1,
            sourceActionId: $sourceActionId,
            sourceSkillId: 200 + $sourceActionId,
            targetLineage: 'pierce',
            targetRanks: [5, 9],
            strictNextAction: $strictNextAction,
            group: 'pierce_prep',
        );
    }
}
