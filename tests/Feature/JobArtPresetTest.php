<?php

namespace Tests\Feature;

use App\Http\Controllers\JobArtPresetController;
use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\JobArtPreset;
use App\Models\Skill;
use App\Models\User;
use App\Services\JobArtPresetLimitProvider;
use App\Services\JobArtPresetService;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2PrototypeCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class JobArtPresetTest extends TestCase
{
    private JobArtService $jobArtService;

    private JobArtPresetService $presetService;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('current_job_id')->nullable();
            $table->timestamps();
        });
        Schema::create('job_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('name');
            $table->string('skill_type')->default('job_art');
            $table->unsignedTinyInteger('learn_rank')->default(1);
            $table->unsignedTinyInteger('art_cost')->default(0);
            $table->string('limit_group')->nullable();
        });
        Schema::create('character_job_art_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->string('battle_context', 20);
            $table->unsignedTinyInteger('slot_no');
            $table->unsignedBigInteger('skill_id');
            $table->string('activation_policy', 20)->default('normal');
            $table->string('condition_key', 40)->default('always');
            $table->timestamps();
            $table->unique(['character_id', 'battle_context', 'slot_no']);
            $table->unique(['character_id', 'battle_context', 'skill_id']);
        });
        $this->presetMigration()->up();
        $this->conditionMigration()->up();

        DB::table('job_classes')->insert([
            ['id' => 24, 'name' => '司祭'],
            ['id' => 53, 'name' => '星詠み賢者'],
            ['id' => 62, 'name' => '竜冠槍将'],
            ['id' => 85, 'name' => '星律神官'],
            ['id' => 20, 'name' => '継承職'],
            ['id' => 90, 'name' => '対象外職'],
        ]);
        DB::table('characters')->insert([
            ['id' => 1, 'user_id' => 1, 'current_job_id' => 24],
            ['id' => 2, 'user_id' => 1, 'current_job_id' => 24],
            ['id' => 3, 'user_id' => 2, 'current_job_id' => 24],
            ['id' => 4, 'user_id' => 1, 'current_job_id' => 90],
        ]);
        DB::table('skills')->insert([
            $this->skillRow(101, 24, 1, 5),
            $this->skillRow(105, 24, 5, 5),
            $this->skillRow(109, 24, 9, 5),
            $this->skillRow(201, 20, 1, 5),
            $this->skillRow(202, 20, 5, 5),
            $this->skillRow(203, 20, 9, 1),
            $this->skillRow(204, 20, 1, 1),
            $this->skillRow(205, 20, 5, 2),
            $this->skillRow(901, 90, 1, 1),
        ]);

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.pvp_set' => true,
            'battle.job_art_v2.presets' => true,
            'battle.job_art_v2.preset_free_limit' => 3,
        ]);

        $this->character = Character::query()->findOrFail(1);
        $this->jobArtService = new class extends JobArtService
        {
            public array $excludedSkillIds = [];

            public function availableArts(Character $character, string $context = 'pve'): Collection
            {
                return Skill::query()
                    ->whereNotIn('id', $this->excludedSkillIds)
                    ->orderBy('id')
                    ->get()
                    ->each(function (Skill $skill) use ($character): void {
                        $skill->setAttribute('job_art_origin', (int) $skill->job_id === (int) $character->current_job_id ? 'current' : 'inherited');
                        $skill->setAttribute('job_art_effective_cost', $this->effectiveArtCostFor($character, $skill));
                    });
            }
        };
        $this->presetService = new JobArtPresetService(
            $this->jobArtService,
            new JobArtV2FeatureGate(new JobArtV2PrototypeCatalog()),
            new JobArtPresetLimitProvider(),
        );
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_feature_gate_is_fail_closed_for_flag_and_unsupported_job(): void
    {
        $config = require config_path('battle.php');
        $this->assertFalse($config['job_art_v2']['presets']);
        $this->assertTrue($this->presetService->enabledFor($this->character));
        config(['battle.job_art_v2.presets' => false]);
        $this->assertFalse($this->presetService->enabledFor($this->character));
        $this->expectException(NotFoundHttpException::class);
        $this->presetService->createFromCurrentLoadout($this->character, '拒否', 'normal');
    }

    public function test_loadout_flag_and_unsupported_job_are_also_fail_closed(): void
    {
        foreach ([24, 53, 60, 61, 62, 64, 65, 66, 67, 68, 69, 85] as $jobId) {
            $supported = new Character(['current_job_id' => $jobId]);
            $this->assertTrue($this->presetService->enabledFor($supported));
        }

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertFalse($this->presetService->enabledFor($this->character));

        config(['battle.job_art_v2.loadout_v2' => true]);
        $unsupported = Character::query()->findOrFail(4);
        $this->assertFalse($this->presetService->enabledFor($unsupported));
    }

    public function test_saves_raw_five_slots_order_policies_and_source_for_all_contexts(): void
    {
        foreach (['normal', 'boss', 'pvp'] as $context) {
            $this->insertLoadout($this->character, $context, [101, 105, 109, 201, 202], ['aggressive', 'normal', 'conserve', 'normal', 'aggressive']);
            $preset = $this->presetService->createFromCurrentLoadout($this->character, "{$context}構成", $context);

            $this->assertSame($context, $preset->source_context);
            $this->assertSame([101, 105, 109, 201, 202], $preset->slots->pluck('skill_id')->all());
            $this->assertSame([1, 2, 3, 4, 5], $preset->slots->pluck('slot_no')->all());
            $this->assertSame(['aggressive', 'normal', 'conserve', 'normal', 'aggressive'], $preset->slots->pluck('activation_policy')->all());
        }

        $this->assertDatabaseCount('job_art_presets', 3);
        $presetColumns = Schema::getColumnListing('job_art_presets');
        $slotColumns = Schema::getColumnListing('job_art_preset_slots');
        foreach (['cost', 'sp_cost', 'resource', 'field', 'stance', 'restriction_group'] as $derivedColumn) {
            $this->assertNotContains($derivedColumn, $presetColumns);
            $this->assertNotContains($derivedColumn, $slotColumns);
        }
    }

    public function test_free_limit_allows_three_rejects_fourth_but_rename_and_delete_reopen_capacity(): void
    {
        $this->insertLoadout($this->character, 'normal', [101]);
        $presets = [];
        foreach (['一', '二', '三'] as $name) {
            $presets[] = $this->presetService->createFromCurrentLoadout($this->character, $name, 'normal');
        }

        $this->assertSame(3, $this->presetService->limitFor($this->character));
        try {
            $this->presetService->createFromCurrentLoadout($this->character, '四', 'normal');
            $this->fail('Fourth preset must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preset', $exception->errors());
        }

        $this->presetService->rename($this->character, $presets[0]->id, '  更新名  ');
        $this->assertDatabaseHas('job_art_presets', ['id' => $presets[0]->id, 'name' => '更新名']);
        $this->presetService->delete($this->character, $presets[1]->id);
        $this->presetService->createFromCurrentLoadout($this->character, '再作成', 'normal');
        $this->assertDatabaseCount('job_art_presets', 3);
    }

    public function test_name_validation_and_duplicate_names(): void
    {
        $this->insertLoadout($this->character, 'normal', [101]);
        foreach (['', str_repeat('あ', 21)] as $invalidName) {
            try {
                $this->presetService->createFromCurrentLoadout($this->character, $invalidName, 'normal');
                $this->fail('Invalid names must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }

        $this->presetService->createFromCurrentLoadout($this->character, '同名', 'normal');
        $this->presetService->createFromCurrentLoadout($this->character, '同名', 'normal');
        $this->assertSame(2, JobArtPreset::query()->where('name', '同名')->count());
    }

    public function test_applies_same_preset_to_normal_boss_and_pvp_with_all_slots_and_policies(): void
    {
        $this->insertLoadout($this->character, 'normal', [101, 105, 109], ['aggressive', 'normal', 'conserve']);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '共通構成', 'normal');

        foreach (['normal', 'boss', 'pvp'] as $target) {
            $this->replaceLoadout($this->character, $target, [203]);
            $this->presetService->apply($this->character, $preset->id, $target);
            $this->assertSame([101, 105, 109], $this->storedSkillIds($this->character, $target));
            $this->assertSame(['aggressive', 'normal', 'conserve'], $this->storedPolicies($this->character, $target));
        }
    }

    public function test_preset_persists_conditions_and_applies_them_to_every_context(): void
    {
        $conditions = ['self_hp_le_50', 'target_hp_le_30', 'target_def_gt_spr'];
        $this->insertLoadout($this->character, 'normal', [101, 105, 109], [], $conditions);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '条件保存', 'normal');
        $this->assertSame($conditions, $preset->slots->sortBy('slot_no')->pluck('condition_key')->all());

        foreach (['normal', 'boss', 'pvp'] as $context) {
            $this->replaceLoadout($this->character, $context, [203]);
            $this->presetService->apply($this->character, $preset->id, $context);
            $this->assertSame($conditions, $this->storedConditions($this->character, $context));
        }
    }

    public function test_unknown_preset_condition_applies_as_always_without_rewriting_preset_row(): void
    {
        $this->insertLoadout($this->character, 'normal', [101], [], ['self_hp_le_50']);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '未知条件', 'normal');
        $preset->slots()->where('slot_no', 1)->update(['condition_key' => 'retired_condition']);

        $this->presetService->apply($this->character, $preset->id, 'boss');

        $this->assertSame(['always'], $this->storedConditions($this->character, 'boss'));
        $this->assertSame(
            'retired_condition',
            $preset->slots()->where('slot_no', 1)->value('condition_key'),
        );
    }

    public function test_mixed_cost_nine_preset_round_trips_all_five_slots_without_persisting_lineage_metadata(): void
    {
        $skillIds = [101, 105, 109, 204, 205];
        $policies = ['aggressive', 'normal', 'conserve', 'aggressive', 'normal'];
        $this->insertLoadout($this->character, 'normal', $skillIds, $policies);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '混成Cost9', 'normal');

        foreach (['normal', 'boss', 'pvp'] as $target) {
            $this->replaceLoadout($this->character, $target, [203]);
            $this->presetService->apply($this->character, $preset->id, $target);
            $this->assertSame($skillIds, $this->storedSkillIds($this->character, $target), $target);
            $this->assertSame($policies, $this->storedPolicies($this->character, $target), $target);
        }

        foreach (['lineage', 'lineage_key', 'resource_key', 'source_lineage'] as $derivedColumn) {
            $this->assertNotContains($derivedColumn, Schema::getColumnListing('job_art_preset_slots'));
        }
    }

    public function test_job_change_rejects_without_deleting_then_original_job_can_reuse(): void
    {
        $conditions = ['main_resource_lt_4', 'main_resource_ge_4', 'target_hp_le_30'];
        $this->insertLoadout($this->character, 'normal', [101, 105, 109], [], $conditions);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '司祭', 'normal');
        $this->character->forceFill(['current_job_id' => 53])->save();

        $this->replaceLoadout($this->character, 'boss', [203]);
        $this->assertValidationFailureKeepsLoadout(fn () => $this->presetService->apply($this->character, $preset->id, 'boss'), [203], 'boss');
        $this->assertDatabaseHas('job_art_presets', ['id' => $preset->id]);
        $display = $this->presetService->presetsForDisplay($this->character, 'boss')[0];
        $this->assertFalse($display['can_apply']);
        $this->assertStringContainsString('職業', $display['unavailable_reason']);

        $this->character->forceFill(['current_job_id' => 24])->save();
        $this->presetService->apply($this->character, $preset->id, 'boss');
        $this->assertSame([101, 105, 109], $this->storedSkillIds($this->character, 'boss'));
        $this->assertSame($conditions, $this->storedConditions($this->character, 'boss'));
    }

    public function test_cost_and_restriction_are_recalculated_and_failed_apply_is_atomic(): void
    {
        $this->insertLoadout($this->character, 'normal', [101]);
        $costPreset = $this->presetWithSlots('Cost超過', [201, 202]);
        $this->replaceLoadout($this->character, 'normal', [101]);
        $this->assertValidationFailureKeepsLoadout(fn () => $this->presetService->apply($this->character, $costPreset->id, 'normal'), [101]);
        $costDisplay = collect($this->presetService->presetsForDisplay($this->character))->firstWhere('id', $costPreset->id);
        $this->assertSame(10, $costDisplay['cost']);
        $this->assertFalse($costDisplay['can_apply']);

        DB::table('skills')->whereIn('id', [101, 201])->update(['limit_group' => 'HEAL']);
        $restrictionPreset = $this->presetWithSlots('制限違反', [101, 201]);
        $this->assertValidationFailureKeepsLoadout(fn () => $this->presetService->apply($this->character, $restrictionPreset->id, 'normal'), [101]);
    }

    public function test_unlearned_art_is_retained_as_invalid_and_does_not_change_current_loadout(): void
    {
        $preset = $this->presetWithSlots('未習得', [101, 203]);
        $this->jobArtService->excludedSkillIds = [203];
        $this->replaceLoadout($this->character, 'normal', [101]);

        $this->assertValidationFailureKeepsLoadout(fn () => $this->presetService->apply($this->character, $preset->id, 'normal'), [101]);
        $display = collect($this->presetService->presetsForDisplay($this->character))->firstWhere('id', $preset->id);
        $this->assertFalse($display['can_apply']);
        $this->assertStringContainsString('習得', $display['unavailable_reason']);
        $this->assertDatabaseHas('job_art_presets', ['id' => $preset->id]);
    }

    public function test_pr13_current_rank_chain_exception_is_reused_on_apply(): void
    {
        DB::table('skills')->whereIn('id', [101, 105, 109])->update(['limit_group' => 'HEAL']);
        $preset = $this->presetWithSlots('司祭三段', [101, 105, 109]);

        $this->presetService->apply($this->character, $preset->id, 'normal');
        $this->assertSame([101, 105, 109], $this->storedSkillIds($this->character, 'normal'));
        $this->assertSame(6, collect($this->presetService->presetsForDisplay($this->character))->firstWhere('id', $preset->id)['cost']);
    }

    public function test_delete_and_rename_never_modify_active_loadout(): void
    {
        $this->insertLoadout($this->character, 'normal', [101, 105]);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '保持', 'normal');
        $this->presetService->rename($this->character, $preset->id, '名前だけ');
        $this->assertSame([101, 105], $this->storedSkillIds($this->character, 'normal'));
        $this->presetService->delete($this->character, $preset->id);
        $this->assertSame([101, 105], $this->storedSkillIds($this->character, 'normal'));
    }

    public function test_character_scoping_blocks_listing_apply_update_delete_and_other_user(): void
    {
        $this->insertLoadout($this->character, 'normal', [101]);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '所有者のみ', 'normal');

        foreach ([Character::findOrFail(2), Character::findOrFail(3)] as $intruder) {
            $this->assertSame([], $this->presetService->presetsForDisplay($intruder));
            foreach (['apply', 'rename', 'delete'] as $operation) {
                try {
                    match ($operation) {
                        'apply' => $this->presetService->apply($intruder, $preset->id, 'normal'),
                        'rename' => $this->presetService->rename($intruder, $preset->id, '侵入'),
                        'delete' => $this->presetService->delete($intruder, $preset->id),
                    };
                    $this->fail("IDOR {$operation} must fail.");
                } catch (ModelNotFoundException) {
                    $this->assertTrue(true);
                }
            }
        }

        $this->assertDatabaseHas('job_art_presets', ['id' => $preset->id, 'name' => '所有者のみ']);
    }

    public function test_preset_operations_do_not_consume_random_numbers(): void
    {
        $this->insertLoadout($this->character, 'normal', [101, 105]);
        srand(9173);
        $expected = rand();
        srand(9173);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '乱数不変', 'normal');
        $this->presetService->rename($this->character, $preset->id, '乱数不変2');
        $this->presetService->apply($this->character, $preset->id, 'boss');
        $this->presetService->delete($this->character, $preset->id);
        $this->assertSame($expected, rand());
    }

    public function test_ui_shows_zero_of_three_escapes_names_and_has_no_auto_apply(): void
    {
        $html = view('job-arts.partials.presets', [
            'jobArtPresets' => [],
            'jobArtPresetLimit' => 3,
        ])->render();
        $this->assertStringContainsString('0 / 3', $html);
        $this->assertStringContainsString('現在の構成を保存', $html);

        $html = view('job-arts.partials.presets', [
            'jobArtPresets' => [[
                'id' => 1,
                'name' => '<script>alert(1)</script>',
                'source_context' => 'normal',
                'slot_count' => 3,
                'cost' => 6,
                'can_apply' => true,
                'unavailable_reason' => null,
                'application_statuses' => [
                    'normal' => ['can_apply' => true, 'reason' => null],
                    'boss' => ['can_apply' => true, 'reason' => null],
                    'pvp' => ['can_apply' => true, 'reason' => null],
                ],
            ]],
            'jobArtPresetLimit' => 3,
        ])->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('自動適用', $html);

        $page = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $this->assertStringContainsString('@if($jobArtPresetUiEnabled ?? false)', $page);
        $this->assertStringContainsString("job-arts.partials.presets", $page);
    }

    public function test_migration_up_and_down_only_manage_preset_tables(): void
    {
        $migration = $this->presetMigration();
        $this->assertTrue(Schema::hasTable('job_art_presets'));
        $this->assertTrue(Schema::hasTable('job_art_preset_slots'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('job_art_presets'));
        $this->assertFalse(Schema::hasTable('job_art_preset_slots'));
        $this->assertTrue(Schema::hasTable('character_job_art_slots'));
        $migration->up();
    }

    public function test_battle_runtime_does_not_reference_presets(): void
    {
        $paths = [
            ...glob(app_path('Services/*Battle*.php')),
            ...glob(app_path('Services/Battle/*.php')),
            app_path('Services/ExplorationService.php'),
            app_path('Http/Controllers/BattleController.php'),
        ];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('JobArtPreset', $source, $path);
            $this->assertStringNotContainsString('job_art_presets', $source, $path);
        }
    }

    public function test_direct_route_is_rejected_when_feature_is_off(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        $character = new Character(['id' => 1, 'current_job_id' => 24]);
        $character->exists = true;
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'role' => 'player']);
        $user->exists = true;
        $user->shouldReceive('currentCharacter')->once()->andReturn($character);
        $this->actingAs($user);

        $service = Mockery::mock(JobArtPresetService::class);
        $service->shouldReceive('enabledFor')->once()->with($character)->andReturnFalse();
        $service->shouldNotReceive('createFromCurrentLoadout');
        $this->app->instance(JobArtPresetService::class, $service);
        $this->app['router']->post('/_test/job-arts/presets', [JobArtPresetController::class, 'store'])->middleware('web');

        $this->post('/_test/job-arts/presets', ['name' => '拒否', 'slot_context' => 'normal'])->assertNotFound();
    }

    public function test_direct_routes_cannot_apply_update_or_delete_another_characters_preset(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        $this->insertLoadout($this->character, 'normal', [101]);
        $preset = $this->presetService->createFromCurrentLoadout($this->character, '所有者', 'normal');
        $intruder = Character::query()->findOrFail(2);
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'role' => 'player']);
        $user->exists = true;
        $user->shouldReceive('currentCharacter')->times(3)->andReturn($intruder);
        $this->actingAs($user);
        $this->app->instance(JobArtPresetService::class, $this->presetService);

        $this->app['router']->post('/_test/presets/{preset}/apply', [JobArtPresetController::class, 'apply'])->middleware('web');
        $this->app['router']->patch('/_test/presets/{preset}', [JobArtPresetController::class, 'update'])->middleware('web');
        $this->app['router']->delete('/_test/presets/{preset}', [JobArtPresetController::class, 'destroy'])->middleware('web');

        $this->post("/_test/presets/{$preset->id}/apply", ['slot_context' => 'normal'])->assertNotFound();
        $this->patch("/_test/presets/{$preset->id}", ['name' => '侵入'])->assertNotFound();
        $this->delete("/_test/presets/{$preset->id}")->assertNotFound();
        $this->assertDatabaseHas('job_art_presets', ['id' => $preset->id, 'name' => '所有者']);
    }

    private function skillRow(int $id, int $jobId, int $rank, int $cost): array
    {
        return [
            'id' => $id,
            'job_id' => $jobId,
            'name' => "試験戦技{$id}",
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'art_cost' => $cost,
            'limit_group' => null,
        ];
    }

    private function insertLoadout(Character $character, string $context, array $skillIds, array $policies = [], array $conditions = []): void
    {
        foreach (array_values($skillIds) as $index => $skillId) {
            CharacterJobArtSlot::create([
                'character_id' => $character->id,
                'battle_context' => $context,
                'slot_no' => $index + 1,
                'skill_id' => $skillId,
                'activation_policy' => $policies[$index] ?? 'normal',
                'condition_key' => $conditions[$index] ?? 'always',
            ]);
        }
    }

    private function replaceLoadout(Character $character, string $context, array $skillIds): void
    {
        $character->jobArtSlots()->where('battle_context', $context)->delete();
        $this->insertLoadout($character, $context, $skillIds);
    }

    private function presetWithSlots(string $name, array $skillIds): JobArtPreset
    {
        $preset = $this->character->jobArtPresets()->create([
            'name' => $name,
            'current_job_id' => 24,
            'source_context' => 'normal',
        ]);
        foreach (array_values($skillIds) as $index => $skillId) {
            $preset->slots()->create([
                'slot_no' => $index + 1,
                'skill_id' => $skillId,
                'activation_policy' => 'normal',
            ]);
        }

        return $preset->load('slots.skill');
    }

    private function storedSkillIds(Character $character, string $context): array
    {
        return $character->jobArtSlots()->where('battle_context', $context)->orderBy('slot_no')->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function storedPolicies(Character $character, string $context): array
    {
        return $character->jobArtSlots()->where('battle_context', $context)->orderBy('slot_no')->pluck('activation_policy')->all();
    }

    private function storedConditions(Character $character, string $context): array
    {
        return $character->jobArtSlots()->where('battle_context', $context)->orderBy('slot_no')->pluck('condition_key')->all();
    }

    private function assertValidationFailureKeepsLoadout(callable $operation, array $expectedSkillIds, string $context = 'normal'): void
    {
        try {
            $operation();
            $this->fail('Preset apply must fail validation.');
        } catch (ValidationException) {
            $this->assertSame($expectedSkillIds, $this->storedSkillIds($this->character, $context));
        }
    }

    private function presetMigration(): object
    {
        return require database_path('migrations/2026_08_07_120000_create_job_art_preset_tables.php');
    }

    private function conditionMigration(): object
    {
        return require database_path('migrations/2026_08_09_120000_add_condition_key_to_job_art_slots.php');
    }

    private function dropTables(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['job_art_preset_slots', 'job_art_presets', 'character_job_art_slots', 'skills', 'job_classes', 'characters'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
