<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecondJobArtReplacementWave2CPhase1MigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const NEW_NAMES = ['1:5' => '受け返し', '17:1' => '影伏せ'];

    /** @var array<string, string> */
    private const OLD_NAMES = ['1:5' => '連斬', '17:1' => '煙玉'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
        $this->assertSame(282, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    public function test_up_replaces_exactly_two_natural_keys_preserves_ids_and_references_and_is_idempotent(): void
    {
        $migration = $this->migration();
        $migration->down();

        $ids = collect($this->targetRows())->map(fn (array $row): int => (int) $row['id'])->all();
        $referencesBefore = $this->createSlotAndPresetReferences($ids);
        $unaffectedBefore = $this->semanticRow(17, 5);
        $phrasesBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_phrase'])->all();
        $descriptionsBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_description'])->all();

        $migration->up();
        $afterFirst = $this->targetRows();
        $migration->up();

        $this->assertSame(self::NEW_NAMES, $this->targetNames());
        $this->assertSame($afterFirst, $this->targetRows());
        $this->assertSame($ids, collect($afterFirst)->map(fn (array $row): int => (int) $row['id'])->all());
        $this->assertSame($referencesBefore, $this->storedSlotAndPresetReferences());
        $this->assertSame($unaffectedBefore, $this->semanticRow(17, 5));
        $this->assertSame($phrasesBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_phrase'])->all());
        $this->assertSame($descriptionsBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_description'])->all());
        $this->assertSame(['PHYSICAL_DAMAGE', 145, 1, 1, 'physical', 20], $this->effectTuple(1, 5));
        $this->assertSame(['PHYSICAL_DAMAGE', 100, 1, 1, 'physical', 8], $this->effectTuple(17, 1));
        $this->assertSame(0, (int) $this->row(1, 5)->self_buff_percent);
        $this->assertSame(0, (int) $this->row(17, 1)->enemy_spd_down_percent);
    }

    public function test_down_restores_the_exact_pre_replacement_effect_columns(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertSame(self::OLD_NAMES, $this->targetNames());
        $this->assertSame(['MULTI_HIT', 145, 2, 2, 'physical', 20], $this->effectTuple(1, 5));
        $this->assertSame(['ENEMY_DEBUFF', 100, 1, 2, 'support', 8], $this->effectTuple(17, 1));
        $this->assertSame(10, (int) $this->row(17, 1)->enemy_spd_down_percent);
    }

    public function test_up_rejects_duplicate_or_missing_natural_keys_and_rolls_back_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        $duplicate = (array) $this->row(17, 1);
        unset($duplicate['id']);
        $duplicate['name'] = '煙玉・重複検証';
        DB::table('skills')->insert($duplicate);

        try {
            $migration->up();
            $this->fail('The migration did not reject the duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 17:1, found 2', $exception->getMessage());
        }
        $this->assertSame('連斬', (string) $this->row(1, 5)->name, 'Earlier updates must roll back.');

        DB::table('skills')
            ->where('job_id', 17)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject the missing natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 17:1, found 0', $exception->getMessage());
        }
        $this->assertSame('連斬', (string) $this->row(1, 5)->name, 'Earlier updates must roll back.');
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
        return require base_path('database/migrations/2026_08_25_180000_replace_job_arts_wave2_2c_phase1.php');
    }

    /** @param array<string, int> $skillIds
     * @return array{loadout:list<int>,preset:list<int>}
     */
    private function createSlotAndPresetReferences(array $skillIds): array
    {
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => '2-C参照確認者',
            'current_job_id' => 17,
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $presetId = DB::table('job_art_presets')->insertGetId([
            'character_id' => $character->id,
            'name' => '2-C参照',
            'current_job_id' => 17,
            'source_context' => 'normal',
            'sp_policy' => 'aggressive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_values($skillIds) as $slotIndex => $skillId) {
            DB::table('character_job_art_slots')->insert([
                'character_id' => $character->id,
                'battle_context' => 'normal',
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
