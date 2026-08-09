<?php

namespace Tests\Feature;

use App\Http\Controllers\JobArtController;
use App\Models\Character;
use App\Models\User;
use App\Services\JobArtService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class JobArtPvpContextValidationTest extends TestCase
{
    public function test_flag_off_rejects_direct_pvp_slot_save_request(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'battle.job_art_v2.pvp_set' => false,
        ]);

        $character = new Character(['id' => 1]);
        $character->exists = true;
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'role' => 'player']);
        $user->exists = true;
        $user->shouldReceive('currentCharacter')->once()->andReturn($character);
        $this->actingAs($user);

        $this->mock(JobArtService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('slotContexts')->once()->andReturn(['normal', 'boss']);
            $mock->shouldReceive('maxSlots')->once()->andReturn(3);
            $mock->shouldNotReceive('setSlot');
        });

        $this->app['router']
            ->post('/_test/job-arts/slot', [JobArtController::class, 'slotSet'])
            ->middleware('web');

        $this->post('/_test/job-arts/slot', [
            'slot_context' => 'pvp',
            'slot_no' => 1,
            'skill_id' => null,
        ])
            ->assertSessionHasErrors('slot_context');
    }

    public function test_flag_off_rejects_direct_pvp_assign_request(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'battle.job_art_v2.pvp_set' => false,
        ]);

        $character = new Character(['id' => 1]);
        $character->exists = true;
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'role' => 'player']);
        $user->exists = true;
        $user->shouldReceive('currentCharacter')->once()->andReturn($character);
        $this->actingAs($user);

        $this->mock(JobArtService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('slotContexts')->once()->andReturn(['normal', 'boss']);
            $mock->shouldReceive('maxSlots')->once()->andReturn(3);
            $mock->shouldNotReceive('assignToSlot');
        });

        $this->app['router']
            ->post('/_test/job-arts/assign', [JobArtController::class, 'assign'])
            ->middleware('web');

        $this->post('/_test/job-arts/assign', [
            'slot_context' => 'pvp',
            'slot_no' => 1,
            'skill_id' => 101,
        ])
            ->assertSessionHasErrors('slot_context');
    }
}
