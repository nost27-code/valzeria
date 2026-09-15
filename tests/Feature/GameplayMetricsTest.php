<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\GameplayMetric;
use App\Models\JobClass;
use App\Models\Skill;
use App\Models\User;
use App\Services\Admin\GameplayAnalyticsService;
use App\Services\Battle\BattleResult;
use App\Services\GameplayMetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameplayMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_job_art_and_exploration_results_and_aggregates_single_vs_batch(): void
    {
        $character = $this->createCharacter();
        $art = $this->createArt();
        $offLineageArt = $this->createArt();
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 4;
        $battle->playerLevelAtStart = 48;
        $battle->playerJobIdAtStart = (int) $art->job_id;
        $battle->jobArtLoadout = [[
            'slot_no' => 1,
            'skill_id' => $art->id,
            'name' => $art->name,
            'origin' => 'current',
        ]];
        $battle->jobArtUsage = [[
            'skill_id' => $art->id,
            'name' => $art->name,
            'origin' => 'current',
            'activation_count' => 2,
            'hit_count' => 1,
            'miss_count' => 1,
            'evade_count' => 0,
            'no_resolution_count' => 0,
            'vital_hit_count' => 1,
            'hp_recovered' => 120,
            'sp_recovered' => 8,
        ]];
        $battle->jobArtActivationAttempts = [
            [
                'skill_id' => $art->id,
                'name' => $art->name,
                'effective_rate' => 50,
                'activation_roll' => 25,
                'current_lineage' => 'counter',
                'skill_lineage' => 'counter',
                'attempt_count' => 2,
            ],
            [
                'skill_id' => $art->id,
                'name' => $art->name,
                'effective_rate' => 50,
                'activation_roll' => 52,
                'current_lineage' => 'counter',
                'skill_lineage' => 'counter',
                'attempt_count' => 1,
            ],
            [
                'skill_id' => $art->id,
                'name' => $art->name,
                'effective_rate' => 50,
                'activation_roll' => 53,
                'current_lineage' => 'counter',
                'skill_lineage' => 'counter',
                'attempt_count' => 1,
            ],
            [
                'skill_id' => $offLineageArt->id,
                'name' => $offLineageArt->name,
                'effective_rate' => 50,
                'activation_roll' => 51,
                'current_lineage' => 'counter',
                'skill_lineage' => 'pierce',
                'attempt_count' => 1,
            ],
        ];

        $service = app(GameplayMetricService::class);
        $service->recordJobArtBattle($character, 'normal', $battle);
        $service->recordExplorationRequest($character, 'normal', 1, [
            'result' => 'victory',
            'exp_gained' => 100,
            'gold_gained' => 20,
            'job_exp_gained' => 5,
            'equipment_drops' => [['name' => '剣']],
            'material_drop' => [['name' => '石', 'quantity' => 2]],
        ], ['danger_rate' => 100, 'stamina' => 50]);
        $service->recordExplorationRequest($character, 'normal', 50, [
            'result' => 'victory',
            'equipment_drops' => [['name' => '盾'], ['name' => '杖']],
            'material_drop' => [['name' => '石', 'quantity' => 10]],
            'batch_explore' => [
                'requested' => 50,
                'completed' => 40,
                'stop_reason' => 'hp_pinch',
                'total_exp' => 4000,
                'total_gold' => 800,
                'total_job_exp' => 200,
                'runs' => array_fill(0, 40, ['result' => 'victory']),
            ],
        ], ['danger_rate' => 100, 'stamina' => 50]);

        $this->assertDatabaseCount('gameplay_metrics', 3);
        $this->assertSame(2, GameplayMetric::query()->where('metric_type', GameplayMetric::TYPE_EXPLORATION_REQUEST)->count());

        $analysis = app(GameplayAnalyticsService::class)->analyze('all');
        $this->assertTrue($analysis['ready']);
        $this->assertSame(1, $analysis['jobArt']['cards']['battles']);
        $this->assertSame(2, $analysis['jobArt']['cards']['activations']);
        $this->assertSame(50.0, $analysis['jobArt']['skillRows'][0]['hit_rate']);
        $this->assertSame(1, $analysis['jobArt']['skillRows'][0]['vital_hits']);
        $this->assertSame(100.0, $analysis['jobArt']['skillRows'][0]['vital_hit_rate']);
        $jobArtMetric = GameplayMetric::query()
            ->where('metric_type', GameplayMetric::TYPE_JOB_ART_BATTLE)
            ->sole();
        $this->assertSame(1, data_get($jobArtMetric->payload, 'skills.0.vital_hit_count'));
        $this->assertSame(3, data_get($jobArtMetric->payload, 'version'));
        $this->assertSame(48, data_get($jobArtMetric->payload, 'character_level_at_start'));
        $this->assertSame((int) $art->job_id, data_get($jobArtMetric->payload, 'current_job_id_at_start'));
        $this->assertSame('1-49', data_get($jobArtMetric->payload, 'level_band_at_start'));
        $this->assertSame(120.0, $analysis['jobArt']['loadoutRows'][0]['hp_recovered_per_battle']);
        $this->assertSame(8.0, $analysis['jobArt']['loadoutRows'][0]['sp_recovered_per_battle']);
        $this->assertSame(4.0, $analysis['jobArt']['loadoutRows'][0]['average_turns']);
        $shadow = app(GameplayAnalyticsService::class)->analyze([
            'activity_window' => 'all',
            'shadow_bonus_points' => 2,
        ])['jobArt']['activationShadow'];
        $this->assertDatabaseCount('gameplay_job_art_activation_rollups', 4);
        $this->assertSame(5, $shadow['cards']['attempts']);
        $this->assertSame(4, $shadow['cards']['same_lineage_attempts']);
        $this->assertSame(2, $shadow['cards']['actual_activations']);
        $this->assertSame(1, $shadow['cards']['estimated_extra_activations']);
        $this->assertSame(3, $shadow['cards']['estimated_total_activations']);
        $this->assertSame(40.0, $shadow['cards']['actual_activation_rate']);
        $this->assertSame(60.0, $shadow['cards']['estimated_activation_rate']);
        $this->assertSame(1, $shadow['skillRows'][0]['estimated_extra_activations']);
        $this->assertSame(2, $analysis['exploration']['cards']['requests']);
        $this->assertSame(51, $analysis['exploration']['cards']['requested_runs']);
        $this->assertSame(41, $analysis['exploration']['cards']['completed_runs']);
        $this->assertSame('1回探索', $analysis['exploration']['modeRows'][0]['label']);
        $this->assertSame(100.0, $analysis['exploration']['modeRows'][0]['equipment_per_100']);
        $this->assertSame('HP低下', $analysis['exploration']['stopRows'][0]['label']);
    }

    public function test_admin_and_tester_characters_are_not_recorded(): void
    {
        $service = app(GameplayMetricService::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $adminCharacter = $this->createCharacter($admin);
        $tester = User::factory()->create(['role' => 'user', 'email' => 'tester_gameplay_metrics@valzeria.local']);
        $testerCharacter = $this->createCharacter($tester);
        $adminCharacter->setRelation('user', (new User)->forceFill(['id' => $admin->id]));
        $testerCharacter->setRelation('user', (new User)->forceFill(['id' => $tester->id]));
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 2;

        $service->recordJobArtBattle($adminCharacter, 'normal', $battle);
        $service->recordJobArtBattle($testerCharacter, 'normal', $battle);

        $this->assertDatabaseCount('gameplay_metrics', 0);

        $similarEmail = User::factory()->create(['role' => 'user', 'email' => 'testera@valzeria.local']);
        $similarEmailCharacter = $this->createCharacter($similarEmail);
        $service->recordJobArtBattle($similarEmailCharacter, 'normal', $battle);
        $this->assertDatabaseCount('gameplay_metrics', 1);
        GameplayMetric::query()->create([
            'character_id' => $testerCharacter->id,
            'metric_type' => GameplayMetric::TYPE_JOB_ART_BATTLE,
            'context' => 'normal',
            'result' => 'victory',
            'payload' => ['version' => 1, 'turn_count' => 2, 'activation_count' => 0, 'skills' => []],
            'created_at' => now(),
        ]);
        $legacyArt = $this->createArt();
        $legacyShapeBattle = new BattleResult;
        $legacyShapeBattle->result = 'victory';
        $legacyShapeBattle->turnCount = 2;
        $legacyShapeBattle->jobArtUsage = [[
            'skill_id' => $legacyArt->id,
            'name' => $legacyArt->name,
            'origin' => 'current',
            'activation_count' => 2,
            'hit_count' => 1,
            'miss_count' => 1,
            'evade_count' => 0,
            'no_resolution_count' => 0,
        ]];
        $service->recordJobArtBattle($similarEmailCharacter, 'pvp', $legacyShapeBattle);
        $this->assertDatabaseCount('gameplay_metrics', 3);
        $analysis = app(GameplayAnalyticsService::class)->analyze('all');
        $this->assertSame(2, $analysis['jobArt']['cards']['battles']);
        $this->assertSame(50.0, $analysis['jobArt']['skillRows'][0]['hit_rate']);
        $this->assertSame(0, $analysis['jobArt']['skillRows'][0]['vital_hits']);
        $this->assertSame(0.0, $analysis['jobArt']['skillRows'][0]['vital_hit_rate']);
    }

    public function test_gameplay_metrics_page_is_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->get(route('admin.gameplay-analytics'))->assertRedirect();
        $normalUser = User::factory()->create(['role' => 'user']);
        $this->actingAs($normalUser)->get(route('admin.gameplay-analytics'))->assertRedirect('/admin/login');
        $this->actingAs($admin)->get(route('admin.gameplay-analytics'))
            ->assertOk()
            ->assertSee('戦技・探索実績')
            ->assertSee('同系譜の発動率ボーナス仮試算')
            ->assertSee('急所命中');
    }

    public function test_records_each_map_battle_after_a_batch_has_completed(): void
    {
        $character = $this->createCharacter();
        $art = $this->createArt();
        $usage = [[
            'skill_id' => $art->id,
            'name' => $art->name,
            'origin' => 'current',
            'activation_count' => 1,
            'hit_count' => 1,
            'miss_count' => 0,
            'evade_count' => 0,
            'no_resolution_count' => 0,
            'vital_hit_count' => 0,
        ]];

        app(GameplayMetricService::class)->recordJobArtExplorationResult($character, 'map', [
            'batch_explore' => [
                'runs' => [
                    ['result' => 'victory', 'turn_count' => 3, 'job_art_usage' => $usage],
                    ['result' => 'defeat', 'turn_count' => 4, 'job_art_usage' => []],
                ],
            ],
        ]);

        $this->assertDatabaseCount('gameplay_metrics', 2);
        $this->assertSame(
            ['victory', 'defeat'],
            GameplayMetric::query()->orderBy('id')->pluck('result')->all(),
        );
        $this->assertSame(
            [1, 0],
            GameplayMetric::query()->orderBy('id')->get()
                ->map(fn (GameplayMetric $metric): int => (int) data_get($metric->payload, 'activation_count'))
                ->all(),
        );
    }

    public function test_depth_gate_stop_keeps_the_completed_battle_rewards_in_metrics(): void
    {
        $character = $this->createCharacter();

        app(GameplayMetricService::class)->recordExplorationRequest($character, 'normal', 1, [
            'result' => 'victory',
            'exp_gained' => 120,
            'gold_gained' => 30,
            'job_exp_gained' => 6,
            'metric_stop_reason' => 'depth_transition',
        ], ['danger_rate' => 80, 'stamina' => 20]);

        $metric = GameplayMetric::query()->sole();
        $this->assertSame('depth_transition', data_get($metric->payload, 'stop_reason'));
        $this->assertSame(120, data_get($metric->payload, 'rewards.exp'));
        $this->assertSame(1, data_get($metric->payload, 'completed_count'));
    }

    public function test_danger_and_stamina_deltas_are_normalized_per_completed_exploration(): void
    {
        $character = $this->createCharacter();
        $payloads = [
            ['requested_count' => 1, 'completed_count' => 1, 'danger_before' => 100, 'danger_after' => 110, 'stamina_before' => 50, 'stamina_after' => 49],
            ['requested_count' => 10, 'completed_count' => 10, 'danger_before' => 100, 'danger_after' => 200, 'stamina_before' => 50, 'stamina_after' => 40],
        ];
        foreach ($payloads as $payload) {
            GameplayMetric::query()->create([
                'character_id' => $character->id,
                'metric_type' => GameplayMetric::TYPE_EXPLORATION_REQUEST,
                'context' => 'normal',
                'result' => 'victory',
                'payload' => $payload + [
                    'version' => 1,
                    'stop_reason' => null,
                    'outcomes' => [],
                    'rewards' => [],
                    'drops' => [],
                ],
                'created_at' => now(),
            ]);
        }

        $rows = collect(app(GameplayAnalyticsService::class)->analyze('all')['exploration']['modeRows'])->keyBy('key');
        $this->assertSame(10.0, $rows['single']['average_danger_delta']);
        $this->assertSame(10.0, $rows['batch']['average_danger_delta']);
        $this->assertSame(1.0, $rows['single']['average_stamina_cost']);
        $this->assertSame(1.0, $rows['batch']['average_stamina_cost']);
    }

    public function test_battle_time_job_and_level_filters_do_not_follow_later_character_changes(): void
    {
        $character = $this->createCharacter();
        $battleJob = JobClass::query()->create([
            'key' => 'battle-time-job-'.str()->random(6),
            'name' => '戦闘時の職',
            'rank' => 'basic',
            'max_job_level' => 10,
        ]);
        $laterJob = JobClass::query()->create([
            'key' => 'later-job-'.str()->random(6),
            'name' => '後からの職',
            'rank' => 'basic',
            'max_job_level' => 10,
        ]);
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 3;
        $battle->playerLevelAtStart = 99;
        $battle->playerJobIdAtStart = (int) $battleJob->id;

        app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $battle);
        $character->forceFill(['level' => 220, 'current_job_id' => $laterJob->id])->save();

        $battleTime = app(GameplayAnalyticsService::class)->analyze([
            'activity_window' => 'all',
            'battle_context' => 'normal',
            'current_job_id' => $battleJob->id,
            'level_band' => '50-99',
        ]);
        $laterState = app(GameplayAnalyticsService::class)->analyze([
            'activity_window' => 'all',
            'battle_context' => 'normal',
            'current_job_id' => $laterJob->id,
            'level_band' => '200-255',
        ]);

        $this->assertSame(1, $battleTime['jobArt']['cards']['battles']);
        $this->assertSame(0, $laterState['jobArt']['cards']['battles']);
    }

    public function test_loadout_rollup_keeps_slot_order_and_aggregates_matching_signatures(): void
    {
        $character = $this->createCharacter();
        $opening = $this->createArt();
        $link = Skill::query()->create([
            'job_id' => $opening->job_id,
            'name' => '計測の連携',
            'skill_type' => 'job_art',
            'learn_rank' => 5,
        ]);
        $service = app(GameplayMetricService::class);

        foreach ([
            ['result' => 'victory', 'turn_count' => 3, 'skills' => [$opening, $link]],
            ['result' => 'defeat', 'turn_count' => 5, 'skills' => [$opening, $link]],
            ['result' => 'victory', 'turn_count' => 2, 'skills' => [$link, $opening]],
        ] as $case) {
            $battle = new BattleResult;
            $battle->result = $case['result'];
            $battle->turnCount = $case['turn_count'];
            $battle->playerLevelAtStart = 120;
            $battle->playerJobIdAtStart = (int) $opening->job_id;
            $battle->jobArtLoadout = collect($case['skills'])
                ->values()
                ->map(fn (Skill $skill, int $index): array => [
                    'slot_no' => $index + 1,
                    'skill_id' => (int) $skill->id,
                    'name' => (string) $skill->name,
                    'origin' => 'current',
                ])->all();
            $service->recordJobArtBattle($character, 'normal', $battle);
        }

        $rows = collect(app(GameplayAnalyticsService::class)->analyze([
            'activity_window' => 'all',
            'battle_context' => 'normal',
            'current_job_id' => $opening->job_id,
            'level_band' => '100-149',
        ])['jobArt']['loadoutRows'])->keyBy('label');

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows['計測の構え → 計測の連携']['battles']);
        $this->assertSame(50.0, $rows['計測の構え → 計測の連携']['win_rate']);
        $this->assertSame(4.0, $rows['計測の構え → 計測の連携']['average_turns']);
        $this->assertSame(1, $rows['計測の連携 → 計測の構え']['battles']);
    }

    public function test_malformed_telemetry_payloads_do_not_escape_into_gameplay_flow(): void
    {
        $character = $this->createCharacter();
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 1;
        $battle->jobArtUsage = ['unexpected'];

        $service = app(GameplayMetricService::class);
        $service->recordJobArtBattle($character, 'normal', $battle);
        $service->recordExplorationRequest($character, 'normal', 1, [
            'result' => 'victory',
            'material_drop' => [(object) ['unexpected' => true]],
        ], ['danger_rate' => null, 'stamina' => null]);

        $this->assertDatabaseCount('gameplay_metrics', 0);
    }

    private function createCharacter(?User $user = null): Character
    {
        $user ??= User::factory()->create(['role' => 'user']);

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '計測冒険者'.str()->random(6),
            'current_hp' => 100,
            'current_mp' => 10,
        ]);
    }

    private function createArt(): Skill
    {
        $job = JobClass::query()->create([
            'key' => 'metric-job-'.str()->random(6),
            'name' => '計測職',
            'rank' => 'basic',
            'max_job_level' => 10,
        ]);

        return Skill::query()->create([
            'job_id' => $job->id,
            'name' => '計測の構え',
            'skill_type' => 'job_art',
            'learn_rank' => 1,
        ]);
    }
}
