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
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 4;
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
        ]];

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
        $this->assertSame(2, data_get($jobArtMetric->payload, 'version'));
        $this->assertSame(1, data_get($jobArtMetric->payload, 'skills.0.vital_hit_count'));
        $this->assertSame(2, $analysis['exploration']['cards']['requests']);
        $this->assertSame(51, $analysis['exploration']['cards']['requested_runs']);
        $this->assertSame(41, $analysis['exploration']['cards']['completed_runs']);
        $this->assertSame('1回探索', $analysis['exploration']['modeRows'][0]['label']);
        $this->assertSame(100.0, $analysis['exploration']['modeRows'][0]['equipment_per_100']);
        $this->assertSame('HP低下', $analysis['exploration']['stopRows'][0]['label']);
    }

    public function test_admin_and_tester_characters_are_not_recorded_and_page_is_admin_only(): void
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
        GameplayMetric::query()->create([
            'character_id' => $similarEmailCharacter->id,
            'metric_type' => GameplayMetric::TYPE_JOB_ART_BATTLE,
            'context' => 'pvp',
            'result' => 'victory',
            'payload' => [
                'version' => 1,
                'turn_count' => 2,
                'activation_count' => 2,
                'skills' => [[
                    'skill_id' => $legacyArt->id,
                    'name' => $legacyArt->name,
                    'origin' => 'current',
                    'activation_count' => 2,
                    'hit_count' => 1,
                    'miss_count' => 1,
                    'evade_count' => 0,
                    'no_resolution_count' => 0,
                ]],
            ],
            'created_at' => now(),
        ]);
        $this->assertDatabaseCount('gameplay_metrics', 3);
        $analysis = app(GameplayAnalyticsService::class)->analyze('all');
        $this->assertSame(2, $analysis['jobArt']['cards']['battles']);
        $this->assertSame(50.0, $analysis['jobArt']['skillRows'][0]['hit_rate']);
        $this->assertSame(0, $analysis['jobArt']['skillRows'][0]['vital_hits']);
        $this->assertSame(0.0, $analysis['jobArt']['skillRows'][0]['vital_hit_rate']);

        $this->get(route('admin.gameplay-analytics'))->assertRedirect();
        $normalUser = User::factory()->create(['role' => 'user']);
        $this->actingAs($normalUser)->get(route('admin.gameplay-analytics'))->assertRedirect('/admin/login');
        $this->actingAs($admin)->get(route('admin.gameplay-analytics'))
            ->assertOk()
            ->assertSee('戦技・探索実績')
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
