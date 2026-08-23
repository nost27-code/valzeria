<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecondJobArtReplacementWave2BMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const NEW_NAMES = [
        '2:9' => '穿貫',
        '3:1' => '影狩りの構え',
        '3:5' => '急所狙い',
        '4:1' => '精密射撃',
        '5:1' => '崩し打ち',
        '5:5' => '連環崩打',
    ];

    /** @var array<string, string> */
    private const OLD_NAMES = [
        '2:9' => '巨人断ち',
        '3:1' => 'すり抜け',
        '3:5' => '不意打ち',
        '4:1' => '足止め矢',
        '5:1' => '気合拳',
        '5:5' => '連打',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
        $this->assertSame(282, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    public function test_up_replaces_exactly_six_natural_keys_preserves_ids_and_references_and_is_idempotent(): void
    {
        $migration = $this->migration();
        $seededSpCosts = collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => (int) $this->row($jobId, $rank)->sp_cost_fixed];
        })->all();
        $this->assertSame([
            '2:9' => 42,
            '3:1' => 8,
            '3:5' => 16,
            '4:1' => 8,
            '5:1' => 6,
            '5:5' => 20,
        ], $seededSpCosts, 'JobArtSeeder must use the explicit master SP costs.');
        $migration->down();

        $ids = collect($this->targetRows())->map(fn (array $row): int => (int) $row['id'])->all();
        $referencesBefore = $this->createSlotAndPresetReferences($ids);
        $unaffectedBefore = $this->semanticRow(2, 5);
        $phrasesBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_phrase'])->all();
        $descriptionsBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_description'])->all();

        $migration->up();
        $afterFirst = $this->targetRows();
        $migration->up();

        $migratedSpCosts = collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => (int) $this->row($jobId, $rank)->sp_cost_fixed];
        })->all();

        $this->assertSame(self::NEW_NAMES, $this->targetNames());
        $this->assertSame($seededSpCosts, $migratedSpCosts, 'Seeder and wave 2-B migration must agree on fixed SP.');
        $this->assertSame($afterFirst, $this->targetRows());
        $this->assertSame($ids, collect($afterFirst)->map(fn (array $row): int => (int) $row['id'])->all());
        $this->assertSame($referencesBefore, $this->storedSlotAndPresetReferences());
        $this->assertSame($unaffectedBefore, $this->semanticRow(2, 5), '2:5 is outside wave 2-B.');
        $this->assertSame($phrasesBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_phrase'])->all());
        $this->assertSame($descriptionsBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_description'])->all());
        $this->assertCount(6, $afterFirst);

        $this->assertSame(['PHYSICAL_DAMAGE', 225, 1, 1, 'physical', 42], $this->effectTuple(2, 9));
        $this->assertSame(['DAMAGE_DEBUFF', 90, 1, 3, 'physical', 8], $this->effectTuple(3, 1));
        $this->assertSame(['PHYSICAL_DAMAGE', 145, 1, 1, 'physical', 16], $this->effectTuple(3, 5));
        $this->assertSame(['PHYSICAL_DAMAGE', 90, 1, 1, 'physical', 8], $this->effectTuple(4, 1));
        $this->assertSame(['DAMAGE_DEBUFF', 90, 1, 3, 'physical', 6], $this->effectTuple(5, 1));
        $this->assertSame(['DAMAGE_DEBUFF', 145, 3, 3, 'physical', 20], $this->effectTuple(5, 5));

        $this->assertSame(50, (int) $this->row(2, 9)->def_ignore_percent);
        $this->assertSame(15, (int) $this->row(3, 1)->enemy_spd_down_percent);
        $this->assertSame(15, (int) $this->row(5, 1)->enemy_def_down_percent);
        $this->assertSame(15, (int) $this->row(5, 5)->enemy_def_down_percent);
        $this->assertSame(15, (int) $this->row(5, 5)->enemy_spr_down_percent);

        foreach ([[2, 9], [3, 5], [4, 1]] as [$jobId, $rank]) {
            $row = $this->row($jobId, $rank);
            $this->assertSame(0, (int) $row->self_buff_percent);
            $this->assertSame(0, (int) $row->enemy_def_down_percent);
            $this->assertSame(0, (int) $row->enemy_spr_down_percent);
            $this->assertSame(0, (int) $row->enemy_spd_down_percent);
        }
    }

    public function test_down_restores_the_exact_pre_wave_effect_columns(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertSame(self::OLD_NAMES, $this->targetNames());
        $this->assertSame(['DAMAGE_BUFF', 225, 1, 1, 'physical', 42], $this->effectTuple(2, 9));
        $this->assertSame(['SELF_BUFF', 90, 1, 2, 'support', 8], $this->effectTuple(3, 1));
        $this->assertSame(['DAMAGE_BUFF', 145, 1, 2, 'physical', 16], $this->effectTuple(3, 5));
        $this->assertSame(['DAMAGE_DEBUFF', 90, 1, 3, 'physical', 8], $this->effectTuple(4, 1));
        $this->assertSame(['DAMAGE_BUFF', 90, 1, 2, 'physical', 6], $this->effectTuple(5, 1));
        $this->assertSame(['MULTI_HIT', 145, 3, 2, 'physical', 20], $this->effectTuple(5, 5));
        $this->assertSame(10, (int) $this->row(4, 1)->enemy_spd_down_percent);
        $this->assertSame(0, (int) $this->row(2, 9)->def_ignore_percent);
    }

    public function test_up_rejects_duplicate_or_missing_natural_keys_and_rolls_back_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        $duplicate = (array) $this->row(5, 5);
        unset($duplicate['id']);
        $duplicate['name'] = '連打・重複検証';
        DB::table('skills')->insert($duplicate);

        try {
            $migration->up();
            $this->fail('The migration did not reject the duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 5:5, found 2', $exception->getMessage());
        }
        $this->assertSame('巨人断ち', (string) $this->row(2, 9)->name, 'Earlier updates must roll back.');

        DB::table('skills')
            ->where('job_id', 5)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->where('name', '連打・重複検証')
            ->delete();
        DB::table('skills')
            ->where('job_id', 5)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject the missing natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 5:5, found 0', $exception->getMessage());
        }
        $this->assertSame('巨人断ち', (string) $this->row(2, 9)->name, 'Earlier updates must roll back.');
    }

    public function test_up_and_down_are_no_ops_before_the_job_art_master_is_seeded(): void
    {
        DB::table('skills')->where('skill_type', 'job_art')->delete();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    /** @return array{string, int, int, int, string, int} */
    private function effectTuple(int $jobId, int $rank): array
    {
        $row = $this->row($jobId, $rank);

        return [
            (string) $row->effect_template,
            (int) $row->power,
            (int) $row->hit_count,
            (int) $row->duration_turns,
            (string) $row->damage_type,
            (int) $row->sp_cost_fixed,
        ];
    }

    /** @return array<string, string> */
    private function targetNames(): array
    {
        return collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => (string) $this->row($jobId, $rank)->name];
        })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function targetRows(): array
    {
        return collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => $this->semanticRow($jobId, $rank)];
        })->all();
    }

    /** @return array<string, mixed> */
    private function semanticRow(int $jobId, int $rank): array
    {
        $values = (array) $this->row($jobId, $rank);
        unset($values['created_at'], $values['updated_at']);

        return $values;
    }

    private function row(int $jobId, int $rank): object
    {
        $row = DB::table('skills')
            ->where('job_id', $jobId)
            ->where('learn_rank', $rank)
            ->where('skill_type', 'job_art')
            ->first();

        $this->assertNotNull($row, "Missing Job Art {$jobId}:{$rank}");

        return $row;
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_23_120000_replace_job_arts_wave2_2b.php');
    }

    /** @param array<string, int> $skillIds
     *  @return array{loadout:list<int>,preset:list<int>}
     */
    private function createSlotAndPresetReferences(array $skillIds): array
    {
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '2-B参照確認者',
            'current_job_id' => 2,
            'hp_base' => 100,
            'current_hp' => 100,
        ]);

        foreach (array_chunk(array_values($skillIds), 3) as $contextIndex => $contextSkillIds) {
            $context = $contextIndex === 0 ? 'normal' : 'boss';
            $presetId = DB::table('job_art_presets')->insertGetId([
                'character_id' => $character->id,
                'name' => '2-B参照'.($contextIndex + 1),
                'current_job_id' => 2,
                'source_context' => $context,
                'sp_policy' => 'aggressive',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($contextSkillIds as $slotIndex => $skillId) {
                DB::table('character_job_art_slots')->insert([
                    'character_id' => $character->id,
                    'battle_context' => $context,
                    'slot_no' => $slotIndex + 1,
                    'skill_id' => $skillId,
                    'activation_policy' => 'normal',
                    'condition_key' => 'always',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('job_art_preset_slots')->insert([
                    'job_art_preset_id' => $presetId,
                    'slot_no' => $slotIndex + 1,
                    'skill_id' => $skillId,
                    'activation_policy' => 'normal',
                    'condition_key' => 'always',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $this->storedSlotAndPresetReferences();
    }

    /** @return array{loadout:list<int>,preset:list<int>} */
    private function storedSlotAndPresetReferences(): array
    {
        return [
            'loadout' => DB::table('character_job_art_slots')->orderBy('skill_id')->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
            'preset' => DB::table('job_art_preset_slots')->orderBy('skill_id')->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }
}
