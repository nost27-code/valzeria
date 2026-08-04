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
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialProfileService;
use App\Services\HeroTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class BlackMoonHeroTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['extra_content.contents.hero_trials.default_enabled' => true]);
    }

    public function test_black_moon_trial_master_rows_are_released(): void
    {
        $this->assertDatabaseHas('areas', [
            'id' => 85,
            'city_id' => 10,
            'slug' => 'black_moon_hero_trial',
            'area_kind' => 'hero_trial',
        ]);
        $this->assertDatabaseHas('job_classes', [
            'id' => 71,
            'key' => 'black_moon_executor',
            'is_active' => true,
            'is_hidden' => true,
        ]);
        $this->assertDatabaseHas('job_requirements', [
            'job_id' => 71,
            'requirement_type' => 'master_job',
            'required_job_id' => 64,
        ]);
        $this->assertFileExists(public_path('images/symbol/hero_trial_071.webp'));
        $this->assertFileExists(public_path('images/jobbadge/jobbadge_071.webp'));
    }

    public function test_shadow_crown_master_can_clear_black_moon_trial_with_any_current_job(): void
    {
        $this->ensureAppearanceAreaExists();

        $requiredJob = JobClass::query()->where('key', 'shadow_crown_hunter')->firstOrFail();
        $currentJob = JobClass::query()->where('key', 'thunder_fist_overlord')->firstOrFail();
        $heroJob = JobClass::query()->where('key', 'black_moon_executor')->firstOrFail();
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '月影試練者',
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

        $battleResult = new BattleResult;
        $battleResult->result = 'victory';
        $battleResult->turnCount = 8;
        $battleResult->logs = ['【戦闘開始】月影試練者 は 月喰影獣ルナグリム と遭遇した！'];
        $battleResult->playerHpAfter = 35;
        $battleResult->playerMpAfter = 20;

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')
            ->once()
            ->withArgs(fn (Character $challenger, Enemy $enemy, int $bonus, array $options): bool =>
                $challenger->is($character)
                && $enemy->name === '月喰影獣ルナグリム'
                && $bonus === 0
                && $options === ['rewards_enabled' => false])
            ->andReturn($battleResult);

        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')
            ->atLeast()
            ->once()
            ->andReturn(['max_hp' => 100, 'max_mp' => 50]);

        $service = new HeroTrialService(
            app(HeroTrialProfileService::class),
            $battleService,
            $statusService,
        );

        $facility = $service->trialFacilitiesFor($character, 10)[0];
        $this->assertSame('月蝕の試練場', $facility['name']);
        $this->assertSame('月蝕の試練に挑む', $facility['action']);
        $this->assertArrayNotHasKey('details', $facility);

        $outcome = $service->challenge($character, 'black_moon_executor');

        $this->assertTrue($outcome['passed']);
        $this->assertSame('雷拳覇', $character->currentJob->name);
        $this->assertTrue($service->hasClearedForJob($character, $heroJob));
        $this->assertDatabaseHas('character_area_progresses', [
            'character_id' => $character->id,
            'area_id' => 85,
            'boss_defeated' => true,
        ]);
    }

    public function test_both_unlocked_trials_are_grouped_under_one_hall_facility(): void
    {
        $this->ensureAppearanceAreaExists();

        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '双試練者',
            'current_job_id' => JobClass::query()->where('key', 'thunder_fist_overlord')->value('id'),
            'current_city_id' => 10,
            'current_hp' => 100,
            'current_mp' => 50,
        ]);
        foreach (['crown_sword_knight', 'shadow_crown_hunter'] as $jobKey) {
            CharacterJob::query()->create([
                'character_id' => $character->id,
                'job_class_id' => JobClass::query()->where('key', $jobKey)->value('id'),
                'job_level' => 10,
                'is_mastered' => true,
                'mastered_at' => now(),
            ]);
        }
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 70,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->andReturn(['max_hp' => 100, 'max_mp' => 50]);
        $service = new HeroTrialService(
            app(HeroTrialProfileService::class),
            Mockery::mock(BattleService::class),
            $statusService,
        );

        $hallFacility = $service->facilitiesFor($character, 10)[0];
        $this->assertSame('英雄試練殿', $hallFacility['name']);
        $this->assertSame('symbol/hero_trial_hall.webp', $hallFacility['symbol_image']);
        $this->assertSame(
            ['暁の試練場', '月蝕の試練場'],
            collect($service->trialFacilitiesFor($character, 10))->pluck('name')->all(),
        );
        $hallFacilities = $service->hallFacilitiesFor($character, 10);
        $this->assertCount(10, $hallFacilities);
        $this->assertSame('白銀の試練場', $hallFacilities[9]['name']);
        $this->assertSame('試練に挑む', $hallFacilities[0]['action']);
        $this->assertSame('試練に挑む', $hallFacilities[1]['action']);
        $this->assertSame('準備中', $hallFacilities[2]['action']);

        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('hero-trials.index'))
            ->assertOk()
            ->assertSee('images/symbol/hero_trial_hall.webp', false)
            ->assertSeeText('英雄試練殿')
            ->assertSeeText('暁の試練場')
            ->assertSeeText('月蝕の試練場')
            ->assertSeeText('白銀の試練場')
            ->assertDontSeeText('挑戦職: すべての職業')
            ->assertDontSeeText('試練主の種族')
            ->assertDontSeeText('剣相から術相へHP/SPを引き継いで連戦');
    }

    public function test_deepening_eclipse_is_locked_until_turn_six(): void
    {
        $enemy = app(HeroTrialProfileService::class)
            ->enemies('black_moon_executor_balanced')
            ->sole();
        $action = $enemy->actions->firstWhere('action_key', 'deepening_eclipse');
        $enemyActor = new BattleActor($enemy->name, false, [
            'max_hp' => $enemy->max_hp,
            'str' => $enemy->str,
            'def' => $enemy->def,
            'agi' => $enemy->agi,
            'mag' => $enemy->mag,
            'spr' => $enemy->spr,
            'luk' => $enemy->luk,
        ], $enemy);
        $playerActor = new BattleActor('挑戦者', true, [
            'max_hp' => 100_000,
            'str' => 10_000,
            'def' => 10_000,
            'agi' => 10_000,
            'mag' => 10_000,
            'spr' => 10_000,
            'luk' => 10_000,
        ]);
        $state = new BattleState($playerActor, $enemyActor, 'boss');
        $method = new ReflectionMethod(BattleService::class, 'canUseEnemyAction');

        $state->turnCount = 5;
        $this->assertFalse($method->invoke(app(BattleService::class), $action, $state, $enemyActor));
        $state->turnCount = 6;
        $this->assertTrue($method->invoke(app(BattleService::class), $action, $state, $enemyActor));
    }

    private function ensureAppearanceAreaExists(): void
    {
        Area::query()->updateOrCreate(
            ['id' => 70],
            [
                'city_id' => 10,
                'name' => '終焉の祭壇',
                'slug' => 'final_altar',
                'description' => '英雄試練の出現確認用エリア',
                'is_published' => true,
            ],
        );
    }
}
