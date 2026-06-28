<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('skills')) {
            return;
        }

        foreach ($this->jobArtUpdates() as $update) {
            DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('job_id', $update['job_id'])
                ->where('learn_rank', $update['learn_rank'])
                ->where('name', $update['name'])
                ->update([
                    'description' => $update['description'],
                    'memo' => $update['description'],
                    'mp_recover_percent' => $update['mp_recover_percent'],
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('skills')) {
            return;
        }

        foreach ($this->legacyJobArtUpdates() as $update) {
            DB::table('skills')
                ->where('skill_type', 'job_art')
                ->where('job_id', $update['job_id'])
                ->where('learn_rank', $update['learn_rank'])
                ->where('name', $update['name'])
                ->update([
                    'description' => $update['description'],
                    'memo' => $update['description'],
                    'mp_recover_percent' => 0,
                ]);
        }
    }

    private function jobArtUpdates(): array
    {
        return [
            ['job_id' => 19, 'learn_rank' => 1, 'name' => 'マナピック', 'description' => '小魔法ダメージ＋SP小吸収', 'mp_recover_percent' => 5],
            ['job_id' => 19, 'learn_rank' => 5, 'name' => 'スピリットスティール', 'description' => 'HP/SP吸収＋敵SPR低下', 'mp_recover_percent' => 10],
            ['job_id' => 25, 'learn_rank' => 5, 'name' => '秘薬調合', 'description' => 'HP/SPを中回復。弱体があれば解除を優先', 'mp_recover_percent' => 10],
            ['job_id' => 29, 'learn_rank' => 1, 'name' => '魔力循環', 'description' => 'HP小回復＋SP小回復', 'mp_recover_percent' => 5],
            ['job_id' => 38, 'learn_rank' => 5, 'name' => '王者の秘薬', 'description' => 'HP/SP中回復＋LUK上昇', 'mp_recover_percent' => 10],
            ['job_id' => 41, 'learn_rank' => 1, 'name' => '古式錬成', 'description' => 'HP小回復＋SP小回復', 'mp_recover_percent' => 5],
            ['job_id' => 41, 'learn_rank' => 5, 'name' => '神代防壁', 'description' => 'HP大回復＋SP小回復', 'mp_recover_percent' => 5],
        ];
    }

    private function legacyJobArtUpdates(): array
    {
        return [
            ['job_id' => 19, 'learn_rank' => 1, 'name' => 'マナピック', 'description' => '小魔法ダメージ＋MP小吸収'],
            ['job_id' => 19, 'learn_rank' => 5, 'name' => 'スピリットスティール', 'description' => 'HP/MP吸収＋敵SPR低下'],
            ['job_id' => 25, 'learn_rank' => 5, 'name' => '秘薬調合', 'description' => 'HP/MPを中回復。弱体があれば解除を優先'],
            ['job_id' => 29, 'learn_rank' => 1, 'name' => '魔力循環', 'description' => 'MP小回復＋次の魔法消費MP軽減'],
            ['job_id' => 38, 'learn_rank' => 5, 'name' => '王者の秘薬', 'description' => 'HP/MP中回復＋LUK上昇'],
            ['job_id' => 41, 'learn_rank' => 1, 'name' => '古式錬成', 'description' => '自身の弱体を1つ小バリア/MP回復に変換'],
            ['job_id' => 41, 'learn_rank' => 5, 'name' => '神代防壁', 'description' => 'MAG/SPR参照の大バリア＋MP小回復'],
        ];
    }
};
