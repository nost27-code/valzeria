<?php

namespace Tests\Feature;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\GameSetting;
use App\Models\JobClass;
use App\Models\KisekiTransaction;
use App\Models\NamelessWorkshopOperation;
use App\Models\User;
use App\Models\WeeklyWinRankingRecord;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use App\Services\WeeklyWinRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NamelessRuinRewardReferences;
use Tests\TestCase;

class NamelessWeeklyWinsTest extends TestCase
{
    use NamelessRuinRewardReferences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 7, 1, 0, 0, 'Asia/Tokyo'));
        config(['nameless_relics.enabled' => true, 'nameless_relics.drop_chance_bps' => 0,
            'nameless_relics.boss_drop_chance_bps' => 0, 'nameless_relics.relic_goblin_encounter_bps' => 0,
            'nameless_relics.cleared_boss_encounter_bps' => 0, 'gold.battle.normal_drop_rate' => 0,
            'gold.battle.boss_drop_rate' => 0]);
        $this->installRuinRewardReference();
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_real_single_boss_and_batch_wins_are_counted_once_on_replay(): void
    {
        $character = $this->character();
        $ruins = app(NamelessRuinService::class);
        foreach ([[false, 1], [true, 1], [false, 3]] as [$boss, $count]) {
            $uuid = (string) Str::uuid();
            $result = $ruins->fight($character, 'sand', 1, $boss, $uuid, $count);
            $this->assertSame($result, $ruins->fight($character, 'sand', 1, $boss, $uuid, $count));
        }
        $this->assertSame(5, (int) $character->fresh()->wins);
        $this->assertSame(0, (int) $character->fresh()->losses);
        $this->assertSame(3, NamelessWorkshopOperation::query()->count());
        $this->assertSame(5, $this->score($character));
        $this->assertSame(0, BattleLog::query()->count());
    }

    public function test_actual_defeat_increments_losses_once_and_is_not_a_weekly_win(): void
    {
        $character = $this->character();
        $character->update(['current_hp' => 1, 'hp_base' => 10, 'attack_base' => 1, 'magic_base' => 1,
            'defense_base' => 0, 'spirit_base' => 0, 'speed_base' => 1]);
        $uuid = (string) Str::uuid();
        $ruins = app(NamelessRuinService::class);
        $result = $ruins->fight($character, 'sand', 1, true, $uuid);
        $this->assertSame('defeat', $result['result']);
        $this->assertSame($result, $ruins->fight($character, 'sand', 1, true, $uuid));
        $this->assertSame(0, (int) $character->fresh()->wins);
        $this->assertSame(1, (int) $character->fresh()->losses);
        $this->assertSame(0, $this->score($character));
    }

    public function test_saved_zero_exp_wins_and_partial_batch_use_original_week_even_when_off(): void
    {
        $character = $this->character();
        $before = $character->fresh()->getAttributes();
        $this->operation($character, ['result' => 'victory', 'turn_count' => 1, 'exp_gained' => 0], '2026-10-05 09:00:00');
        $this->operation($character, ['result' => 'defeat', 'turn_count' => 2, 'batch_explore' => ['runs' => [
            ['result' => 'victory', 'turn_count' => 1], ['result' => 'win', 'turn_count' => 2],
            ['result' => 'defeat', 'turn_count' => 3], ['result' => 'timeout', 'turn_count' => 20],
            ['result' => 'victory', 'turn_count' => 0],
        ]]], '2026-10-11 23:59:59');
        foreach (['2026-10-05 08:59:59', '2026-10-12 09:00:00'] as $time) {
            $this->operation($character, ['result' => 'victory', 'turn_count' => 1], $time);
        }
        foreach (['event', 'defeat', 'timeout'] as $result) {
            $this->operation($character, ['result' => $result, 'turn_count' => 1]);
        }
        $this->operation($character, ['result' => 'victory', 'turn_count' => 1], null, 'forge');
        config(['nameless_relics.enabled' => false]);
        $this->assertSame(3, $this->score($character));
        for ($i = 0; $i < 201; $i++) {
            $this->operation($character, ['result' => 'victory', 'turn_count' => 1]);
        }
        $this->assertSame(204, $this->score($character));
        $this->assertSame($before, $character->fresh()->getAttributes());
    }

    public function test_normal_and_ruin_scores_combine_with_ties_and_account_exclusions(): void
    {
        $one = $this->character();
        $two = $this->character();
        $three = $this->character();
        $this->normalWin($one);
        foreach ([$one, $one, $two, $two, $two, $three] as $character) {
            $this->operation($character, ['result' => 'victory', 'turn_count' => 1]);
        }
        foreach ([['role' => 'admin'], ['email' => 'tester_weekly@valzeria.local']] as $attributes) {
            $excluded = $this->character(User::factory()->create($attributes));
            $this->operation($excluded, ['result' => 'victory', 'turn_count' => 1]);
        }
        $rows = app(WeeklyWinRankingService::class)->currentRows();
        $this->assertSame([$one->id, $two->id, $three->id], $rows->pluck('character_id')->all());
        $this->assertSame([3, 3, 1], $rows->pluck('score')->all());
        $this->assertSame([1, 1, 3], $rows->pluck('rank')->all());
    }

    public function test_unprepared_database_keeps_existing_normal_ranking(): void
    {
        $character = $this->character();
        $this->normalWin($character);
        Schema::drop('nameless_workshop_operations');
        config(['nameless_relics.enabled' => false]);
        $this->assertSame(1, $this->score($character));
        Schema::create('nameless_workshop_operations', fn ($table) => $table->id());
        $this->assertSame(1, $this->score($character));
    }

    public function test_finalization_includes_ruin_wins_without_double_rewards(): void
    {
        $character = $this->character();
        $this->normalWin($character);
        $this->operation($character, ['result' => 'victory', 'turn_count' => 1]);
        $service = app(WeeklyWinRankingService::class);
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 9, 10, 0, 'Asia/Tokyo'));
        $period = $service->periodForWeekStart('2026-10-05');
        $first = $service->finalizePeriod($period);
        $second = $service->finalizePeriod($period);
        $this->assertFalse($first['already_finalized']);
        $this->assertTrue($second['already_finalized']);
        $this->assertSame(2, (int) WeeklyWinRankingRecord::query()->sole()->wins);
        $this->assertSame(20, (int) WeeklyWinRankingRecord::query()->sole()->reward_free_kiseki);
        $this->assertSame(1, KisekiTransaction::where('transaction_type', WeeklyWinRankingService::TRANSACTION_TYPE)->count());
    }

    private function score(Character $character): int
    {
        Cache::flush();

        return (int) (app(WeeklyWinRankingService::class)->currentRows()->firstWhere('character_id', $character->id)['score'] ?? 0);
    }

    private function operation(Character $character, array $result, ?string $time = null, string $action = 'ruin'): void
    {
        $operation = new NamelessWorkshopOperation(['character_id' => $character->id, 'request_uuid' => (string) Str::uuid(),
            'action' => $action, 'payload_hash' => str_repeat('a', 64), 'result' => $result]);
        $operation->forceFill([
            'created_at' => $time ?? now(), 'updated_at' => $time ?? now()])->save();
    }

    private function normalWin(Character $character): void
    {
        $enemy = Enemy::where('name', '通常報酬基準試験魔物')->sole();
        BattleLog::query()->create(['character_id' => $character->id, 'area_id' => $enemy->area_id, 'enemy_id' => $enemy->id,
            'battle_type' => 'normal', 'result' => 'win', 'exp_gained' => 17, 'turn_count' => 1, 'log_text' => '通常戦闘']);
    }

    private function character(?User $user = null): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $job = JobClass::firstOrCreate(['key' => 'ruin-weekly-fixture'], ['name' => '週間試験職', 'rank' => '一般職']);
        $character = Character::query()->create(['user_id' => ($user ?? User::factory()->create())->id, 'name' => '遺跡週間検証',
            'current_job_id' => $job->id, 'level' => 100, 'exp' => 0, 'current_city_id' => $town->id,
            'explore_stamina' => 100, 'explore_stamina_updated_at' => now(), 'hp_base' => 100000,
            'mp_base' => 1000, 'current_hp' => 100000, 'current_mp' => 500, 'attack_base' => 100000,
            'magic_base' => 100000, 'defense_base' => 10000, 'spirit_base' => 10000,
            'speed_base' => 100000, 'luck_base' => 10, 'money' => 10000, 'free_kiseki' => 0]);
        CharacterJob::query()->create(['character_id' => $character->id, 'job_class_id' => $job->id, 'job_level' => 1, 'job_exp' => 0]);

        return $character;
    }
}
