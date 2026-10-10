<?php
namespace Tests\Feature;

use App\Models\{Character, User, Area};
use App\Services\{ExplorationReturnTransactionRunner, ExplorationStateService};
use App\Http\Middleware\CheckCharacterSelected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationReturnContentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_return_preserves_session_and_exploration_until_success(): void
    {
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => '帰還試験', 'money' => 999]);
        $state = app(ExplorationStateService::class)->getOrStart($hero, Area::firstOrFail()->id);
        $before = $state->fresh()->getRawOriginal();
        $runner = \Mockery::mock(ExplorationReturnTransactionRunner::class);
        $runner->shouldReceive('run')->once()->andReturn(['success' => false, 'message' => '探索処理が進行中です。少し待ってから、もう一度帰還してください。']);
        $this->app->instance(ExplorationReturnTransactionRunner::class, $runner);
        $this->actingAs($hero->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->withSession(['current_character_id' => $hero->id, 'current_location' => 'dungeon', 'lastBattleData' => ['sentinel' => 1], 'exploration_selected_count.'.$hero->id => 50])
            ->post(route('battle.return'))->assertRedirect(route('battle.resume'))->assertSessionHas('error')
            ->assertSessionHas('lastBattleData', ['sentinel' => 1])->assertSessionHas('current_location', 'dungeon');
        $this->assertSame($before, $state->fresh()->getRawOriginal());
        $this->assertSame(999, (int) $hero->fresh()->money);
        $this->app->forgetInstance(ExplorationReturnTransactionRunner::class);
        $this->post(route('battle.return'))->assertRedirect(route('home', ['skip_resume' => 1]))
            ->assertSessionMissing('lastBattleData')->assertSessionHas('current_location', 'town');
        $this->assertFalse(app(ExplorationStateService::class)->hasActiveExploration($hero));
    }
}
