<?php

namespace Tests\Feature;

use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Rank5V6GuardBalanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{old:string,new:string}> */
    private const TEXTS = [
        7 => [
            'old' => '攻撃なし。精神150%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを15%軽減する（1回）',
            'new' => '攻撃なし。精神150%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）',
        ],
        10 => [
            'old' => '威力100%の物理ダメージ。最大HP7%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを15%軽減する（1回）',
            'new' => '威力100%の物理ダメージ。最大HP7%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）',
        ],
        11 => [
            'old' => '受け流し率+20%。受け流しに成功した場合、次の自分の行動開始まで被ダメージ15%軽減',
            'new' => '受け流し率+20%。受け流しに成功した場合、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）',
        ],
        15 => [
            'old' => '被ダメージ16%軽減。対奥義/大技予告中は専用防御35%軽減へ切替',
            'new' => '受けるダメージを20%軽減する。奥義または大技の予告中に発動した場合は、20%軽減の代わりに、その予告行動のダメージを35%軽減する',
        ],
        29 => [
            'old' => '次の自分の行動開始まで被ダメージ18%軽減',
            'new' => '次の自分の行動開始まで、受けるダメージを20%軽減する',
        ],
        36 => [
            'old' => '相手の魔力を−15%する（3ターン）。次の自分の行動開始まで被ダメージ15%軽減',
            'new' => '相手の魔力を−15%する（3ターン）。次の自分の行動開始まで、受けるダメージを20%軽減する',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
    }

    public function test_up_and_down_sync_the_six_descriptions_without_replacing_rows(): void
    {
        $migration = $this->migration();
        $ids = $this->ids();
        $unaffectedMemo = (string) $this->row(44)->memo;

        $migration->down();
        $this->assertTexts('old');
        $this->assertSame($ids, $this->ids());
        $this->assertSame($unaffectedMemo, (string) $this->row(44)->memo);

        $migration->up();
        $migration->up();
        $this->assertTexts('new');
        $this->assertSame($ids, $this->ids());
        $this->assertSame($unaffectedMemo, (string) $this->row(44)->memo);
    }

    public function test_partial_target_set_aborts_before_any_text_is_changed(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('skills')
            ->where('job_id', 36)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject a partial Rank5 guard target set.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected 6 target rows, found 5', $exception->getMessage());
        }

        $this->assertSame(self::TEXTS[7]['old'], (string) $this->row(7)->memo);
    }

    public function test_up_and_down_are_no_ops_before_the_master_is_seeded(): void
    {
        DB::table('skills')->where('skill_type', 'job_art')->delete();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    private function assertTexts(string $direction): void
    {
        foreach (self::TEXTS as $jobId => $texts) {
            $row = $this->row($jobId);
            $this->assertSame($texts[$direction], (string) $row->memo, "{$jobId}:5.memo");
            $this->assertSame($texts[$direction], (string) $row->description, "{$jobId}:5.description");
        }
    }

    /** @return array<int,int> */
    private function ids(): array
    {
        return DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->whereIn('job_id', array_keys(self::TEXTS))
            ->orderBy('job_id')
            ->pluck('id', 'job_id')
            ->mapWithKeys(static fn (mixed $id, mixed $jobId): array => [(int) $jobId => (int) $id])
            ->all();
    }

    private function row(int $jobId): object
    {
        $row = DB::table('skills')
            ->where('job_id', $jobId)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->first();
        $this->assertNotNull($row, "Missing Job Art {$jobId}:5");

        return $row;
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_28_120000_rebalance_rank5_v6_guard_effects.php');
    }
}
