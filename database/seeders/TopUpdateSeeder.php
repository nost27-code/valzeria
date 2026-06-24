<?php

namespace Database\Seeders;

use App\Models\TopUpdate;
use Illuminate\Database\Seeder;

class TopUpdateSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            [
                'published_on' => '2026-06-24',
                'body'         => '案内所（ヘルプ）の内容をリニューアルしました。タップで開くアコーディオン形式になり、遊び方の説明が読みやすくなりました',
                'sort_order'   => 10,
                'is_active'    => true,
            ],
            [
                'published_on' => '2026-06-24',
                'body'         => '奥義セット画面のUIを改善しました。セット中の奥義がカード表示になり、SP消費・発動率などが一目で確認できるようになりました',
                'sort_order'   => 20,
                'is_active'    => true,
            ],
        ];

        foreach ($entries as $entry) {
            TopUpdate::firstOrCreate(
                ['published_on' => $entry['published_on'], 'body' => $entry['body']],
                $entry
            );
        }
    }
}
