<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        $talkTexts = [
            // 運はドロップ率でなくスキルダメージに影響することを正確に伝える
            22 => '「勘違いしている子が多いけど、運はドロップ率には関係ないの。運が輝くのは、侍や剣聖みたいな一部の職業のスキルを使う時よ。ドロップが欲しいなら、狙う敵の種類を変えてみることね。」',

            // ヴァルモンのレベルアップ・なつき度について説明
            38 => '「ヴァルモンに餌を与えるたびに、なつき度が少しずつ上がるんですよ。レベルが上がって相棒との絆が深まると、探索中に素材を見つけてきてくれることもあります。出会いを大切にすると、旅が豊かになるものですね。」',
        ];

        foreach ($talkTexts as $npcId => $talkText) {
            DB::table('npc_master')
                ->where('npc_id', $npcId)
                ->update([
                    'talk_text' => $talkText,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        $originals = [
            22 => '「運がいい人ほど、運だけに頼らないものよ。運を上げるなら、狙う敵も選びなさい。」',
            38 => '「ここには色々な冒険者が来ますよ。出会いを記録しておくと、いつか思わぬ縁になります。」',
        ];

        foreach ($originals as $npcId => $talkText) {
            DB::table('npc_master')
                ->where('npc_id', $npcId)
                ->update([
                    'talk_text' => $talkText,
                    'updated_at' => now(),
                ]);
        }
    }
};
