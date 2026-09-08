<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\JobClass;
use App\Models\User;
use App\Services\HeroTrialService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroTrialFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_trial_hall_route_is_closed_while_feature_is_off(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false]);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);

        $this->actingAs(User::factory()->create())
            ->get(route('hero-trials.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', '英雄試練は現在公開されていません。');
    }

    public function test_hero_trials_are_closed_by_default(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false]);

        $service = app(HeroTrialService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertSame([], $service->facilitiesFor(new Character, 10));
        $this->assertSame([], $service->trialFacilitiesFor(new Character, 10));
        $this->assertSame([], $service->hallFacilitiesFor(new Character, 10));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('英雄試練は現在公開されていません。');

        $service->challenge(new Character, 'dawn_hero');
    }

    public function test_a_saved_hero_job_unlock_remains_valid_while_new_challenges_are_off(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '試練達成済み冒険者',
        ]);
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 84,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $heroJob = JobClass::query()->where('key', 'dawn_hero')->firstOrFail();

        $this->assertTrue(app(HeroTrialService::class)->hasClearedForJob($character, $heroJob));
    }
}
