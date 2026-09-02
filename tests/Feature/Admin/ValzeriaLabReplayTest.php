<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ValzeriaLabReplay;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\CharacterJobArtSlot;
use App\Models\City;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\User;
use App\Services\Admin\ValzeriaLabReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class ValzeriaLabReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_anonymous_snapshot_and_seed_reproduce_the_same_result(): void
    {
        [$character, $enemy] = $this->battlePair();
        $service = app(ValzeriaLabReplayService::class);

        $snapshot = $service->capture($character, $enemy, 'pve', 73_001);
        $json = $service->encode($snapshot);
        $loaded = $service->decode($json);

        $first = $service->presentResult($service->executeSnapshot($loaded));
        $second = $service->presentResult($service->executeSnapshot($loaded));

        $this->assertSame($first, $second);
        $this->assertSame($first['logs'], $second['logs']);
        $this->assertSame($first['hp_after'], $second['hp_after']);
        $this->assertSame($first['sp_after'], $second['sp_after']);
        $this->assertSame($first['exp'], $second['exp']);
        $this->assertSame($first['gold'], $second['gold']);
        $this->assertSame($first['job_exp'], $second['job_exp']);
        $this->assertGreaterThan(0, $first['turn_count']);
    }

    public function test_snapshot_is_anonymous_and_replay_does_not_change_persistent_data(): void
    {
        [$character, $enemy] = $this->battlePair();
        $character->forceFill([
            'current_hp' => 117,
            'current_mp' => 9,
            'wins' => 14,
            'losses' => 6,
            'money' => 12_345,
            'total_score' => 99_001,
        ])->save();
        $beforeCharacter = $character->fresh()->getAttributes();
        $beforeCounts = $this->persistentTableCounts();
        $service = app(ValzeriaLabReplayService::class);

        $snapshot = $service->capture($character, $enemy, 'boss', 99);
        $json = $service->encode($snapshot);
        $result = $service->executeSnapshot($service->decode($json));

        $this->assertSame('匿名冒険者', $snapshot['character']['label']);
        $this->assertStringNotContainsString($character->name, $json);
        $this->assertStringNotContainsString($character->user->email, $json);
        foreach (['"user_id"', '"character_id"', '"email"', '"password"', '"remember_token"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertContains($result->result, ['victory', 'defeat', 'timeout']);
        $this->assertSame($beforeCharacter, $character->fresh()->getAttributes());
        $this->assertSame($beforeCounts, $this->persistentTableCounts());
    }

    public function test_selected_job_art_is_captured_and_uses_the_seeded_random_scope(): void
    {
        [$character, $enemy] = $this->battlePair();
        $job = JobClass::query()
            ->whereHas('jobArts', fn ($query) => $query
                ->where('effect_template', 'PHYSICAL_DAMAGE')
                ->where('pve_enabled', true))
            ->orderBy('id')
            ->firstOrFail();
        $skill = $job->jobArts()
            ->where('effect_template', 'PHYSICAL_DAMAGE')
            ->where('pve_enabled', true)
            ->orderBy('learn_rank')
            ->orderBy('id')
            ->firstOrFail();
        $skill->forceFill([
            'trigger_rate' => 100,
            'activation_rate' => 100,
        ])->save();
        $character->forceFill([
            'current_job_id' => $job->id,
            'mp_base' => 100,
            'current_mp' => 100,
        ])->save();
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $job->id,
            'job_level' => max(1, (int) $skill->learn_rank),
            'job_exp' => 0,
            'is_mastered' => false,
        ]);
        CharacterJobArtSlot::query()->create([
            'character_id' => $character->id,
            'slot_no' => 1,
            'skill_id' => $skill->id,
            'battle_context' => 'normal',
            'activation_policy' => 'aggressive',
            'condition_key' => 'always',
        ]);
        $service = app(ValzeriaLabReplayService::class);

        $snapshot = $service->capture($character->fresh(), $enemy, 'pve', 8_808);
        $first = $service->presentResult($service->executeSnapshot($snapshot));
        $second = $service->presentResult($service->executeSnapshot($snapshot));

        $this->assertCount(1, $snapshot['character']['job_arts']);
        $this->assertSame($skill->name, $snapshot['character']['job_arts'][0]['attributes']['name']);
        $this->assertSame($first, $second);
        $this->assertStringContainsString($skill->name, implode("\n", $first['logs']));
    }

    public function test_import_rejects_personal_keys_html_and_unknown_schema(): void
    {
        [$character, $enemy] = $this->battlePair();
        $service = app(ValzeriaLabReplayService::class);
        $snapshot = $service->capture($character, $enemy, 'pve', 1);

        $withPersonalKey = $snapshot;
        $withPersonalKey['character']['user_id'] = 123;
        $this->assertInvalidSnapshot($service, $withPersonalKey);

        $withHtml = $snapshot;
        $withHtml['enemy']['attributes']['name'] = '<img src=x onerror=alert(1)>';
        $this->assertInvalidSnapshot($service, $withHtml);

        $wrongSchema = $snapshot;
        $wrongSchema['schema'] = 'valzeria-lab-battle-snapshot/v2';
        $this->assertInvalidSnapshot($service, $wrongSchema);

        $unboundedEnemyHits = $snapshot;
        $unboundedEnemyHits['enemy']['actions'][] = [
            'master_id' => 1,
            'attributes' => [
                'name' => '過剰連撃',
                'action_key' => 'unbounded-hits',
                'action_type' => 'multi_attack',
                'hit_count' => PHP_INT_MAX,
            ],
        ];
        $this->assertInvalidSnapshot($service, $unboundedEnemyHits);

        $unboundedJobArtHits = $snapshot;
        $unboundedJobArtHits['character']['job_arts'][] = [
            'master_id' => 1,
            'attributes' => [
                'name' => '過剰連撃',
                'hit_count' => PHP_INT_MAX,
            ],
            'runtime' => [],
        ];
        $this->assertInvalidSnapshot($service, $unboundedJobArtHits);
    }

    public function test_admin_can_capture_and_run_a_representative_scenario_from_livewire(): void
    {
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        [$character, $enemy] = $this->battlePair();

        Livewire::actingAs($admin)
            ->test(ValzeriaLabReplay::class)
            ->set('selectedCharacterId', $character->id)
            ->set('selectedEnemyId', $enemy->id)
            ->set('battleType', 'pve')
            ->set('seed', 424_242)
            ->call('captureAndRun')
            ->assertHasNoErrors()
            ->assertSet('snapshot.schema', ValzeriaLabReplayService::SNAPSHOT_SCHEMA)
            ->assertSet('snapshot.character.label', '匿名冒険者')
            ->assertSet('snapshot.seed', 424_242)
            ->assertSet('result.result', fn ($value): bool => in_array($value, ['victory', 'defeat', 'timeout'], true))
            ->assertSee('戦闘ログ')
            ->assertSee('算出報酬（未付与）');
    }

    /** @return array{Character, Enemy} */
    private function battlePair(): array
    {
        $user = User::factory()->create([
            'name' => '再現元アカウント',
            'email' => 'snapshot-owner@example.test',
        ]);
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '秘密の再現元Character',
            'level' => 20,
            'hp_base' => 320,
            'mp_base' => 35,
            'attack_base' => 180,
            'defense_base' => 45,
            'speed_base' => 32,
            'magic_base' => 20,
            'spirit_base' => 30,
            'luck_base' => 12,
            'current_hp' => 280,
            'current_mp' => 27,
        ]);
        $city = City::query()->where('is_initial', true)->firstOrFail();
        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Lab再現エリア',
            'slug' => 'lab-replay-area',
            'recommended_level_min' => 1,
            'recommended_level_max' => 20,
            'is_published' => true,
        ]);
        $enemy = Enemy::query()->create([
            'area_id' => $area->id,
            'name' => '再現用スライム',
            'level' => 5,
            'max_hp' => 180,
            'str' => 22,
            'def' => 10,
            'agi' => 12,
            'mag' => 8,
            'spr' => 8,
            'luk' => 5,
            'exp_reward' => 21,
            'job_exp_reward' => 2,
            'gold_reward' => 15,
            'appearance_weight' => 10,
            'is_boss' => false,
        ]);

        return [$character, $enemy];
    }

    /** @return array<string, int> */
    private function persistentTableCounts(): array
    {
        $tables = [
            'character_items',
            'character_materials',
            'character_area_progresses',
            'battle_logs',
            'champ_battle_logs',
            'arena_logs',
            'arena_rankings',
            'gold_transactions',
            'job_art_v2_battle_telemetry_logs',
            'public_logs',
        ];

        return collect($tables)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    private function assertInvalidSnapshot(ValzeriaLabReplayService $service, array $snapshot): void
    {
        try {
            $service->decode(json_encode($snapshot, JSON_THROW_ON_ERROR));
            $this->fail('Invalid snapshot was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
