<?php

namespace Tests\Feature;

use App\Models\EnemyAction;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use App\Services\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use Tests\TestCase;

class MagicHeroTrialReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_star_and_time_trial_master_rows_are_installed_while_the_feature_stays_off_by_default(): void
    {
        $this->assertFalse((bool) config('extra_content.contents.hero_trials.default_enabled'));

        foreach ([
            [86, 'star_heaven_hero_trial', 72, 'star_heaven_sage', 63, 'hero_trial_072.webp'],
            [87, 'time_reader_hero_trial', 77, 'time_reader_traveler', 67, 'hero_trial_077.webp'],
        ] as [$areaId, $slug, $heroJobId, $heroJobKey, $requiredJobId, $asset]) {
            $this->assertDatabaseHas('areas', [
                'id' => $areaId,
                'city_id' => 10,
                'slug' => $slug,
                'area_kind' => 'hero_trial',
                'is_published' => false,
            ]);
            $this->assertDatabaseHas('job_classes', [
                'id' => $heroJobId,
                'key' => $heroJobKey,
                'is_active' => true,
                'is_hidden' => true,
            ]);
            $this->assertDatabaseHas('job_requirements', [
                'job_id' => $heroJobId,
                'requirement_type' => 'master_job',
                'required_job_id' => $requiredJobId,
            ]);
            $this->assertFileExists(public_path("images/symbol/{$asset}"));
            $this->assertFileExists(public_path('images/jobbadge/'.str_replace('hero_trial_', 'jobbadge_', $asset)));
        }

        $this->assertFileExists(public_path('images/enemy/enemy_725.webp'));
        $this->assertFileExists(public_path('images/enemy/enemy_732.webp'));
        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('hero_trials'));
    }

    public function test_magic_trial_enemy_actions_use_magic_debuffs_multi_hit_and_speed_acceleration(): void
    {
        $service = app(BattleService::class);
        $this->seedBattleRandomizer($service, 20260908);

        $enemy = new BattleActor('試練主', false, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'str' => 1,
            'def' => 100,
            'agi' => 10_000,
            'mag' => 1_000,
            'spr' => 100,
            'luk' => 10_000,
            'normal_attack_type' => 'magical',
        ]);
        $player = new BattleActor('挑戦者', true, [
            'hp' => 20_000,
            'max_hp' => 20_000,
            'str' => 100,
            'def' => 1_000_000,
            'agi' => 1,
            'mag' => 100,
            'spr' => 100,
            'luk' => 1,
        ]);
        $state = new BattleState($player, $enemy, 'boss');

        $this->executeEnemyAction($service, $enemy, $player, $state, new EnemyAction([
            'id' => 1,
            'name' => '天象解析',
            'action_type' => 'magical_spr_down',
            'power_percent' => 80,
            'effect_percent' => 18,
            'duration_turns' => 3,
        ]));
        $this->assertArrayHasKey('spr_down', $player->conditions);
        $this->assertSame(0.18, $player->conditions['spr_down']['rate']);

        $magicLogsBefore = collect($state->logs)->filter(fn (string $log): bool => str_contains($log, 'の魔法攻撃！'))->count();
        $this->executeEnemyAction($service, $enemy, $player, $state, new EnemyAction([
            'id' => 2,
            'name' => '刻針連奏',
            'action_type' => 'magical_multi_hit',
            'power_percent' => 70,
            'hit_count' => 2,
        ]));
        $magicLogsAfter = collect($state->logs)->filter(fn (string $log): bool => str_contains($log, 'の魔法攻撃！'))->count();
        $this->assertSame(2, $magicLogsAfter - $magicLogsBefore);

        $this->executeEnemyAction($service, $enemy, $player, $state, new EnemyAction([
            'id' => 3,
            'name' => '時環加速',
            'action_type' => 'self_speed_buff',
            'effect_percent' => 15,
        ]));
        $this->assertSame(11_500, $enemy->agi);
        $this->assertTrue(collect($state->logs)->contains(fn (string $log): bool => str_contains($log, '敏捷が高まった')));
    }

    private function seedBattleRandomizer(BattleService $service, int $seed): void
    {
        $method = new ReflectionMethod(BattleService::class, 'useScopedBattleRandomizer');
        $method->invoke($service, new Randomizer(new Mt19937($seed)));
    }

    private function executeEnemyAction(
        BattleService $service,
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        EnemyAction $action,
    ): void {
        $method = new ReflectionMethod(BattleService::class, 'executeEnemyActionEffect');
        $method->invoke($service, $attacker, $defender, $state, $action);
    }
}
