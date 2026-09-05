<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Battle\BattleActor;
use App\Services\Nation\Raid\NationRaidTelegraphPreparationState;
use PHPUnit\Framework\TestCase;

final class NationRaidIsolationTest extends TestCase
{
    public function test_raid_preparation_does_not_touch_existing_guard_counter_or_timed_states(): void
    {
        $boss = new BattleActor('ヴァルグレイド', false, []);
        $preparation = new NationRaidTelegraphPreparationState(
            preparationId: 'prep-1',
            pendingEnemyActionId: 'pending-1',
            kind: 'reflect',
            sourceCycleId: 'cycle-1',
            createdTurn: 5,
            expiresOn: 7,
        );

        $this->assertTrue($preparation->isActive());
        $this->assertNull($boss->jobArtV2GuardState());
        $this->assertNull($boss->counterStanceState());
        $this->assertSame([], $boss->jobArtV2TimedEffects());
    }

    public function test_raid_preparation_has_only_raid_lifecycle_fields_and_no_hud_integration(): void
    {
        $preparation = new NationRaidTelegraphPreparationState(
            preparationId: 'prep-1',
            pendingEnemyActionId: 'pending-1',
            kind: 'cleanse_guard',
            sourceCycleId: 'cycle-1',
            createdTurn: 11,
            expiresOn: 13,
        );
        $this->assertSame([
            'preparation_id', 'pending_enemy_action_id', 'kind', 'source_cycle_id', 'created_turn',
            'expires_on', 'destroyed', 'active', 'cleared_reason',
        ], array_keys($preparation->toArray()));

        $hudSource = file_get_contents(dirname(__DIR__, 5).'/app/Services/JobArtV2BattleHudService.php');
        $this->assertIsString($hudSource);
        $this->assertStringNotContainsString('NationRaidTelegraphPreparationState', $hudSource);
        $this->assertStringNotContainsString('raid_telegraph_preparation', $hudSource);
    }

    public function test_preparation_keeps_pending_id_through_delay_then_records_cleanup_reason(): void
    {
        $preparation = new NationRaidTelegraphPreparationState(
            preparationId: 'prep-1',
            pendingEnemyActionId: 'pending-1',
            kind: 'reflect',
            sourceCycleId: 'cycle-1',
            createdTurn: 5,
            expiresOn: 7,
        );

        $beforeDelay = $preparation->pendingEnemyActionId;
        $this->assertSame($beforeDelay, $preparation->pendingEnemyActionId);
        $preparation->clear('executed');
        $this->assertFalse($preparation->isActive());
        $this->assertSame('executed', $preparation->clearedReason());
        $this->assertSame($beforeDelay, $preparation->pendingEnemyActionId);
    }

    public function test_all_contractual_cleanup_paths_are_explicit(): void
    {
        foreach (['executed', 'replacement', 'suppressed', 'battle_end'] as $reason) {
            $preparation = new NationRaidTelegraphPreparationState(
                preparationId: 'prep-'.$reason,
                pendingEnemyActionId: 'pending-'.$reason,
                kind: 'reflect',
                sourceCycleId: 'cycle-1',
                createdTurn: 5,
                expiresOn: 7,
            );
            $preparation->clear($reason);
            $this->assertFalse($preparation->isActive(), $reason);
            $this->assertSame($reason, $preparation->clearedReason(), $reason);
        }
    }
}
