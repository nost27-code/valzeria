<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\GoldTransaction;
use App\Models\JobClass;
use App\Models\NamelessWorkshopOperation;
use App\Models\TownMapRegistration;
use App\Models\User;
use App\Services\GoldService;
use App\Services\MapExplorationItemService;
use App\Services\NamelessBattleHistoryService;
use App\Services\NamelessRuinCompensationService;
use App\Services\NamelessRuinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\NamelessRuinRewardReferences;
use Tests\TestCase;

class NamelessRuinCompensationTest extends TestCase
{
    use RefreshDatabase;
    use NamelessRuinRewardReferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['nameless_relics.enabled' => false, 'gold.battle.normal_drop_rate' => 100]);
    }

    public function test_plan_is_readonly_and_fixed_batch_rewards_replay_without_duplicate_history(): void
    {
        $character = $this->character();
        $source = $this->source($character, ['zone_key' => 'sand', 'depth' => 1,
            'batch_explore' => ['runs' => [
                ['index' => 1, 'result' => 'victory', 'turn_count' => 1, 'exp' => 0, 'enemy_name' => config('nameless_ruins.sand.enemies.0.name')],
                ['index' => 2, 'result' => 'win', 'turn_count' => 2, 'exp' => 17],
                ['index' => 3, 'result' => 'victory', 'turn_count' => 0, 'exp' => 0],
            ]]]);
        $before = $character->fresh()->getAttributes();
        $plan = $this->plan($source, 2, 1);
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
        $reward = $plan['entries'][0]['rewards'][0];
        $this->assertSame(17, $reward['experience']);
        $this->assertGreaterThanOrEqual(8, $reward['gold']);
        $this->assertLessThanOrEqual(12, $reward['gold']);
        $service = app(NamelessRuinCompensationService::class);
        $paid = $service->applyEntry($plan['entries'][0]);
        $this->assertSame('applied', $paid['status']);
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(12, (int) $character->fresh()->wins);
        $this->assertSame(2, $paid['receipt']['job_exp_added']);
        $after = $character->fresh()->getAttributes();
        $replay = $service->applyEntry($plan['entries'][0]);
        $this->assertSame('replayed', $replay['status']);
        $this->assertSame($paid['receipt'], $replay['receipt']);
        $this->assertSame($after, $character->fresh()->getAttributes());
        $this->assertSame(1, GoldTransaction::query()->count());
        $this->assertSame($reward['gold'], (int) GoldTransaction::query()->sum('amount'));
        $this->assertSame(['wins' => 2, 'losses' => 0, 'boss_wins' => 0], app(NamelessBattleHistoryService::class)->totalsForCharacter($character->id));
        $this->assertSame($source->getRawOriginal('result'), $source->fresh()->getRawOriginal('result'));
    }

    public function test_level_growth_preserves_hp_and_pays_the_current_job(): void
    {
        $character = $this->character();
        $character->update(['level' => 1, 'exp' => 49, 'current_hp' => 5]);
        $plan = $this->plan($this->single($character), 1, 1);
        $job = JobClass::query()->create(['key' => 'current-compensation-job', 'name' => '現在職', 'rank' => '一般職']);
        $oldJob = $character->current_job_id;
        $character->update(['current_job_id' => $job->id]);
        $result = app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
        $this->assertSame(2, (int) $character->fresh()->level);
        $this->assertSame(16, (int) $character->fresh()->exp);
        $this->assertSame(5, (int) $character->fresh()->current_hp);
        $this->assertSame(1, $result['receipt']['level_ups']);
        $this->assertSame(0, (int) CharacterJob::query()->where('job_class_id', $oldJob)->value('job_exp'));
        $this->assertSame(2, (int) CharacterJob::query()->where('job_class_id', $job->id)->value('job_exp'));
    }

    public function test_capped_level_and_mastered_job_keep_caps_but_receive_gold_and_wins(): void
    {
        $character = $this->character();
        $character->update(['level' => 255]);
        CharacterJob::query()->where('character_id', $character->id)->update(['is_mastered' => true, 'job_level' => 10]);
        $plan = $this->plan($this->single($character), 1, 1);
        $result = app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
        $this->assertSame(0, $result['receipt']['experience_added']);
        $this->assertSame(0, $result['receipt']['job_exp_added']);
        $this->assertSame(255, (int) $character->fresh()->level);
        $this->assertSame(0, (int) $character->fresh()->exp);
        $this->assertSame(11, (int) $character->fresh()->wins);
        $this->assertGreaterThan(100, (int) $character->fresh()->money);
    }

    public function test_failed_gold_ledger_rolls_back_all_growth_and_can_retry_the_fixed_plan(): void
    {
        $character = $this->character();
        $plan = $this->plan($this->single($character), 1, 1);
        $before = $character->fresh()->getAttributes();
        $this->partialMock(GoldService::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('fixture compensation ledger failure'));
        });
        try {
            app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
            $this->fail('A ledger failure must fail the entire compensation operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture compensation ledger failure', $exception->getMessage());
        }
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame(0, (int) CharacterJob::query()->where('character_id', $character->id)->value('job_exp'));
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
        $this->assertSame(0, GoldTransaction::query()->count());
        $this->app->forgetInstance(GoldService::class);
        $this->assertSame('applied', app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0])['status']);
        $this->assertSame($plan['entries'][0]['rewards'][0]['gold'], (int) GoldTransaction::query()->sum('amount'));
    }

    public function test_changed_source_and_changed_receipt_payload_are_rejected(): void
    {
        $character = $this->character();
        $source = $this->single($character);
        $plan = $this->plan($source, 1, 1);
        $original = $source->result;
        $source->update(['result' => $original + ['changed' => true]]);
        try {
            app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
            $this->fail('Changed history must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('記録ハッシュ', $exception->getMessage());
        }
        $this->assertSame(10, (int) $character->fresh()->wins);
        $source->update(['result' => $original]);
        app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
        $plan['entries'][0]['rewards'][0]['gold']++;
        $this->expectExceptionMessage('既存の補填計画と一致しません');
        app(NamelessRuinCompensationService::class)->applyEntry($plan['entries'][0]);
    }

    public function test_counter_only_history_does_not_pay_existing_rewards_again(): void
    {
        $character = $this->character();
        $source = $this->single($character, '2026-10-07 01:00:00');
        $source->update(['result' => array_replace($source->result, ['exp_gained' => 17])]);
        $entry = $this->plan($source, 1, 0)['entries'][0];
        $result = app(NamelessRuinCompensationService::class)->applyEntry($entry);
        $this->assertSame(1, $result['receipt']['wins_added']);
        $this->assertSame(0, $result['receipt']['experience_added']);
        $this->assertSame(0, $result['receipt']['gold_added']);
        $this->assertSame(0, GoldTransaction::query()->count());
    }

    public function test_active_map_defers_repair_without_creating_a_receipt(): void
    {
        $character = $this->character();
        $entry = $this->plan($this->single($character), 1, 1)['entries'][0];
        $this->mock(MapExplorationItemService::class)->shouldReceive('activeRegistration')->once()->andReturn(new TownMapRegistration());
        $result = app(NamelessRuinCompensationService::class)->applyEntry($entry);
        $this->assertSame(['status' => 'deferred', 'reason' => 'active_map'], $result);
        $this->assertSame(10, (int) $character->fresh()->wins);
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
    }

    public function test_fallback_job_reward_uses_reference_level_and_fixed_draws_with_no_gold(): void
    {
        \App\Models\Enemy::query()->where('name', '通常報酬基準試験魔物')->update(['job_exp_reward' => 0]);
        config(['gold.battle.normal_drop_rate' => 0]);
        $character = $this->character();
        $entry = $this->plan($this->single($character), 1, 1)['entries'][0];
        $this->assertSame(100, $entry['rewards'][0]['reference_level']);
        $result = app(NamelessRuinCompensationService::class)->applyEntry($entry);
        $this->assertSame($entry['rewards'][0]['job_draws'][1], $result['receipt']['job_exp_added']);
        $this->assertSame(0, $result['receipt']['gold_added']);
        $this->assertSame(0, GoldTransaction::query()->count());
    }

    private function character(): Character
    {
        $job = JobClass::query()->create(['key' => 'compensation-fixture', 'name' => '補填試験職', 'rank' => '一般職']);
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '補填試験',
            'current_job_id' => $job->id, 'level' => 100, 'exp' => 0, 'hp_base' => 1000, 'mp_base' => 100,
            'current_hp' => 900, 'current_mp' => 90, 'money' => 100, 'wins' => 10]);
        CharacterJob::query()->create(['character_id' => $character->id, 'job_class_id' => $job->id, 'job_level' => 1, 'job_exp' => 0]);
        return $character;
    }

    private function single(Character $character, string $at = '2026-10-06 23:00:00'): NamelessWorkshopOperation
    {
        $enemy = app(NamelessRuinService::class)->enemyStats(config('nameless_ruins.sand.enemies.0'), 1, false);
        return $this->source($character, ['result' => 'victory', 'turn_count' => 1, 'exp_gained' => 0, 'boss' => false, 'enemy' => $enemy], $at);
    }

    private function source(Character $character, array $data, string $at = '2026-10-06 23:00:00'): NamelessWorkshopOperation
    {
        $operation = NamelessWorkshopOperation::query()->create(['character_id' => $character->id, 'request_uuid' => (string) Str::uuid(),
            'action' => 'ruin', 'payload_hash' => str_repeat('a', 64), 'result' => $data]);
        $operation->forceFill(['created_at' => $at])->save();
        return $operation->fresh();
    }

    private function plan(NamelessWorkshopOperation $source, int $wins, int $unpaid): array
    {
        return app(NamelessRuinCompensationService::class)->createPlan([['operation_id' => $source->id,
            'character_id' => $source->character_id, 'original_result_sha256' => hash('sha256', $source->getRawOriginal('result')),
            'wins' => $wins, 'zero_exp_wins' => $unpaid]]);
    }
}
