<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterJob;
use App\Models\JobClass;
use App\Models\User;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialService;
use App\Services\JobService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use Tests\TestCase;

class FinalWaveHeroTrialBattleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'extra_content.contents.hero_trials.default_enabled' => true,
            'extra_content.contents.hero_trials_final_wave.default_enabled' => true,
        ]);
        (new ReflectionMethod(BattleService::class, 'useScopedBattleRandomizer'))
            ->invoke(app(BattleService::class), new Randomizer(new Mt19937(20261006)));
    }

    public static function trials(): array
    {
        return [
            '蒼竜' => ['azure_dragon_warrior_king', 1],
            '幻葬' => ['phantom_funeral_demon_king', 1],
        ];
    }

    #[DataProvider('trials')]
    public function test_real_battle_unlocks_only_its_hero_without_rewards(string $trialKey, int $phaseCount): void
    {
        $character = $this->eligibleCharacter($trialKey);
        if ($phaseCount > 1) {
            // 敵に先手を渡し、実消耗後のHPを次段へ持ち越す。
            $character->update(['speed_base' => 1]);
            CharacterStatusService::clearRequestCache((int) $character->id);
        }
        $service = app(HeroTrialService::class);
        $trial = $service->trial($trialKey);
        $job = JobClass::query()->where('key', $trial['hero_job_key'])->firstOrFail();
        $before = $character->fresh()->only(['level', 'exp', 'money', 'bank_gold', 'current_job_id']);
        $jobsBefore = $character->jobHistories()->get()->toArray();
        $itemsBefore = DB::table('character_items')->where('character_id', $character->id)->count();
        $this->assertFalse(app(JobService::class)->canRevealJob($character, $job));

        $outcome = $service->challenge($character, $trialKey);

        $this->assertTrue($outcome['passed']);
        $this->assertCount($phaseCount, $outcome['phase_results']);
        if ($phaseCount > 1) {
            $first = $outcome['phase_results'][0]['result'];
            $this->assertLessThan($first->playerHpBefore, $first->playerHpAfter);
        }
        $turnOffset = 0;
        foreach ($outcome['phase_results'] as $index => $phase) {
            $result = $phase['result'];
            $this->assertSame('victory', $result->result);
            $this->assertSame([0, 0, 0, []], [$result->exp, $result->gold, $result->jobExp, $result->drops]);
            if ($index > 0) {
                $previous = $outcome['phase_results'][$index - 1]['result'];
                $this->assertSame($previous->playerHpAfter, $result->playerHpBefore);
                $this->assertSame($previous->playerMpAfter, $result->playerMpBefore);
                $this->assertStringContainsString('--- ターン '.($turnOffset + 1).' ---', implode("\n", $phase['display_logs']));
            }
            $turnOffset += $result->turnCount;
        }
        $lastResult = $outcome['phase_results'][$phaseCount - 1]['result'];
        $this->assertSame($lastResult->playerHpAfter, (int) $character->fresh()->current_hp);
        $this->assertSame($lastResult->playerMpAfter, (int) $character->fresh()->current_mp);
        $this->assertSame($before, $character->fresh()->only(array_keys($before)));
        $this->assertSame($jobsBefore, $character->jobHistories()->get()->toArray());
        $this->assertSame($itemsBefore, DB::table('character_items')->where('character_id', $character->id)->count());
        $this->assertSame(1, CharacterAreaProgress::query()->where('character_id', $character->id)
            ->whereIn('area_id', [92, 93])->where('boss_defeated', true)->count());
        $this->assertTrue($service->hasClearedForJob($character, $job));

        config([
            'extra_content.contents.hero_trials.default_enabled' => false,
            'extra_content.contents.hero_trials_final_wave.default_enabled' => false,
        ]);
        $this->assertTrue(app(JobService::class)->canChangeJob($character->fresh(), $job->fresh()));
    }

    #[DataProvider('trials')]
    public function test_real_defeat_does_not_unlock_the_job(string $trialKey, int $phaseCount): void
    {
        $character = $this->eligibleCharacter($trialKey, false);
        $before = $character->fresh()->only(['level', 'exp', 'money', 'bank_gold']);
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $outcome = app(HeroTrialService::class)->challenge($character, $trialKey);

        $this->assertFalse($outcome['passed']);
        $this->assertCount(1, $outcome['phase_results']);
        $result = $outcome['phase_results'][0]['result'];
        $this->assertSame('defeat', $result->result);
        // 敗北時は既存BattleServiceどおりHP30%、SP10%で復帰する。
        $this->assertSame(max(1, (int) ($stats['max_hp'] * 0.3)), $result->playerHpAfter);
        $this->assertSame((int) ($stats['max_mp'] * 0.1), $result->playerMpAfter);
        $this->assertSame($result->playerHpAfter, (int) $character->fresh()->current_hp);
        $this->assertSame($result->playerMpAfter, (int) $character->fresh()->current_mp);
        $this->assertSame([0, 0, 0, []], [$result->exp, $result->gold, $result->jobExp, $result->drops]);
        $this->assertSame($before, $character->fresh()->only(array_keys($before)));
        $this->assertDatabaseHas('character_area_progresses', [
            'character_id' => $character->id, 'area_id' => app(HeroTrialService::class)->trial($trialKey)['area_id'], 'boss_defeated' => false,
        ]);
    }

    #[DataProvider('trials')]
    public function test_ordinary_boss_route_cannot_enter_trial_areas(string $trialKey, int $phaseCount): void
    {
        $character = $this->eligibleCharacter($trialKey);
        $areaId = app(HeroTrialService::class)->trial($trialKey)['area_id'];
        $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->post(route('battle.boss', ['area' => $areaId]), ['exploration_request_id' => (string) Str::uuid()])
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'このエリアにはまだ入れません。');
        $this->assertDatabaseMissing('character_area_progresses', [
            'character_id' => $character->id, 'area_id' => $areaId,
        ]);
    }

    public function test_low_power_is_not_an_entry_gate_but_full_hp_sp_is_required(): void
    {
        $character = $this->eligibleCharacter('azure_dragon_warrior_king', false);
        $character->update(['current_mp' => max(0, (int) $character->current_mp - 1)]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('宿屋でHP/SPを全快');
        app(HeroTrialService::class)->challenge($character, 'azure_dragon_warrior_king');
    }

    private function eligibleCharacter(string $trialKey, bool $strong = true): Character
    {
        $trial = app(HeroTrialService::class)->trial($trialKey);
        Area::query()->updateOrCreate(['id' => 70], [
            'name' => '終焉の祭壇', 'slug' => 'final_altar', 'city_id' => 10,
        ]);
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '最終陣テスト冒険者', 'current_city_id' => 10,
            'current_job_id' => 1, 'level' => 255, 'exp' => 77, 'money' => 123456,
            'hp_base' => $strong ? 1000000 : 100,
            'mp_base' => 100, 'attack_base' => $strong ? 1000000 : 1,
            'defense_base' => $strong ? 1000000 : 1, 'magic_base' => $strong ? 1000000 : 1,
            'spirit_base' => $strong ? 1000000 : 1, 'speed_base' => $strong ? 1000000 : 1,
            'luck_base' => 1,
        ]);
        foreach ([50, JobClass::query()->where('key', $trial['required_job_key'])->value('id')] as $jobId) {
            CharacterJob::query()->create([
                'character_id' => $character->id, 'job_class_id' => $jobId,
                'job_level' => 10, 'job_exp' => 0, 'is_mastered' => true,
            ]);
        }
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id, 'area_id' => 70,
            'is_unlocked' => true, 'boss_defeated' => true,
        ]);
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $character->update(['current_hp' => $stats['max_hp'], 'current_mp' => $stats['max_mp']]);

        return $character->fresh();
    }
}
