<?php

namespace Tests\Feature;

use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecondJobArtReplacementWave2AMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const NEW_NAMES = [
        '6:5' => '天測の陣',
        '17:9' => '狩猟の完成',
        '19:9' => '魂喰らい',
        '33:9' => '崩落',
    ];

    /** @var array<string, string> */
    private const OLD_NAMES = [
        '6:5' => '火炎弾',
        '17:9' => '瞬影乱舞',
        '19:9' => 'ルーン強奪',
        '33:9' => '武神降臨',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
        $this->assertSame(282, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    public function test_up_replaces_exactly_four_natural_keys_preserves_ids_and_is_idempotent(): void
    {
        $migration = $this->migration();
        $ids = collect($this->targetRows())->map(fn (array $row): int => (int) $row['id'])->all();
        $unaffectedBefore = $this->semanticRow(19, 5);
        $phrasesBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_phrase'])->all();
        $descriptionsBefore = collect($this->targetRows())->map(fn (array $row): ?string => $row['activation_description'])->all();

        $migration->down();
        $this->assertSame(self::OLD_NAMES, $this->targetNames());
        $this->assertSame($ids, collect($this->targetRows())->map(fn (array $row): int => (int) $row['id'])->all());

        $migration->up();
        $afterFirst = $this->targetRows();
        $migration->up();

        $this->assertSame(self::NEW_NAMES, $this->targetNames());
        $this->assertSame($afterFirst, $this->targetRows());
        $this->assertSame($ids, collect($afterFirst)->map(fn (array $row): int => (int) $row['id'])->all());
        $this->assertSame($unaffectedBefore, $this->semanticRow(19, 5), '19:5 is outside wave 2-A.');
        $this->assertSame($phrasesBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_phrase'])->all());
        $this->assertSame($descriptionsBefore, collect($afterFirst)->map(fn (array $row): ?string => $row['activation_description'])->all());
        $this->assertCount(4, $afterFirst);

        $this->assertSame(['MAGICAL_DAMAGE', 145, 1, 2, 'magical'], $this->effectTuple(6, 5));
        $this->assertSame(['PHYSICAL_DAMAGE', 255, 1, 1, 'physical'], $this->effectTuple(17, 9));
        $this->assertSame(['DRAIN', 255, 1, 1, 'magical'], $this->effectTuple(19, 9));
        $this->assertSame(['DAMAGE_DEBUFF', 315, 1, 5, 'physical'], $this->effectTuple(33, 9));

        $hunt = $this->row(17, 9);
        $this->assertSame(0, (int) $hunt->self_buff_percent);
        $this->assertSame(0.0, (float) $hunt->drain_hp_rate);

        $eclipse = $this->row(19, 9);
        $this->assertSame(0.35, (float) $eclipse->drain_hp_rate);
        $this->assertSame(0, (int) $eclipse->mp_recover_percent);
        $this->assertSame(0, (int) $eclipse->self_buff_percent);
        $this->assertSame(42, (int) $eclipse->sp_cost_fixed, 'Changing to DRAIN must not change the pre-existing fixed SP cost.');

        $collapse = $this->row(33, 9);
        $this->assertSame(25, (int) $collapse->enemy_def_down_percent);
        $this->assertSame(25, (int) $collapse->enemy_spr_down_percent);
    }

    public function test_down_restores_the_exact_pre_wave_effect_columns(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertSame(['MAGICAL_DAMAGE', 145, 1, 2, 'magical'], $this->effectTuple(6, 5));
        $this->assertSame(['DAMAGE_BUFF', 255, 4, 1, 'physical'], $this->effectTuple(17, 9));
        $this->assertSame(['DAMAGE_BUFF', 255, 1, 1, 'physical'], $this->effectTuple(19, 9));
        $this->assertSame(['DAMAGE_DEBUFF', 315, 2, 3, 'physical'], $this->effectTuple(33, 9));
        $this->assertSame(0.0, (float) $this->row(19, 9)->drain_hp_rate);
        $this->assertSame(20, (int) $this->row(33, 9)->enemy_def_down_percent);
        $this->assertSame(10, (int) $this->row(33, 9)->enemy_spr_down_percent);
    }

    public function test_up_rejects_duplicate_natural_keys_and_rolls_back_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        $duplicate = (array) $this->row(33, 9);
        unset($duplicate['id']);
        $duplicate['name'] = '崩落・重複検証';
        DB::table('skills')->insert($duplicate);

        try {
            $migration->up();
            $this->fail('The migration did not reject the duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 33:9, found 2', $exception->getMessage());
        }

        $this->assertSame('火炎弾', (string) $this->row(6, 5)->name, 'Earlier updates must roll back.');
    }

    public function test_up_rejects_a_partially_missing_target_set_and_rolls_back_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('skills')
            ->where('job_id', 33)
            ->where('learn_rank', 9)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject the missing natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 33:9, found 0', $exception->getMessage());
        }

        $this->assertSame('火炎弾', (string) $this->row(6, 5)->name, 'Earlier updates must roll back.');
    }

    public function test_up_and_down_are_no_ops_before_the_job_art_master_is_seeded(): void
    {
        DB::table('skills')->where('skill_type', 'job_art')->delete();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    /** @return array{string, int, int, int, string} */
    private function effectTuple(int $jobId, int $rank): array
    {
        $row = $this->row($jobId, $rank);

        return [
            (string) $row->effect_template,
            (int) $row->power,
            (int) $row->hit_count,
            (int) $row->duration_turns,
            (string) $row->damage_type,
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
        return require base_path('database/migrations/2026_08_20_120000_replace_job_arts_wave2_2a.php');
    }
}
