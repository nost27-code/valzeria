<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterJob;
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

    public function test_second_wave_trial_is_closed_until_its_own_gate_is_active(): void
    {
        config([
            'extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この英雄試練は現在公開されていません。');

        app(HeroTrialService::class)->challenge(new Character, 'heavenly_machina_chancellor');
    }

    public function test_second_wave_result_route_is_closed_until_its_own_gate_is_active(): void
    {
        config([
            'extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false,
        ]);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '第2陣結果確認冒険者',
        ]);

        $this->actingAs($user)
            ->withSession([
                'current_character_id' => $character->id,
                'heroTrialData' => ['trial_key' => 'heavenly_machina_chancellor'],
            ])
            ->get(route('hero-trials.result', ['trialKey' => 'heavenly_machina_chancellor']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'この英雄試練は現在公開されていません。');
    }

    public function test_second_wave_cards_stay_unpublished_until_both_gates_are_active(): void
    {
        config([
            'extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false,
        ]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '第2陣検証冒険者',
            'current_city_id' => 10,
        ]);
        Area::query()->updateOrCreate(
            ['id' => 70],
            ['name' => '終焉の祭壇', 'slug' => 'final_altar', 'city_id' => 10]
        );
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 70,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);
        foreach ([65, 66, 68, 69] as $jobId) {
            CharacterJob::query()->create([
                'character_id' => $character->id,
                'job_class_id' => $jobId,
                'job_level' => 10,
                'is_mastered' => true,
            ]);
        }

        Area::updateOrCreate(['id' => JobService::CROWN_PROOF_AREA_ID], ['name' => '冠位の証', 'slug' => 'crown_proof', 'city_id' => 10]);
        CharacterAreaProgress::create(['character_id' => $character->id, 'area_id' => JobService::CROWN_PROOF_AREA_ID, 'is_unlocked' => true, 'boss_defeated' => true]);
        $service = app(HeroTrialService::class);
        $closed = collect($service->hallFacilitiesFor($character, 10))->keyBy('name');
        foreach (['天機の試練場', '聖域の試練場', '荒天の試練場', '白銀の試練場', '蒼竜の試練場', '幻葬の試練場'] as $name) {
            $this->assertFalse($closed->has($name));
        }
        $this->assertDatabaseMissing('character_area_progresses', ['character_id' => $character->id, 'area_id' => 88]);

        config(['extra_content.contents.hero_trials_second_wave.default_enabled' => true]);
        $open = collect($service->hallFacilitiesFor($character, 10))->keyBy('name');
        foreach (['天機の試練場', '聖域の試練場', '荒天の試練場', '白銀の試練場'] as $name) {
            $this->assertSame('active', $open[$name]['status']);
            $this->assertSame('英雄試練', $open[$name]['badge']);
        }
        $this->assertDatabaseHas('character_area_progresses', [
            'character_id' => $character->id,
            'area_id' => 88,
            'is_unlocked' => true,
        ]);
    }

    public function test_second_wave_clear_keeps_hero_job_visible_after_its_gate_is_disabled(): void
    {
        config([
            'extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false,
        ]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '第2陣達成済み冒険者',
        ]);
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 88,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $heroJob = JobClass::query()->where('key', 'heavenly_machina_chancellor')->firstOrFail();

        $this->assertTrue(app(HeroTrialService::class)->hasClearedForJob($character, $heroJob));
    }
}
