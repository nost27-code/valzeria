<?php

namespace Tests\Feature;

use App\Livewire\AdventurerCardModal;
use App\Livewire\CityHeader;
use App\Models\Character;
use App\Models\JobClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_job_badge_details_are_loaded_only_after_the_tier_is_opened(): void
    {
        config()->set('job_master_badges.enabled', true);

        $job = JobClass::query()->create([
            'key' => 'lazy_badge_job',
            'name' => '遅延読込職',
            'rank' => 'normal',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $target = $this->createCharacter(User::factory()->create(), '職業表示対象');
        $target->update(['current_job_id' => $job->id]);
        DB::table('character_jobs')->insert([
            'character_id' => $target->id,
            'job_class_id' => $job->id,
            'job_level' => 10,
            'job_exp' => 0,
            'is_mastered' => true,
            'mastered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Livewire::test(AdventurerCardModal::class)
            ->dispatch('open-adventurer-card', characterId: $target->id)
            ->assertSet('playerInfo.job_master_badge_tiers.0.jobs', [])
            ->assertSet('playerInfo.job_master_badge_tiers.0.compact_jobs.0.1', '遅延読込職')
            ->assertSet('isPlayerModalOpen', true);

        $jobs = $component->instance()->jobBadgeTierJobs('normal');
        $loadedJob = collect($jobs)->firstWhere('id', $job->id);

        $this->assertSame('遅延読込職', $loadedJob['name']);
        $this->assertSame(10, $loadedJob['job_level']);
        $this->assertTrue($loadedJob['is_mastered']);
    }

    private function createCharacter(User $user, string $name): Character
    {
        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
