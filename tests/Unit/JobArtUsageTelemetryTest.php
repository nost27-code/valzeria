<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use PHPUnit\Framework\TestCase;

class JobArtUsageTelemetryTest extends TestCase
{
    public function test_state_aggregates_actual_job_art_activations_and_resolutions_per_actor(): void
    {
        $player = new BattleActor('冒険者', true, [
            'hp' => 50,
            'max_hp' => 100,
            'mp' => 10,
            'max_mp' => 20,
        ]);
        $enemy = new BattleActor('敵', false, []);
        $state = new BattleState($player, $enemy);
        $art = new Skill(['name' => '計測の構え']);
        $art->id = 101;
        $player->jobArtOrigins[101] = 'inherited';

        $state->beginSourceAction();
        $state->recordJobArtActivation($player, $art);
        $player->healHp(20);
        $player->mp += 5;
        $state->completeJobArtActivation($player, HitResult::HIT, true);
        $state->completeJobArtRecoveryObservation($player);
        $state->beginSourceAction();
        $state->recordJobArtActivation($player, $art);
        $state->completeJobArtActivation($player, HitResult::EVADE, true);
        $state->beginSourceAction();
        $state->recordJobArtActivation($enemy, $art);
        $state->completeJobArtActivation($enemy, null);

        $this->assertSame([[
            'skill_id' => 101,
            'name' => '計測の構え',
            'origin' => 'inherited',
            'activation_count' => 2,
            'hit_count' => 1,
            'miss_count' => 0,
            'evade_count' => 1,
            'no_resolution_count' => 0,
            'vital_hit_count' => 1,
            'hp_recovered' => 20,
            'sp_recovered' => 5,
        ]], $state->jobArtUsageFor($player));
        $this->assertSame(1, $state->jobArtUsageFor($enemy)[0]['no_resolution_count']);
    }

    public function test_state_snapshots_the_ordered_job_art_loadout(): void
    {
        $player = new BattleActor('冒険者', true, []);
        $enemy = new BattleActor('敵', false, []);
        $state = new BattleState($player, $enemy);
        $opening = new Skill(['name' => '始動']);
        $opening->id = 11;
        $link = new Skill(['name' => '連携']);
        $link->id = 12;
        $player->jobArts = [$opening, $link];
        $player->jobArtOrigins = [11 => 'current', 12 => 'inherited'];

        $this->assertSame([
            ['slot_no' => 1, 'skill_id' => 11, 'name' => '始動', 'origin' => 'current'],
            ['slot_no' => 2, 'skill_id' => 12, 'name' => '連携', 'origin' => 'inherited'],
        ], $state->jobArtLoadoutFor($player));
    }
}
