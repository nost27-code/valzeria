<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            'new' => '次の自分の行動開始まで、受けるダメージを20%軽減する。奥義または大技の予告中に発動した場合は、20%軽減の代わりに、その予告行動のダメージを35%軽減する',
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

    public function up(): void
    {
        $this->apply('new');
    }

    public function down(): void
    {
        $this->apply('old');
    }

    private function apply(string $direction): void
    {
        if (! Schema::hasTable('skills')) {
            return;
        }

        DB::transaction(function () use ($direction): void {
            $targets = DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('learn_rank', 5)
                ->whereIn('job_id', array_keys(self::TEXTS));
            $targetCount = (clone $targets)->count();

            if ($targetCount === 0) {
                return;
            }
            if ($targetCount !== count(self::TEXTS)) {
                throw new RuntimeException(sprintf(
                    'Rank5 v6.1 guard rebalance %s aborted: expected %d target rows, found %d.',
                    $direction,
                    count(self::TEXTS),
                    $targetCount,
                ));
            }

            foreach (self::TEXTS as $jobId => $texts) {
                $query = DB::table('skills')
                    ->where('job_id', $jobId)
                    ->where('learn_rank', 5)
                    ->where('skill_type', 'job_art');
                $count = (clone $query)->count();
                if ($count !== 1) {
                    throw new RuntimeException(sprintf(
                        'Rank5 v6.1 guard rebalance %s aborted: expected exactly one row for %d:5, found %d.',
                        $direction,
                        $jobId,
                        $count,
                    ));
                }

                $values = [
                    'memo' => $texts[$direction],
                    'description' => $texts[$direction],
                ];
                if (Schema::hasColumn('skills', 'updated_at')) {
                    $values['updated_at'] = now();
                }
                $query->update($values);
            }
        });
    }
};
