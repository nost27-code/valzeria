<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterJob;
use App\Models\User;
use App\Services\ExtraContentControlService;
use App\Services\HeroTrialProfileService;
use App\Services\HeroTrialService;
use App\Services\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinalWaveHeroTrialReleaseTest extends TestCase
{
    use RefreshDatabase;

    public static function trials(): array
    {
        return [
            ['azure_dragon_warrior_king', 92, 73, 62, '073', 726],
            ['phantom_funeral_demon_king', 93, 76, 61, '076', 731],
        ];
    }

    #[DataProvider('trials')]
    public function test_masters_arts_and_assets_are_prepared_with_gates_off(string $key, int $area, int $hero, int $crown, string $asset, int $enemy): void
    {
        foreach (['hero_trials', 'hero_trials_second_wave', 'hero_trials_final_wave'] as $gate) {
            $this->assertFalse((bool) config("extra_content.contents.{$gate}.default_enabled"));
        }
        $this->assertDatabaseHas('areas', ['id' => $area, 'city_id' => 10, 'area_kind' => 'hero_trial', 'is_published' => false]);
        $this->assertDatabaseHas('job_classes', ['id' => $hero, 'key' => $key, 'is_active' => true, 'is_hidden' => true]);
        $this->assertDatabaseHas('job_requirements', ['job_id' => $hero, 'required_job_id' => $crown, 'requirement_type' => 'master_job']);
        $this->assertSame(3, \App\Models\Skill::where('job_id', $hero)->where('skill_type', 'job_art')->count());
        foreach (["symbol/hero_trial_{$asset}.webp", "jobbadge/jobbadge_{$asset}.webp", "job_portrait/hero_trial_{$asset}.webp", "enemy/enemy_{$enemy}.webp"] as $path) {
            $this->assertFileExists(public_path('images/'.$path));
        }
        $this->assertSame('hero_trials_final_wave', config("hero_trials.released_trials.{$key}.release_gate"));
    }

    #[DataProvider('trials')]
    public function test_off_gate_blocks_post_and_result_without_creating_progress(string $key, int $area, int $hero, int $crown, string $asset, int $enemy): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_final_wave.default_enabled' => false]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '最終陣OFF検証', 'current_city_id' => 10]);
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->post(route('hero-trials.challenge', ['trialKey' => $key]))
            ->assertRedirect(route('home'))->assertSessionHas('error', 'この英雄試練は現在公開されていません。');
        $this->withSession(['heroTrialData' => ['trial_key' => $key]])
            ->get(route('hero-trials.result', ['trialKey' => $key]))
            ->assertRedirect(route('home'))->assertSessionHas('error', 'この英雄試練は現在公開されていません。');
        $this->postJson(route('hero-trials.rest', ['trialKey' => $key]))
            ->assertStatus(422)->assertJson(['success' => false, 'message' => 'この英雄試練は現在公開されていません。']);
        $this->assertDatabaseMissing('character_area_progresses', ['character_id' => $character->id, 'area_id' => $area]);
    }

    public function test_parent_and_final_periods_are_required_but_second_wave_is_independent(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false,
            'extra_content.contents.hero_trials_final_wave.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false]);
        $control = app(ExtraContentControlService::class);
        $this->assertFalse($control->isActive('hero_trials_final_wave'));
        config(['extra_content.contents.hero_trials.default_enabled' => true]);
        $this->assertTrue($control->isActive('hero_trials_final_wave'));
        $control->setPeriod('hero_trials', '2099-01-01T00:00', null);
        $this->assertFalse($control->isActive('hero_trials_final_wave'));
        $control->setPeriod('hero_trials', null, null);
        $control->setPeriod('hero_trials_final_wave', '2099-01-01T00:00', null);
        $this->assertFalse($control->isActive('hero_trials_final_wave'));
    }

    public function test_cards_become_available_only_after_the_final_gate_opens(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_second_wave.default_enabled' => false,
            'extra_content.contents.hero_trials_final_wave.default_enabled' => false]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '最後の試練カード', 'current_city_id' => 10]);
        \App\Models\Area::updateOrCreate(['id' => 70], ['name' => '終焉の祭壇', 'slug' => 'final_altar', 'city_id' => 10]);
        CharacterAreaProgress::create(['character_id' => $character->id, 'area_id' => 70, 'is_unlocked' => true, 'boss_defeated' => true]);
        foreach ([61, 62] as $job) {
            CharacterJob::create(['character_id' => $character->id, 'job_class_id' => $job, 'job_level' => 10, 'is_mastered' => true]);
        }
        \App\Models\Area::updateOrCreate(['id' => \App\Services\JobService::CROWN_PROOF_AREA_ID], ['name' => '冠位の証', 'slug' => 'crown_proof', 'city_id' => 10]);
        CharacterAreaProgress::create(['character_id' => $character->id, 'area_id' => \App\Services\JobService::CROWN_PROOF_AREA_ID, 'is_unlocked' => true, 'boss_defeated' => true]);
        $service = app(HeroTrialService::class);
        $closed = collect($service->hallFacilitiesFor($character, 10))->keyBy('name');
        foreach (['蒼竜の試練場', '幻葬の試練場'] as $name) {
            $this->assertFalse($closed->has($name));
        }
        $this->assertSame(0, CharacterAreaProgress::where('character_id', $character->id)->whereIn('area_id', [92, 93])->count());
        config(['extra_content.contents.hero_trials_final_wave.default_enabled' => true]);
        $open = collect($service->hallFacilitiesFor($character, 10))->keyBy('name');
        foreach (['蒼竜の試練場', '幻葬の試練場'] as $name) { $this->assertSame('active', $open[$name]['status']); }
        $this->assertSame(2, CharacterAreaProgress::where('character_id', $character->id)->whereIn('area_id', [92, 93])->count());
        $this->assertFalse($open->has('天機の試練場'));
    }

    public function test_final_profiles_use_distinct_trial_masters_and_mechanics(): void
    {
        $profiles = app(HeroTrialProfileService::class);
        $dragon = $profiles->enemies('azure_dragon_warrior_king_candidate')->sole();
        $funeral = $profiles->enemies('phantom_funeral_demon_king_candidate')->sole();
        $this->assertSame('蒼穹古竜アズラギオン', $dragon->name);
        $this->assertSame(['def_pierce', 'multi_hit', 'charge'], $dragon->actions->pluck('action_type')->all());
        $this->assertSame(['竜', '飛行'], $profiles->speciesLabels('azure_dragon_warrior_king_candidate'));
        $this->assertSame('魂葬王ネクロディア', $funeral->name);
        $this->assertSame('magical', $funeral->normal_attack_type);
        $this->assertSame(['magical_spr_down', 'magical_drain', 'magical'], $funeral->actions->pluck('action_type')->all());
        $this->assertSame(['不死', '魔法型'], $profiles->speciesLabels('phantom_funeral_demon_king_candidate'));
        $this->assertTrue((bool) $dragon->actions->last()->is_telegraphed);
        $this->assertTrue((bool) $funeral->actions->last()->is_telegraphed);
        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('hero_trials_final_wave'));
    }
}
