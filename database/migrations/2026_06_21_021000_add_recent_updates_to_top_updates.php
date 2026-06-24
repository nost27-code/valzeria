<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('top_updates')) {
            return;
        }

        $now = now();
        $updates = [
            [
                'published_on' => '2026-06-21',
                'body' => 'ホーム画面に「次やること」を追加し、今できる行動が分かりやすくなりました',
                'sort_order' => 10,
            ],
            [
                'published_on' => '2026-06-21',
                'body' => '冒険者市場で素材を匿名売買できるようになりました',
                'sort_order' => 20,
            ],
            [
                'published_on' => '2026-06-21',
                'body' => '調達依頼で素材を納品し、ゴールド報酬を受け取れるようになりました',
                'sort_order' => 30,
            ],
            [
                'published_on' => '2026-06-21',
                'body' => '素材の詳細画面で用途や入手先を確認できるようになりました',
                'sort_order' => 40,
            ],
            [
                'published_on' => '2026-06-21',
                'body' => '素材売買・納品・装備売却に確認表示を追加し、誤操作しにくくしました',
                'sort_order' => 50,
            ],
            [
                'published_on' => '2026-06-21',
                'body' => 'スマホ向けのヘッダー、街カード、ボトムナビ、冒険者メニューを調整しました',
                'sort_order' => 60,
            ],
        ];

        foreach ($updates as $update) {
            DB::table('top_updates')->updateOrInsert(
                [
                    'published_on' => $update['published_on'],
                    'body' => $update['body'],
                ],
                [
                    'sort_order' => $update['sort_order'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('top_updates')) {
            return;
        }

        DB::table('top_updates')
            ->where('published_on', '2026-06-21')
            ->whereIn('body', [
                'ホーム画面に「次やること」を追加し、今できる行動が分かりやすくなりました',
                '冒険者市場で素材を匿名売買できるようになりました',
                '調達依頼で素材を納品し、ゴールド報酬を受け取れるようになりました',
                '素材の詳細画面で用途や入手先を確認できるようになりました',
                '素材売買・納品・装備売却に確認表示を追加し、誤操作しにくくしました',
                'スマホ向けのヘッダー、街カード、ボトムナビ、冒険者メニューを調整しました',
            ])
            ->delete();
    }
};
