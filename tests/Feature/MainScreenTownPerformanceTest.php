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
            ->assertStatus(200)
            ->assertSee('大陸フィールド')
            ->assertSee('宝箱などは一切ありません。開発中のため、急遽メンテナンスに入る場合があります。')
            ->assertSee('試験公開中')
            ->assertSee('href="'.route('field.show').'"', false)
            ->assertDontSee('href="'.route('field.show').'" wire:navigate', false)
            ->assertDontSee('大陸フィールドは現在準備中です。今後のアップデートで公開予定です。');
    }
}
