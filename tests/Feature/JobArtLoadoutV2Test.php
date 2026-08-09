<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\JobArtService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2PrototypeCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JobArtLoadoutV2Test extends TestCase
{
    private JobArtService $service;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('character_job_art_slots');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');

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

        DB::table('job_classes')->insert([
            ['id' => 24, 'name' => '司祭'],
            ['id' => 53, 'name' => '星詠み賢者'],
            ['id' => 62, 'name' => '竜冠槍将'],
            ['id' => 85, 'name' => '星律神官'],
            ['id' => 20, 'name' => '継承職'],
            ['id' => 90, 'name' => '対象外職'],
        ]);
        DB::table('skills')->insert([
            $this->skillRow(101, 24, 1, 5),
            $this->skillRow(103, 24, 3, 1),
            $this->skillRow(105, 24, 5, 5),
            $this->skillRow(109, 24, 9, 5),
            $this->skillRow(201, 20, 1, 1),
            $this->skillRow(202, 20, 5, 2),
            $this->skillRow(203, 20, 9, 3),
            $this->skillRow(204, 20, 9, 4),
            $this->skillRow(205, 20, 9, 5),
            $this->skillRow(531, 53, 1, 5),
            $this->skillRow(535, 53, 5, 5),
            $this->skillRow(539, 53, 9, 5),
            $this->skillRow(621, 62, 1, 5),
            $this->skillRow(625, 62, 5, 5),
            $this->skillRow(629, 62, 9, 5),
            $this->skillRow(851, 85, 1, 5),
            $this->skillRow(855, 85, 5, 5),
            $this->skillRow(859, 85, 9, 5),
            $this->skillRow(901, 90, 1, 1),
            $this->skillRow(905, 90, 5, 2),
            $this->skillRow(909, 90, 9, 2),
        ]);

        $this->character = new Character(['id' => 1, 'current_job_id' => 24]);
        $this->character->exists = true;
        $this->service = new class extends JobArtService
        {
            public function availableArts(Character $character, string $context = 'pve'): Collection
            {
                return Skill::query()
                    ->with('jobClass')
                    ->orderBy('id')
                    ->get()
                    ->each(function (Skill $skill) use ($character): void {
                        $skill->setAttribute(
                            'job_art_origin',
                            (int) $skill->job_id === (int) $character->current_job_id ? 'current' : 'inherited'
                        );
                        $skill->setAttribute('job_art_rate', 1.0);
                        $skill->setAttribute('job_art_effective_cost', $this->effectiveArtCostFor($character, $skill));
                    });
            }
        };
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('character_job_art_slots');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('job_classes');

        parent::tearDown();
    }

    public function test_flag_off_keeps_three_slots_cost_five_and_ignores_without_deleting_later_rows(): void
    {
        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->insertSlots('normal', [101, 201, 202, 203, 204]);

        $this->assertSame(3, $this->service->maxSlots());
        $this->assertSame(5, $this->service->maxCost());
        $this->assertSame([101, 201, 202], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
        $evaluated = $this->service->evaluateLoadoutSlots(
            $this->character,
            CharacterJobArtSlot::query()->with('skill')->where('battle_context', 'normal')->get()
        );
        $this->assertSame([null, null, null, 'slot_limit', 'slot_limit'], $evaluated->pluck('job_art_inactive_reason')->all());
        $this->assertSame(5, DB::table('character_job_art_slots')->count());
        $this->assertDatabaseHas('character_job_art_slots', ['battle_context' => 'normal', 'slot_no' => 4, 'skill_id' => 203]);
        $this->assertDatabaseHas('character_job_art_slots', ['battle_context' => 'normal', 'slot_no' => 5, 'skill_id' => 204]);

        try {
            $this->service->saveSlots($this->character, [1 => 201, 4 => 204]);
            $this->fail('The legacy save path must reject a submitted fourth slot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slots', $exception->errors());
        }
        $this->assertSame(5, DB::table('character_job_art_slots')->count());

        $this->service->setSlot($this->character, 'normal', 1, null);
        $this->assertSame([201, 202], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
        $this->assertSame(4, DB::table('character_job_art_slots')->count());
        $this->assertDatabaseHas('character_job_art_slots', ['battle_context' => 'normal', 'slot_no' => 4, 'skill_id' => 203]);
        $this->assertDatabaseHas('character_job_art_slots', ['battle_context' => 'normal', 'slot_no' => 5, 'skill_id' => 204]);

        config(['battle.job_art_v2.loadout_v2' => true]);
        $this->assertCount(4, $this->service->selectedSlots($this->character, 'pve', 'normal'));
        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertSame(4, DB::table('character_job_art_slots')->count());
    }

    public function test_flag_on_uses_five_slots_cost_nine_and_current_job_rank_costs(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);

        $rankOne = Skill::findOrFail(101);
        $rankFive = Skill::findOrFail(105);
        $rankNine = Skill::findOrFail(109);
        $inherited = Skill::findOrFail(204);

        $this->assertSame(5, $this->service->maxSlots());
        $this->assertSame(9, $this->service->maxCost());
        $this->assertSame(1, $this->service->effectiveArtCostFor($this->character, $rankOne));
        $this->assertSame(2, $this->service->effectiveArtCostFor($this->character, $rankFive));
        $this->assertSame(3, $this->service->effectiveArtCostFor($this->character, $rankNine));
        $this->assertSame(4, $this->service->effectiveArtCostFor($this->character, $inherited));

        $unknownJob = new Character(['id' => 2]);
        $this->assertSame(5, $this->service->effectiveArtCostFor($unknownJob, $rankOne));
    }

    public function test_slot_conditions_round_trip_through_display_battle_and_incremental_save(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);

        $this->service->saveSlots(
            $this->character,
            [1 => 101, 2 => 105, 3 => 109],
            'normal',
            'pve',
            [1 => 'normal', 2 => 'normal', 3 => 'normal'],
            [1 => 'main_resource_lt_4', 2 => 'target_hp_le_50', 3 => 'always'],
        );

        $this->assertSame(
            ['main_resource_lt_4', 'target_hp_le_50', 'always'],
            $this->service->selectedSlots($this->character, 'pve', 'normal')
                ->pluck('job_art_slot_condition')
                ->all(),
        );
        $this->assertSame(
            ['main_resource_lt_4', 'target_hp_le_50', 'always'],
            $this->service->battleArtsFor($this->character, 'pve')
                ->pluck('job_art_slot_condition')
                ->all(),
        );

        $this->service->setSlot(
            $this->character,
            'normal',
            2,
            105,
            'normal',
            'target_def_gt_spr',
        );
        $this->assertSame(
            'target_def_gt_spr',
            $this->service->selectedSlots($this->character, 'pve', 'normal')
                ->firstWhere('slot_no', 2)?->getAttribute('job_art_slot_condition'),
        );

        $signature = $this->service->setupSignature($this->character);
        $this->service->setSlot($this->character, 'normal', 2, 105, 'normal', 'target_spr_gt_def');
        $this->assertNotSame($signature, $this->service->setupSignature($this->character));
    }

    public function test_unknown_persisted_condition_fails_closed_to_always_without_rewriting_database(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);
        $this->insertSlots('normal', [101]);
        DB::table('character_job_art_slots')->where('character_id', 1)->update([
            'condition_key' => 'removed_future_condition',
        ]);

        $slot = $this->service->selectedSlots($this->character, 'pve', 'normal')->first();

        $this->assertSame('always', $slot?->getAttribute('job_art_slot_condition'));
        $this->assertSame(
            'removed_future_condition',
            DB::table('character_job_art_slots')->where('character_id', 1)->value('condition_key'),
        );
    }

    public function test_condition_migration_preserves_existing_rows_on_up_and_down(): void
    {
        Schema::table('character_job_art_slots', function (Blueprint $table): void {
            $table->dropColumn('condition_key');
        });
        DB::table('character_job_art_slots')->insert([
            'character_id' => 1,
            'battle_context' => 'normal',
            'slot_no' => 1,
            'skill_id' => 101,
            'activation_policy' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::create('job_art_preset_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('slot_no');
            $table->unsignedBigInteger('skill_id');
            $table->string('activation_policy', 20)->default('normal');
        });
        DB::table('job_art_preset_slots')->insert(['slot_no' => 1, 'skill_id' => 101]);
        $migration = require database_path('migrations/2026_08_09_120000_add_condition_key_to_job_art_slots.php');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('character_job_art_slots', 'condition_key'));
        $this->assertTrue(Schema::hasColumn('job_art_preset_slots', 'condition_key'));
        $this->assertSame('always', DB::table('character_job_art_slots')->value('condition_key'));
        $this->assertSame('always', DB::table('job_art_preset_slots')->value('condition_key'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('character_job_art_slots', 'condition_key'));
        $this->assertFalse(Schema::hasColumn('job_art_preset_slots', 'condition_key'));
        $this->assertSame(1, DB::table('character_job_art_slots')->count());
        $this->assertSame(1, DB::table('job_art_preset_slots')->count());
        Schema::dropIfExists('job_art_preset_slots');
    }

    public function test_slot_conditions_are_not_changed_when_outer_loadout_transaction_rolls_back(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);
        $this->service->saveSlots(
            $this->character,
            [1 => 101],
            'normal',
            'pve',
            [1 => 'normal'],
            [1 => 'self_hp_le_50'],
        );

        try {
            DB::transaction(function (): void {
                $this->service->saveSlots(
                    $this->character,
                    [1 => 105],
                    'normal',
                    'pve',
                    [1 => 'normal'],
                    [1 => 'target_hp_le_30'],
                );

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertSame(101, (int) $this->character->jobArtSlots()->where('battle_context', 'normal')->value('skill_id'));
        $this->assertSame('self_hp_le_50', (string) $this->character->jobArtSlots()->where('battle_context', 'normal')->value('condition_key'));
        $this->assertDatabaseMissing('character_job_art_slots', ['character_id' => 1, 'skill_id' => 105]);
    }

    public function test_manual_save_accepts_cost_nine_and_rejects_cost_ten_without_overwriting(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);

        $this->service->saveSlots($this->character, [1 => 101, 2 => 105, 3 => 109, 4 => 201, 5 => 202]);
        $this->assertSame([101, 105, 109, 201, 202], $this->storedSkillIds('normal'));
        $displaySlots = $this->service->selectedSlots($this->character, 'pve', 'normal');
        $battleArts = $this->service->battleArtsFor($this->character, 'pve');
        $this->assertSame([1, 2, 3, 1, 2], $displaySlots->pluck('job_art_effective_cost')->all());
        $this->assertSame([1, 2, 3, 1, 2], $battleArts->pluck('job_art_effective_cost')->all());

        try {
            $this->service->saveSlots($this->character, [1 => 109, 2 => 204, 3 => 203]);
            $this->fail('Cost10 must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slots', $exception->errors());
        }

        $this->assertSame([101, 105, 109, 201, 202], $this->storedSkillIds('normal'));
    }

    public function test_existing_over_limit_rows_pause_from_first_overflow_and_never_reenable_later_slots(): void
    {
        config(['battle.job_art_v2.loadout_v2' => true]);
        $this->insertSlots('normal', [109, 204, 203, 201, 202]);

        $slots = $this->service->selectedSlots($this->character, 'pve', 'normal');

        $this->assertSame([null, null, 'cost_limit', 'cost_limit', 'cost_limit'], $slots->pluck('job_art_inactive_reason')->all());
        $this->assertSame([109, 204], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
        $this->assertSame(5, DB::table('character_job_art_slots')->count());
    }

    public function test_normal_boss_and_pvp_use_the_same_non_destructive_pause_rule(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.pvp_set' => true,
        ]);

        foreach (['normal', 'boss', 'pvp'] as $context) {
            $this->insertSlots($context, [109, 204, 203, 201, 202]);
        }

        $this->assertSame([109, 204], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
        $this->assertSame([109, 204], $this->service->battleArtsFor($this->character, 'boss')->pluck('id')->all());
        $this->assertSame([109, 204], $this->service->battleArtsFor($this->character, 'champ')->pluck('id')->all());
        $this->assertSame(15, DB::table('character_job_art_slots')->count());
    }

    public function test_v2_trusted_current_job_chain_can_share_a_legacy_group_in_every_set_and_reload(): void
    {
        $this->enableRestrictionCompatibility();
        $this->setLimitGroup([101, 105, 109], 'HEAL');
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $this->assertTrue(app(JobArtV2FeatureGate::class)->usesLoadoutRestrictionCompatibilityForCurrentJob(24));
        foreach (Skill::query()->whereIn('id', [101, 105, 109])->get() as $skill) {
            $this->assertTrue($catalog->isTrustedCurrentJobArt(24, $skill));
        }

        foreach (['normal' => 'pve', 'boss' => 'boss', 'pvp' => 'champ'] as $slotContext => $availabilityContext) {
            $this->service->saveSlots(
                $this->character,
                [1 => 101, 2 => 105, 3 => 109],
                $slotContext,
                $availabilityContext
            );

            $this->assertSame([101, 105, 109], $this->storedSkillIds($slotContext));
            $this->assertSame(
                [101, 105, 109],
                $this->service->selectedSlots($this->character, $availabilityContext, $slotContext)
                    ->pluck('skill_id')
                    ->all()
            );
        }

        $this->assertSame([101, 105, 109], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
        $this->assertSame([101, 105, 109], $this->service->battleArtsFor($this->character, 'boss')->pluck('id')->all());
        $this->assertSame([101, 105, 109], $this->service->battleArtsFor($this->character, 'champ')->pluck('id')->all());
    }

    public function test_v2_restriction_compatibility_keeps_cost_nine_and_rejects_cost_ten_without_overwriting(): void
    {
        $this->enableRestrictionCompatibility();
        $this->setLimitGroup([101, 105, 109], 'HEAL');

        $this->service->saveSlots($this->character, [1 => 101, 2 => 105, 3 => 109, 4 => 203]);
        $this->assertSame([101, 105, 109, 203], $this->storedSkillIds('normal'));
        $this->assertSame(9, $this->service->totalCost(
            $this->service->selectedSlots($this->character, 'pve', 'normal')->pluck('skill')->filter()->values()
        ));

        try {
            $this->service->saveSlots($this->character, [1 => 101, 2 => 105, 3 => 109, 4 => 204]);
            $this->fail('Cost10 must remain rejected when the restriction exception applies.');
        } catch (ValidationException $exception) {
            $this->assertSame('奥義コストの合計は9までです。', $exception->errors()['slots'][0]);
        }

        $this->assertSame([101, 105, 109, 203], $this->storedSkillIds('normal'));
    }

    public function test_loadout_or_dynamic_flag_off_keeps_the_legacy_restriction(): void
    {
        $this->setLimitGroup([101, 105, 109], 'HEAL');
        DB::table('skills')->whereIn('id', [101, 105, 109])->update(['art_cost' => 1]);

        foreach ([[false, true], [true, false]] as [$loadoutV2, $dynamicSingle]) {
            config([
                'battle.job_art_v2.loadout_v2' => $loadoutV2,
                'battle.job_art_v2.dynamic_single' => $dynamicSingle,
            ]);

            try {
                $this->service->saveSlots($this->character, [1 => 101, 2 => 105, 3 => 109]);
                $this->fail('Legacy HEAL restriction must remain enabled when either dependency is off.');
            } catch (ValidationException $exception) {
                $this->assertSame('回復系の奥義は1つまでしか設定できません。', $exception->errors()['slots'][0]);
            }
        }
    }

    public function test_inherited_unregistered_and_unsupported_arts_never_receive_the_exception(): void
    {
        $this->enableRestrictionCompatibility();
        $this->setLimitGroup([101, 105, 201, 103], 'HEAL');

        foreach ([[101, 201], [101, 103]] as $skillIds) {
            try {
                $this->service->saveSlots($this->character, array_combine(range(1, count($skillIds)), $skillIds));
                $this->fail('Only trusted current-job Rank1/5/9 arts may share a restriction group.');
            } catch (ValidationException $exception) {
                $this->assertSame('回復系の奥義は1つまでしか設定できません。', $exception->errors()['slots'][0]);
            }
        }

        $unsupported = new Character(['id' => 2, 'current_job_id' => 90]);
        $unsupported->exists = true;
        $this->setLimitGroup([901, 905, 909], 'HEAL');

        try {
            $this->service->saveSlots($unsupported, [1 => 901, 2 => 905, 3 => 909]);
            $this->fail('Unsupported current jobs must retain legacy restrictions.');
        } catch (ValidationException $exception) {
            $this->assertSame('回復系の奥義は1つまでしか設定できません。', $exception->errors()['slots'][0]);
        }
    }

    public function test_every_supported_prototype_job_uses_the_same_trusted_rank_chain_rule(): void
    {
        $this->enableRestrictionCompatibility();

        foreach ([53 => [531, 535, 539], 62 => [621, 625, 629], 85 => [851, 855, 859]] as $jobId => $skillIds) {
            $character = new Character(['id' => $jobId, 'current_job_id' => $jobId]);
            $character->exists = true;
            $this->setLimitGroup($skillIds, 'HEAL');
            $this->service->saveSlots($character, array_combine(range(1, count($skillIds)), $skillIds));

            $this->assertSame(
                $skillIds,
                DB::table('character_job_art_slots')
                    ->where('character_id', $jobId)
                    ->where('battle_context', 'normal')
                    ->orderBy('slot_no')
                    ->pluck('skill_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all()
            );
        }
    }

    public function test_restriction_exception_does_not_consume_rng_or_change_battle_order(): void
    {
        $this->enableRestrictionCompatibility();
        $this->setLimitGroup([101, 105, 109], 'HEAL');

        srand(7319);
        $expectedNextRandom = rand();
        srand(7319);
        $this->service->saveSlots($this->character, [1 => 101, 2 => 105, 3 => 109]);

        $this->assertSame($expectedNextRandom, rand());
        $this->assertSame([101, 105, 109], $this->service->battleArtsFor($this->character, 'pve')->pluck('id')->all());
    }

    private function skillRow(int $id, int $jobId, int $learnRank, int $artCost): array
    {
        return [
            'id' => $id,
            'job_id' => $jobId,
            'name' => "試験奥義{$id}",
            'skill_type' => 'job_art',
            'learn_rank' => $learnRank,
            'art_cost' => $artCost,
            'limit_group' => null,
        ];
    }

    private function enableRestrictionCompatibility(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.pvp_set' => true,
        ]);
    }

    private function setLimitGroup(array $skillIds, string $group): void
    {
        DB::table('skills')->whereIn('id', $skillIds)->update(['limit_group' => $group]);
    }

    private function insertSlots(string $context, array $skillIds): void
    {
        foreach (array_values($skillIds) as $index => $skillId) {
            DB::table('character_job_art_slots')->insert([
                'character_id' => 1,
                'battle_context' => $context,
                'slot_no' => $index + 1,
                'skill_id' => $skillId,
                'activation_policy' => 'normal',
                'created_at' => '2026-08-06 13:00:00',
                'updated_at' => '2026-08-06 13:00:00',
            ]);
        }
    }

    private function storedSkillIds(string $context): array
    {
        return DB::table('character_job_art_slots')
            ->where('battle_context', $context)
            ->orderBy('slot_no')
            ->pluck('skill_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
