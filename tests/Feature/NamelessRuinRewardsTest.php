<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\GameSetting;
use App\Models\GoldTransaction;
use App\Models\JobClass;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\GoldService;
use App\Services\LevelService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\NamelessRuinRewardReferences;
use Tests\TestCase;

class NamelessRuinRewardsTest extends TestCase
{
    use NamelessRuinRewardReferences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'nameless_relics.drop_chance_bps' => 10000,
            'nameless_relics.boss_drop_chance_bps' => 10000, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.cleared_boss_encounter_bps' => 0,
            'gold.battle.normal_drop_rate' => 100, 'gold.battle.boss_drop_rate' => 100]);
        $this->installRuinRewardReference();
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    public function test_normal_victory_persists_reference_rewards_and_replay_does_not_pay_twice(): void
    {
        $character = $this->character();
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid);
        $this->assertSame('victory', $result['result']);
        $this->assertSame(17, $result['exp_gained']); // No exploration-map premium.
        $this->assertSame(2, $result['job_exp_gained']);
        $this->assertGreaterThan(0, $result['gold_gained']);
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(2, $this->jobExp($character));
        $this->assertSame(10000 + $result['gold_gained'], (int) $character->fresh()->money);
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        $this->assertSame($result['gold_gained'], (int) GoldTransaction::where('character_id', $character->id)->sum('amount'));
        $before = $character->fresh()->getAttributes();
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid));
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame(1, GoldTransaction::query()->count());
        $this->assertSame(1, PlayerRelic::query()->count());
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
    }

    public function test_repeated_exploration_totals_match_stored_growth_and_gold_ledger(): void
    {
        $character = $this->character();
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, false, $uuid, 3);
        $this->assertSame(3, $result['batch_explore']['completed']);
        $this->assertSame(51, $result['exp_gained']);
        $this->assertSame(6, $result['job_exp_gained']);
        $this->assertSame(51, (int) $character->fresh()->exp);
        $this->assertSame(6, $this->jobExp($character));
        $this->assertSame(10000 + $result['gold_gained'], (int) $character->fresh()->money);
        $this->assertSame($result['gold_gained'], (int) GoldTransaction::query()->sum('amount'));
        $this->assertSame(97, (int) $character->fresh()->explore_stamina);
        $this->assertSame($result, $service->fight($character, 'sand', 1, false, $uuid, 3));
        $this->assertSame(3, GoldTransaction::query()->count());
        $this->assertSame(3, PlayerRelic::query()->count());
    }

    public function test_boss_rewards_and_depth_progress_commit_once(): void
    {
        $character = $this->character();
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, true, $uuid);
        $this->assertTrue($result['advanced']);
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(2, $this->jobExp($character));
        $this->assertSame(97, (int) $character->fresh()->explore_stamina);
        $this->assertSame(2, (int) NamelessRuinProgress::query()->value('unlocked_depth'));
        $this->assertSame($result, $service->fight($character, 'sand', 1, true, $uuid));
        $this->assertSame(1, GoldTransaction::query()->count());
    }

    public function test_defeat_has_no_growth_or_gold_or_relic_reward(): void
    {
        $character = $this->character();
        $character->update(['current_hp' => 1, 'hp_base' => 10, 'attack_base' => 1, 'magic_base' => 1,
            'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, true, (string) Str::uuid());
        $this->assertSame('defeat', $result['result']);
        $this->assertSame([0, 0, 0], [$result['exp_gained'], $result['gold_gained'], $result['job_exp_gained']]);
        $this->assertSame(0, (int) $character->fresh()->exp);
        $this->assertSame(0, $this->jobExp($character));
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, GoldTransaction::query()->count());
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_failed_gold_write_rolls_back_stamina_growth_and_progress(): void
    {
        $character = $this->character();
        $before = $character->fresh()->getAttributes();
        $this->partialMock(GoldService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->withArgs(function (Character $paid): bool {
                $this->assertGreaterThan(10000, (int) $paid->money);
                $this->assertSame((int) $paid->money, (int) $paid->fresh()->money);

                return true;
            })->andThrow(new RuntimeException('fixture reward failure'));
        });
        try {
            app(NamelessRuinService::class)->fight($character, 'sand', 1, true, (string) Str::uuid());
            $this->fail('Reward failure must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture reward failure', $exception->getMessage());
        }
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame(0, NamelessRuinProgress::query()->count());
        $this->assertSame(0, NamelessWorkshopOperation::query()->count());
        $this->assertSame(0, GoldTransaction::query()->count());
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_gold_remains_a_chance_drop_while_experience_is_paid(): void
    {
        config(['gold.battle.normal_drop_rate' => 0]);
        $character = $this->character();
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
        $this->assertSame(17, $result['exp_gained']);
        $this->assertSame(0, $result['gold_gained']);
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(10000, (int) $character->fresh()->money);
        $this->assertSame(0, GoldTransaction::query()->count());
    }

    public function test_level_up_is_saved_and_returned_without_healing_current_hp(): void
    {
        $character = $this->character();
        $character->update(['level' => 1, 'exp' => app(LevelService::class)->getRequiredExp(1) - 1]);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
        $this->assertSame(1, $result['level_up_count']);
        $this->assertCount(1, $result['level_up_details']);
        $this->assertSame(2, (int) $character->fresh()->level);
        $this->assertSame(16, (int) $character->fresh()->exp);
        $this->assertSame(100000, (int) $character->fresh()->current_hp);
    }

    public function test_maximum_level_preserves_cap_and_still_awards_gold_and_job_exp(): void
    {
        $character = $this->character();
        $character->update(['level' => 255, 'exp' => 0]);
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
        $this->assertSame(255, (int) $character->fresh()->level);
        $this->assertSame(0, (int) $character->fresh()->exp);
        $this->assertSame(0, $result['level_up_count']);
        $this->assertSame(2, $this->jobExp($character));
        $this->assertGreaterThan(0, $result['gold_gained']);
    }

    public function test_default_job_reward_uses_reference_level_instead_of_ruin_depth(): void
    {
        Enemy::query()->where('name', '通常報酬基準試験魔物')->update(['job_exp_reward' => 0]);
        $character = $this->character(); // Lv100; ruin depth is 1, reference enemy is Lv100.
        $result = app(NamelessRuinService::class)->fight($character, 'sand', 1, false, (string) Str::uuid());
        $this->assertGreaterThanOrEqual(1, $result['job_exp_gained']);
        $this->assertLessThanOrEqual(2, $result['job_exp_gained']);
        $this->assertSame($result['job_exp_gained'], $this->jobExp($character));
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $job = JobClass::query()->create(['key' => 'ruin-reward-fixture', 'name' => '報酬試験職', 'rank' => '一般職']);
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '遺跡報酬検証',
            'current_job_id' => $job->id, 'level' => 100, 'exp' => 0, 'current_city_id' => $town->id,
            'explore_stamina' => 100, 'explore_stamina_updated_at' => now(), 'hp_base' => 100000,
            'mp_base' => 1000, 'current_hp' => 100000, 'current_mp' => 500, 'attack_base' => 100000,
            'magic_base' => 100000, 'defense_base' => 10000, 'spirit_base' => 10000,
            'speed_base' => 100000, 'luck_base' => 10, 'money' => 10000]);
        CharacterJob::query()->create(['character_id' => $character->id, 'job_class_id' => $job->id, 'job_level' => 1, 'job_exp' => 0]);

        return $character;
    }

    private function jobExp(Character $character): int
    {
        return (int) CharacterJob::query()->where('character_id', $character->id)->where('job_class_id', $character->current_job_id)->value('job_exp');
    }
}
