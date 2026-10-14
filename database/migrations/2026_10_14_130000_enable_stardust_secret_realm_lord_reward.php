<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('materials')->where('material_code', 'ACC0005')->update([
            'main_use' => '装飾品SSS→EPIC進化',
            'obtain_method' => 'ルミナス・セレスティア・魔王城ヴァルゼリアの探索中に出会う秘境主を倒すと、5%の確率で1個入手できます。',
            'usage_summary' => '装飾品SSS→EPICの進化に1個使います。進化には魔王撃破も必要です。',
            'acquisition_summary' => 'ルミナス・セレスティア・魔王城ヴァルゼリアの秘境主撃破時に5%で1個。',
            'acquisition_tags' => json_encode(['secret_realm_lord']),
            'drop_timing' => 'secret_realm_lord',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 入手済みの素材と、正しい入手案内は保持する。
    }
};
