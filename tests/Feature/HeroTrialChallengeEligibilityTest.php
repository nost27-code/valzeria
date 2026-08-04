<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialProfileService;
use App\Services\HeroTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HeroTrialChallengeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['extra_content.contents.hero_trials.default_enabled' => true]);
    }

    public function test_mastered_player_can_challenge_dawn_trial_without_switching_back_to_crown_sword_knight(): void
    {
        Area::query()->updateOrCreate(
            ['id' => 70],
            ['name' => '終焉の祭壇', 'slug' => 'final_altar', 'city_id' => 10]
        );
        Area::query()->updateOrCreate(
            ['id' => 84],
            ['name' => '暁の試練場', 'slug' => 'dawn_hero_trial', 'city_id' => 10]
        );

        $requiredJob = JobClass::query()->firstOrCreate(
            ['key' => 'crown_sword_knight'],
            ['name' => '剣冠騎士', 'rank' => 'crown']
        );
        $currentJob = JobClass::query()->firstOrCreate(
            ['key' => 'thunder_fist_overlord'],
            ['name' => '雷拳覇', 'rank' => 'super']
        );
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'かんりにん',
            'current_job_id' => $currentJob->id,
            'current_city_id' => 10,
            'current_hp' => 100,
            'current_mp' => 50,
        ]);
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $requiredJob->id,
            'job_level' => 10,
            'is_mastered' => true,
            'mastered_at' => now(),
        ]);
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 70,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $profileService = Mockery::mock(HeroTrialProfileService::class);
        $profileService->shouldReceive('profile')
            ->once()
            ->with('dawn_hero_balanced')
            ->andReturn(['phases' => [['key' => 'sword_phase']]]);
        $profileService->shouldReceive('enemies')
            ->once()
            ->with('dawn_hero_balanced')
            ->andReturn(collect([new Enemy(['name' => '双極天騎アウローラ'])]));
        $battleResult = new BattleResult;
        $battleResult->result = 'defeat';
        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')
            ->once()
            ->andReturn($battleResult);

        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')
            ->atLeast()
            ->once()
            ->andReturn(['max_hp' => 100, 'max_mp' => 50]);

        $service = new HeroTrialService($profileService, $battleService, $statusService);
        $facility = $service->facilityFor($character, 10);

        $this->assertSame('試練を選ぶ', $facility['action']);
        $this->assertSame('hero-trials.index', $facility['route']);
        $this->assertArrayNotHasKey('details', $facility);

        $outcome = $service->challenge($character, 'dawn_hero');

        $this->assertFalse($outcome['passed']);
        $this->assertSame('雷拳覇', $character->currentJob->name);
    }
}
