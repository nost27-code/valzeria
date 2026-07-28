<?php

namespace Tests\Feature;

use App\Livewire\AdventurerCardModal;
use App\Livewire\CityHeader;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class AdventurerCardModalIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('favorite_weapons.enabled', false);
        config()->set('job_master_badges.enabled', false);
    }

    public function test_only_the_dedicated_component_handles_adventurer_card_events(): void
    {
        $viewer = User::factory()->create();
        $viewerCharacter = $this->createCharacter($viewer, '閲覧者');
        $target = $this->createCharacter(User::factory()->create(), '表示対象');

        $this->actingAs($viewer)
            ->withSession(['current_character_id' => $viewerCharacter->id]);

        $this->assertSame(
            [],
            (new ReflectionMethod(CityHeader::class, 'openPlayerModal'))->getAttributes(On::class)
        );
        $this->assertCount(
            1,
            (new ReflectionMethod(AdventurerCardModal::class, 'openPlayerModal'))->getAttributes(On::class)
        );

        Livewire::test(AdventurerCardModal::class)
            ->assertSet('modalOnly', true)
            ->assertDontSee('現在の冒険者')
            ->assertDontSee('<style>', false)
            ->assertSee('x-on:adventurer-card-loading.window', false)
            ->assertSee('冒険者カードを開いています')
            ->assertSee('<template x-if="selectedJobBadgeTier === tier.rank">', false)
            ->assertSee('loading="lazy"', false)
            ->dispatch('open-adventurer-card', characterId: $target->id)
            ->assertSet('isPlayerModalOpen', true)
            ->assertSet('playerInfo.name', '表示対象')
            ->assertSee('冒険の記録');
    }

    private function createCharacter(User $user, string $name): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
