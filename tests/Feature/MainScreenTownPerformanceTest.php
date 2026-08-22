<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\User;
use App\Services\TownRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MainScreenTownPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_town_does_not_load_the_expired_ranking_spotlight(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 22, 14, 30, 0, 'Asia/Tokyo'));

        $rankings = Mockery::mock(TownRankingService::class);
        $rankings->shouldNotReceive('boards');
        $this->app->instance(TownRankingService::class, $rankings);

        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreen::class, ['fixedLocation' => 'town'])
            ->assertStatus(200);
    }
}
