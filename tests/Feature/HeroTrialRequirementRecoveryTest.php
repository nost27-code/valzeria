<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterJob;
use App\Models\JobClass;
use App\Models\User;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HeroTrialRequirementRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['extra_content.contents.hero_trials.default_enabled' => true]);
        Cache::flush();
        $this->withoutMiddleware(CheckCharacterSelected::class);
    }

    public function test_requirement_button_opens_a_modal_with_each_challenge_condition_and_inn_fee(): void
    {
        [$user] = $this->createEligibleCharacter();

        $this->actingAs($user)
            ->get(route('hero-trials.index'))
            ->assertOk()
            ->assertSee('挑戦条件を確認')
            ->assertSee('挑戦条件')
            ->assertSee('終焉の祭壇を踏破')
            ->assertSee('剣冠騎士をマスター')
            ->assertSee('HPを全快にする')
            ->assertSee('SPを全快にする')
            ->assertSee('宿屋に泊まりますか？')
            ->assertSee('300G');
    }

    public function test_unreleased_trial_cards_are_not_rendered_in_the_hall(): void
    {
        [$user] = $this->createEligibleCharacter();

        $this->actingAs($user)
            ->get(route('hero-trials.index'))
            ->assertOk()
            ->assertSee('暁の試練場')
            ->assertDontSee('蒼竜の試練場')
            ->assertDontSee('準備中')
            ->assertDontSee('未実装');
    }

    public function test_player_can_pay_the_normal_inn_fee_and_recover_from_the_trial_modal(): void
    {
        [$user, $character] = $this->createEligibleCharacter();
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character);

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'paid' => 300,
                'rescued' => false,
            ]);

        $character->refresh();

        $this->assertSame(700, (int) $character->money);
        $this->assertSame((int) $stats['max_hp'], (int) $character->current_hp);
        $this->assertSame((int) $stats['max_mp'], (int) $character->current_mp);
        $this->assertDatabaseHas('gold_transactions', [
            'character_id' => $character->id,
            'type' => 'inn',
            'amount' => -300,
            'balance_after' => 700,
        ]);
    }

    public function test_recovery_endpoint_rejects_requests_when_a_non_recovery_condition_is_missing(): void
    {
        [$user, $character, $requiredJob] = $this->createEligibleCharacter();
        CharacterJob::query()
            ->where('character_id', $character->id)
            ->where('job_class_id', $requiredJob->id)
            ->delete();

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_recovery_preserves_the_existing_bank_withdrawal_guidance(): void
    {
        [$user, $character] = $this->createEligibleCharacter();
        $character->forceFill([
            'money' => 100,
            'bank_gold' => 1000,
        ])->save();

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                '所持金が足りません。宿泊料金は 300G です。銀行からGoldを引き出してから宿屋に泊まってください。'
            );

        $character->refresh();
        $this->assertSame(100, (int) $character->money);
        $this->assertSame(100, (int) $character->current_hp);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_recovery_preserves_the_existing_rescue_lodging_rule(): void
    {
        [$user, $character] = $this->createEligibleCharacter();
        $character->forceFill([
            'money' => 100,
            'bank_gold' => 0,
            'inn_rescue_streak' => 0,
        ])->save();

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'paid' => 100,
                'rescued' => true,
            ]);

        $character->refresh();
        $this->assertSame(0, (int) $character->money);
        $this->assertSame(1, (int) $character->inn_rescue_streak);
        $this->assertDatabaseHas('gold_transactions', [
            'character_id' => $character->id,
            'type' => 'inn',
            'amount' => -100,
            'balance_after' => 0,
        ]);
    }

    public function test_short_interval_recovery_requests_do_not_charge_twice(): void
    {
        [$user, $character] = $this->createEligibleCharacter();

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertOk();

        $this->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'HP/SPはすでに全快です。そのまま試練に挑めます。');

        $this->assertSame(700, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 1);
    }

    public function test_recovery_request_guard_blocks_a_second_in_flight_request(): void
    {
        [$user, $character] = $this->createEligibleCharacter();
        Cache::put(
            "hero_trial_rest_request_delay:{$character->id}",
            true,
            now()->addSeconds(3)
        );

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    public function test_recovery_endpoint_rejects_an_already_cleared_trial(): void
    {
        [$user, $character] = $this->createEligibleCharacter();
        CharacterAreaProgress::query()->create([
            'character_id' => $character->id,
            'area_id' => 84,
            'is_unlocked' => true,
            'boss_defeated' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('hero-trials.rest', ['trialKey' => 'dawn_hero']))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(1000, (int) $character->fresh()->money);
        $this->assertDatabaseCount('gold_transactions', 0);
    }

    /**
     * @return array{User, Character, JobClass}
     */
    private function createEligibleCharacter(): array
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
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '試練確認者',
            'level' => 30,
            'current_job_id' => $requiredJob->id,
            'current_city_id' => 10,
            'hp_base' => 1000,
            'mp_base' => 300,
            'current_hp' => 100,
            'current_mp' => 30,
            'money' => 1000,
            'bank_gold' => 0,
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

        session(['current_character_id' => $character->id]);

        return [$user, $character, $requiredJob];
    }
}
