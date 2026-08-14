<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\FieldEvent;
use App\Services\FieldState;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2PrototypeCatalog;
use Tests\TestCase;

class JobArtV2FieldServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableFields();
    }

    public function test_fields_are_default_off_and_fail_closed_on_every_dependency_and_participant(): void
    {
        // Raw config defaults are environment-dependent in the local smoke
        // worktree. The feature contract is the explicit fail-closed gate.
        config(['battle.job_art_v2.fields' => false]);
        [, , $defaultOff] = $this->battle(24, 62);
        $this->assertFalse($this->service()->enabledFor($defaultOff));

        [, , $state] = $this->battle(24, 62);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources', 'fields'] as $flag) {
            $this->enableFields();
            config(["battle.job_art_v2.{$flag}" => false]);
            $this->assertFalse($this->service()->enabledFor($state), $flag);
        }

        $this->enableFields();
        [, , $unsupported] = $this->battle(39, 40);
        $this->assertFalse($this->service()->enabledFor($unsupported));
        $this->assertTrue($this->service()->enabledFor($state));
    }

    public function test_primary_and_overlay_start_null_and_are_transient(): void
    {
        [, , $state] = $this->battle();

        $this->assertNull($state->primaryField());
        $this->assertNull($state->fieldOverlay());
        $this->assertArrayNotHasKey('field', get_object_vars($state));
    }

    public function test_create_refresh_and_overwrite_follow_the_frozen_lifecycle(): void
    {
        [$player, $enemy, $state] = $this->battle();
        $state->turnCount = 1;

        $created = $this->service()->deployPrimary($player, $state, 'star_light', 531, 1);
        $this->assertSame(FieldEvent::CREATED, $created->event);
        $this->assertSame(3, $state->primaryField()?->remainingRounds);
        $this->assertSame('player', $state->primaryField()?->ownerActorKey);

        $this->service()->extendPrimary($player, $state, 535, 2);
        $state->turnCount = 2;
        $refreshed = $this->service()->deployPrimary($enemy, $state, 'star_light', 851, 3);
        $this->assertSame(FieldEvent::REFRESHED, $refreshed->event);
        $this->assertSame(3, $state->primaryField()?->remainingRounds);
        $this->assertSame('enemy', $state->primaryField()?->ownerActorKey);
        $this->assertSame(2, $state->primaryField()?->createdRound);
        $this->assertSame(1, $state->primaryField()?->extends);

        $overwritten = $this->service()->deployPrimary($player, $state, 'sanctuary', 241, 4);
        $this->assertSame(FieldEvent::OVERWRITTEN, $overwritten->event);
        $this->assertSame(0, $state->primaryField()?->extends);
        $events = array_map(static fn ($event): FieldEvent => $event->event, $state->fieldEvents());
        $this->assertNotContains(FieldEvent::EXPIRED, $events);
    }

    public function test_created_round_does_not_decrement_and_natural_zero_expires_once(): void
    {
        [$player, , $state] = $this->battle();
        $state->turnCount = 1;
        $this->service()->deployPrimary($player, $state, 'sanctuary', 241, 1);

        $this->service()->endRound($state);
        $this->assertSame(3, $state->primaryField()?->remainingRounds);
        foreach ([2 => 2, 3 => 1] as $round => $remaining) {
            $state->turnCount = $round;
            $this->service()->endRound($state);
            $this->assertSame($remaining, $state->primaryField()?->remainingRounds);
        }
        $state->turnCount = 4;
        $results = $this->service()->endRound($state);

        $this->assertNull($state->primaryField());
        $this->assertCount(1, $results);
        $this->assertSame(FieldEvent::EXPIRED, $results[0]->event);
        $this->assertSame(1, count(array_filter(
            $state->fieldEvents(),
            static fn ($event): bool => $event->event === FieldEvent::EXPIRED,
        )));
    }

    public function test_extension_is_plus_two_capped_at_eight_and_can_be_reused(): void
    {
        [$player, , $state] = $this->battle();
        $state->turnCount = 1;
        $none = $this->service()->extendPrimary($player, $state, 535, 1);
        $this->assertFalse($none->applied);
        $this->assertCount(0, $state->fieldEvents());

        $this->service()->deployPrimary($player, $state, 'star_light', 531, 2);
        $extended = $this->service()->extendPrimary($player, $state, 535, 3, 2);
        $this->assertSame(FieldEvent::EXTENDED, $extended->event);
        $this->assertSame(5, $state->primaryField()?->remainingRounds);
        $this->assertSame(1, $state->primaryField()?->extends);
        $this->assertSame(FieldEvent::EXTENDED, $this->service()->extendPrimary($player, $state, 535, 4, 2)->event);
        $this->assertSame(7, $state->primaryField()?->remainingRounds);
        $this->assertSame(2, $state->primaryField()?->extends);

        $state->turnCount = 2;
        $this->service()->deployPrimary($player, $state, 'sanctuary', 241, 5);
        $this->assertSame(0, $state->primaryField()?->extends);
        $this->service()->extendPrimary($player, $state, 535, 6, 2);
        $this->assertLessThanOrEqual(8, $state->primaryField()?->remainingRounds);

        $state->replacePrimaryField(new FieldState('sanctuary', 'player', 7, 241, 7, 2));
        $this->service()->extendPrimary($player, $state, 535, 8, 2);
        $this->assertSame(8, $state->primaryField()?->remainingRounds);
        $this->assertFalse($this->service()->extendPrimary($player, $state, 535, 9)->applied);
    }

    public function test_lock_blocks_overwrite_and_ownership_transfer_but_allows_owner_refresh_and_extension(): void
    {
        [$player, $enemy, $state] = $this->battle(85, 53);
        $state->turnCount = 1;
        $service = $this->service();
        $service->deployPrimary($player, $state, 'star_light', 851, 1);

        $locked = $service->lockPrimary($player, $state, 855, 2);
        $this->assertSame(FieldEvent::LOCKED, $locked->event);
        $this->assertSame(2, $state->primaryField()?->overwriteLockRemainingRounds);
        $this->assertSame(3, $state->primaryField()?->remainingRounds);
        $this->assertSame(FieldEvent::OVERWRITE_BLOCKED, $service->deployPrimary($enemy, $state, 'sanctuary', 241, 3)->event);
        $this->assertSame(FieldEvent::OVERWRITE_BLOCKED, $service->deployPrimary($enemy, $state, 'star_light', 531, 4)->event);
        $this->assertSame('player', $state->primaryField()?->ownerActorKey);
        $this->assertSame(FieldEvent::REFRESHED, $service->deployPrimary($player, $state, 'star_light', 851, 5)->event);
        $this->assertSame(FieldEvent::EXTENDED, $service->extendPrimary($enemy, $state, 535, 6)->event);

        $refreshedLock = $service->lockPrimary($player, $state, 855, 7);
        $this->assertSame(FieldEvent::LOCK_REFRESHED, $refreshedLock->event);
        $this->assertSame(2, $state->primaryField()?->overwriteLockRemainingRounds);
        $this->assertSame(4, $state->primaryField()?->remainingRounds);

        $service->endRound($state);
        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(1, $state->primaryField()?->overwriteLockRemainingRounds);
        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(0, $state->primaryField()?->overwriteLockRemainingRounds);
        $this->assertNotNull($state->primaryField());

        $state->turnCount = 4;
        $service->endRound($state);
        $state->turnCount = 5;
        $service->endRound($state);
        $this->assertNull($state->primaryField());
    }

    public function test_overlay_is_independent_and_expires_only_at_the_next_round_end(): void
    {
        [$player, , $state] = $this->battle(85, 53);
        $state->turnCount = 1;
        $service = $this->service();
        $service->deployPrimary($player, $state, 'star_light', 851, 1);
        $primary = $state->primaryField();

        $created = $service->createOverlay($player, $state, 'melody', 859, 2);
        $this->assertSame(FieldEvent::OVERLAY_CREATED, $created->event);
        $this->assertEquals($primary, $state->primaryField());
        $this->assertSame('melody', $state->fieldOverlay()?->key);
        $service->endRound($state);
        $this->assertNotNull($state->fieldOverlay());

        $state->turnCount = 2;
        $expired = $service->endRound($state);
        $this->assertNull($state->fieldOverlay());
        $this->assertSame(FieldEvent::OVERLAY_EXPIRED, $expired[0]->event);
        $events = array_map(static fn ($event): FieldEvent => $event->event, $state->fieldEvents());
        $this->assertNotContains(FieldEvent::OVERWRITTEN, $events);
        $this->assertSame(0, count(array_filter($events, static fn (FieldEvent $event): bool => $event === FieldEvent::EXPIRED)));
    }

    public function test_catalog_contains_only_the_frozen_nine_field_operations(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $expected = [
            24 => [1 => ['deploy', 'sanctuary'], 5 => ['none', null], 9 => ['none', null]],
            53 => [1 => ['deploy', 'star_light'], 5 => ['extend', null], 9 => ['none', null]],
            85 => [1 => ['deploy', 'star_light'], 5 => ['lock', null], 9 => ['overlay', 'melody']],
        ];

        foreach ($expected as $jobId => $ranks) {
            foreach ($ranks as $rank => [$operation, $fieldKey]) {
                $metadata = $catalog->artFieldMetadata($this->art($jobId, $rank));
                $this->assertSame($operation, $metadata['field_operation']);
                $this->assertSame($fieldKey, $metadata['field_key'] ?? null);
            }
        }
        $this->assertNull($catalog->artFieldMetadata($this->art(62, 1)));
    }

    public function test_field_service_does_not_consume_rng(): void
    {
        [$player, , $state] = $this->battle();
        $state->turnCount = 1;
        mt_srand(9909);
        $expected = mt_rand();

        mt_srand(9909);
        $this->service()->deployPrimary($player, $state, 'star_light', 531, 1);
        $this->service()->extendPrimary($player, $state, 535, 2);
        $this->service()->lockPrimary($player, $state, 855, 3);
        $this->service()->createOverlay($player, $state, 'melody', 859, 4);
        $this->service()->endRound($state);

        $this->assertSame($expected, mt_rand());
    }

    private function enableFields(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    private function service(): JobArtV2FieldService
    {
        return app(JobArtV2FieldService::class);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(?int $playerJob = 53, ?int $enemyJob = 24): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy)];
    }

    private function actor(string $name, bool $isPlayer, ?int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'mag' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => 'MAGICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }
}
