<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, int|string|bool>> */
    private const TITLES = [
        112 => [
            'category' => 'level',
            'rarity' => 'common',
            'name' => '一人前の冒険者',
            'description' => 'Lv30に到達する',
            'hint' => '冒険を重ね、Lv30を目指そう。',
            'unlock_type' => 'character_level',
            'target_type' => 'level',
            'target_id' => '30',
            'source_master' => 'キャラクターLv',
            'display_order' => 101,
            'is_hidden' => true,
        ],
        113 => [
            'category' => 'level',
            'rarity' => 'mythic',
            'name' => '極限に至りし者',
            'description' => 'Lv255に到達する',
            'hint' => '冒険者として歩み続け、最高Lvへ到達しよう。',
            'unlock_type' => 'character_level',
            'target_type' => 'level',
            'target_id' => '255',
            'source_master' => 'キャラクターLv',
            'display_order' => 110,
            'is_hidden' => true,
        ],
        114 => [
            'category' => 'battle',
            'rarity' => 'rare',
            'name' => '千勝の戦巧者',
            'description' => '探索の戦いで累計1,000勝する',
            'hint' => '勝利を重ね、累計1,000勝を目指そう。',
            'unlock_type' => 'battle_win_count',
            'target_type' => 'count',
            'target_id' => '1000',
            'source_master' => 'キャラクター戦績',
            'display_order' => 102,
            'is_hidden' => true,
        ],
        115 => [
            'category' => 'battle',
            'rarity' => 'epic',
            'name' => '常勝の冒険者',
            'description' => '探索の戦いで累計2,000勝する',
            'hint' => 'さらに勝利を重ね、累計2,000勝を目指そう。',
            'unlock_type' => 'battle_win_count',
            'target_type' => 'count',
            'target_id' => '2000',
            'source_master' => 'キャラクター戦績',
            'display_order' => 103,
            'is_hidden' => true,
        ],
        116 => [
            'category' => 'battle',
            'rarity' => 'legendary',
            'name' => '勝利を極めし者',
            'description' => '探索の戦いで累計3,000勝する',
            'hint' => '数多の戦いを制し、累計3,000勝へ到達しよう。',
            'unlock_type' => 'battle_win_count',
            'target_type' => 'count',
            'target_id' => '3000',
            'source_master' => 'キャラクター戦績',
            'display_order' => 104,
            'is_hidden' => true,
        ],
        117 => [
            'category' => 'job_rank',
            'rarity' => 'rare',
            'name' => '中級職への一歩',
            'description' => '初めて中級職に転職する',
            'hint' => '基本職を極め、中級職への道を開こう。',
            'unlock_type' => 'first_rank_job',
            'target_type' => 'rank',
            'target_id' => 'middle',
            'source_master' => '職業マスタ/転職条件',
            'display_order' => 105,
            'is_hidden' => true,
        ],
        118 => [
            'category' => 'job_rank',
            'rarity' => 'epic',
            'name' => '超級の境地に立つ者',
            'description' => '初めて超級職に転職する',
            'hint' => '上級職の先にある、超級職へ到達しよう。',
            'unlock_type' => 'first_rank_job',
            'target_type' => 'rank',
            'target_id' => 'super',
            'source_master' => '職業マスタ/転職条件',
            'display_order' => 106,
            'is_hidden' => true,
        ],
        119 => [
            'category' => 'job_rank',
            'rarity' => 'legendary',
            'name' => '冠位を戴く者',
            'description' => '初めて冠位職に転職する',
            'hint' => '冠位の証を手にし、冠位職へ到達しよう。',
            'unlock_type' => 'first_rank_job',
            'target_type' => 'rank',
            'target_id' => 'crown',
            'source_master' => '職業マスタ/転職条件',
            'display_order' => 107,
            'is_hidden' => true,
        ],
        120 => [
            'category' => 'job_rank',
            'rarity' => 'legendary',
            'name' => '英雄の道を歩む者',
            'description' => '初めて英雄職に転職する',
            'hint' => '試練を越え、英雄職へ到達しよう。',
            'unlock_type' => 'first_rank_job',
            'target_type' => 'rank',
            'target_id' => 'hero',
            'source_master' => '職業マスタ/転職条件',
            'display_order' => 108,
            'is_hidden' => true,
        ],
        121 => [
            'category' => 'job_rank',
            'rarity' => 'mythic',
            'name' => '神話に名を連ねる者',
            'description' => '初めて神話職に転職する',
            'hint' => '伝説の先へ進み、神話職へ到達しよう。',
            'unlock_type' => 'first_rank_job',
            'target_type' => 'rank',
            'target_id' => 'myth',
            'source_master' => '職業マスタ/転職条件',
            'display_order' => 109,
            'is_hidden' => true,
        ],
    ];

    /** @var array<int, array{name:string, old:string, new:string}> */
    private const RANK_TARGET_FIXES = [
        97 => ['name' => '上級職の門を開く者', 'old' => 'Advanced', 'new' => 'advanced'],
        98 => ['name' => '伝説職の継承者', 'old' => 'Legend', 'new' => 'legend'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        $this->assertTitleIdsAreAvailable();
        $this->assertNaturalKeysAreAvailable();

        $now = now();
        $rows = [];
        foreach (self::TITLES as $id => $title) {
            $rows[] = [
                'id' => $id,
                ...$title,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            $this->updateRankTargets('new');

            DB::table('titles')->upsert(
                $rows,
                ['id'],
                [
                    'category',
                    'rarity',
                    'name',
                    'description',
                    'hint',
                    'unlock_type',
                    'target_type',
                    'target_id',
                    'source_master',
                    'display_order',
                    'is_hidden',
                    'updated_at',
                ]
            );
        });
    }

    public function down(): void
    {
        // Forward-only: deleting title masters would cascade into player-owned character_titles rows.
    }

    private function assertTitleIdsAreAvailable(): void
    {
        $existing = DB::table('titles')
            ->whereIn('id', array_keys(self::TITLES))
            ->get()
            ->keyBy('id');

        foreach (self::TITLES as $id => $title) {
            $row = $existing->get($id);
            if (! $row) {
                continue;
            }

            foreach ($title as $column => $expected) {
                $actual = $row->{$column};
                $actual = match (true) {
                    is_bool($expected) => (bool) $actual,
                    is_int($expected) => (int) $actual,
                    default => (string) $actual,
                };

                if ($actual !== $expected) {
                    throw new RuntimeException(
                        "Title ID {$id} already exists with a different {$column} value."
                    );
                }
            }
        }
    }

    private function assertNaturalKeysAreAvailable(): void
    {
        foreach (self::TITLES as $id => $title) {
            $existingIds = DB::table('titles')
                ->where('unlock_type', $title['unlock_type'])
                ->where('target_type', $title['target_type'])
                ->where('target_id', $title['target_id'])
                ->pluck('id')
                ->map(static fn ($existingId): int => (int) $existingId)
                ->unique()
                ->values()
                ->all();
            $unexpectedIds = array_values(array_diff($existingIds, [$id]));

            if ($unexpectedIds !== []) {
                throw new RuntimeException(
                    "Title condition {$title['unlock_type']}/{$title['target_type']}/{$title['target_id']} also uses unexpected IDs ".implode(', ', $unexpectedIds).'.'
                );
            }
        }
    }

    private function updateRankTargets(string $direction): void
    {
        foreach (self::RANK_TARGET_FIXES as $id => $fix) {
            $row = DB::table('titles')->where('id', $id)->first(['name', 'target_id']);
            if (! $row) {
                continue;
            }

            if ((string) $row->name !== $fix['name']) {
                throw new RuntimeException("Title ID {$id} no longer matches {$fix['name']}.");
            }

            $allowedTargets = [$fix['old'], $fix['new']];
            if (! in_array((string) $row->target_id, $allowedTargets, true)) {
                throw new RuntimeException("Title ID {$id} has an unexpected rank target {$row->target_id}.");
            }

            DB::table('titles')->where('id', $id)->update([
                'target_id' => $fix[$direction],
                'updated_at' => now(),
            ]);
        }
    }
};
