<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Rank5V6MigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{old:array<string,array<string,mixed>>,new:array<string,array<string,mixed>>} */
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
        $this->payload = json_decode(
            (string) file_get_contents(database_path('data/job_art_rank5_v6_1_migration.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(94, $this->payload['old']);
        $this->assertCount(94, $this->payload['new']);
    }

    public function test_up_and_down_update_all_94_natural_keys_and_preserve_ids_and_references(): void
    {
        $migration = $this->migration();
        $ids = $this->rankFiveIds();
        $references = $this->createReferences(array_slice(array_values($ids), 0, 5));

        $migration->down();
        $this->assertPayloadApplied($this->payload['old']);
        $this->assertSame($ids, $this->rankFiveIds());
        $this->assertSame($references, $this->storedReferences());

        $migration->up();
        $this->assertPayloadApplied($this->payload['new']);
        $this->assertSame($ids, $this->rankFiveIds());
        $this->assertSame($references, $this->storedReferences());

        config(['battle.job_art_v2.rank5_v6' => false]);
        $this->assertPayloadApplied($this->payload['new'], 'The flag does not roll back migrated master rows.');
    }

    public function test_migration_is_idempotent_and_does_not_change_rank_one_or_rank_nine(): void
    {
        $migration = $this->migration();
        $unaffected = DB::table('skills')
            ->where('skill_type', 'job_art')
            ->whereIn('learn_rank', [1, 9])
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $values = (array) $row;
                unset($values['updated_at']);

                return $values;
            })
            ->all();

        $migration->up();
        $first = $this->semanticRankFiveRows();
        $migration->up();

        $this->assertSame($first, $this->semanticRankFiveRows());
        $after = DB::table('skills')
            ->where('skill_type', 'job_art')
            ->whereIn('learn_rank', [1, 9])
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $values = (array) $row;
                unset($values['updated_at']);

                return $values;
            })
            ->all();
        $this->assertSame($unaffected, $after);
    }

    public function test_partial_rank_five_master_aborts_without_partial_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        $before = $this->row(1, 5);
        DB::table('skills')
            ->where('job_id', 99)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject a partial Rank5 master.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected 94 target rows, found 93', $exception->getMessage());
        }

        $this->assertSame((int) $before->power, (int) $this->row(1, 5)->power);
        $this->assertSame((string) $before->memo, (string) $this->row(1, 5)->memo);
    }

    public function test_up_and_down_are_no_ops_before_the_master_is_seeded(): void
    {
        DB::table('skills')->where('skill_type', 'job_art')->delete();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    /** @param array<string,array<string,mixed>> $expected */
    private function assertPayloadApplied(array $expected, string $message = ''): void
    {
        foreach ($expected as $naturalKey => $values) {
            [$jobId, $rank] = array_map('intval', explode(':', $naturalKey, 2));
            $row = $this->row($jobId, $rank);
            foreach ($values as $column => $value) {
                $actual = $row->{$column};
                if (is_float($value)) {
                    $this->assertEqualsWithDelta($value, (float) $actual, 0.000001, "{$naturalKey}.{$column} {$message}");
                } elseif (is_int($value)) {
                    $this->assertSame($value, (int) $actual, "{$naturalKey}.{$column} {$message}");
                } else {
                    $this->assertSame($value, $actual, "{$naturalKey}.{$column} {$message}");
                }
            }
        }
    }

    /** @return array<string,int> */
    private function rankFiveIds(): array
    {
        return DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->orderBy('job_id')
            ->get(['id', 'job_id'])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->job_id.':5' => (int) $row->id])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function semanticRankFiveRows(): array
    {
        return DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $values = (array) $row;
                unset($values['updated_at']);

                return $values;
            })
            ->all();
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
        return require base_path('database/migrations/2026_08_26_120000_redefine_rank5_job_arts_v6.php');
    }

    /** @param list<int> $skillIds @return array{loadout:list<int>,preset:list<int>} */
    private function createReferences(array $skillIds): array
    {
        $character = Character::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Rank5 v6.1参照確認者',
            'current_job_id' => 1,
            'hp_base' => 100,
            'current_hp' => 100,
        ]);
        $presetId = DB::table('job_art_presets')->insertGetId([
            'character_id' => $character->id,
            'name' => 'Rank5 v6.1参照',
            'current_job_id' => 1,
            'source_context' => 'normal',
            'sp_policy' => 'aggressive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($skillIds as $slot => $skillId) {
            DB::table('character_job_art_slots')->insert([
                'character_id' => $character->id,
                'battle_context' => 'normal',
                'slot_no' => $slot + 1,
                'skill_id' => $skillId,
                'activation_policy' => 'normal',
                'condition_key' => 'always',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('job_art_preset_slots')->insert([
                'job_art_preset_id' => $presetId,
                'slot_no' => $slot + 1,
                'skill_id' => $skillId,
                'activation_policy' => 'normal',
                'condition_key' => 'always',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->storedReferences();
    }

    /** @return array{loadout:list<int>,preset:list<int>} */
    private function storedReferences(): array
    {
        return [
            'loadout' => DB::table('character_job_art_slots')->orderBy('slot_no')->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
            'preset' => DB::table('job_art_preset_slots')->orderBy('slot_no')->pluck('skill_id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }
}
