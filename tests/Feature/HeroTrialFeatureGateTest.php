<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\JobClass;
use App\Models\User;
use App\Services\HeroTrialService;
use App\Services\JobService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroTrialFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_trial_hall_route_is_closed_while_feature_is_off(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false]);
        $this->withoutMiddleware(CheckCharacterSelected::class);

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

    public function test_hall_visibility_follows_the_crown_job_reveal_proof(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => true]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '冠位未到達者',
            'current_city_id' => 10,
        ]);
        $service = app(HeroTrialService::class);

        $this->assertFalse($service->canViewHall($character, 10));
        $this->assertSame([], $service->facilitiesFor($character, 10));
        $this->assertSame([], $service->hallFacilitiesFor($character, 10));

        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('hero-trials.index'))
            ->assertRedirect(route('home'));

        Area::query()->updateOrCreate(
            ['id' => JobService::CROWN_PROOF_AREA_ID],
            [
                'name' => '北境の霊峰エルヴァン',
                'slug' => 'northern_peak_elvan',
                'city_id' => 10,
            ],
        );
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => JobService::CROWN_PROOF_AREA_ID,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $this->assertTrue($service->canViewHall($character, 10));
        $this->assertFalse($service->canViewHall($character, 9));
        $this->assertSame('英雄試練殿', $service->facilitiesFor($character, 10)[0]['name']);
        $this->assertSame([], $service->facilitiesFor($character, 9));
        $this->assertCount(4, $service->hallFacilitiesFor($character, 10));
    }

    public function test_crown_proof_holder_can_open_the_hall_before_a_specific_trial_is_unlocked(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => true]);
        Area::query()->updateOrCreate(
            ['id' => JobService::CROWN_PROOF_AREA_ID],
            [
                'name' => '北境の霊峰エルヴァン',
                'slug' => 'northern_peak_elvan',
                'city_id' => 10,
            ],
        );
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '冠位表示済み冒険者',
            'current_city_id' => 10,
        ]);
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => JobService::CROWN_PROOF_AREA_ID,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('hero-trials.index'))
            ->assertOk()
            ->assertSeeText('英雄試練殿')
            ->assertSeeText('暁の試練場')
            ->assertSeeText('道は閉ざされている')
            ->assertDontSeeText('準備中');
    }
}
