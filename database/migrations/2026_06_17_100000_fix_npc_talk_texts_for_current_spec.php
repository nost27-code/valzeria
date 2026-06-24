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

        $updates = [
            // NPC12: 「市場」は存在しない施設 → 鍛冶屋での強化・進化合成に修正
            12 => [
                'talk_text' => '「素材は売るだけじゃもったいないよ。鍛冶屋で武器を強化したり、合成素材として進化合成に使ったりすれば、思わぬ力になるんだから。」',
                'hint_text' => '素材は鍛冶屋の武器強化や進化合成で活用できる。素材を貯めながら探索を進めよう。',
            ],
            // NPC14: 鍛冶屋ヒントNPCなのに強化石・進化合成への言及がない → 現行システムに合わせて修正
            14 => [
                'talk_text' => '「武器は買って終わりじゃねぇ。強化石で鍛えて、素材を集めて進化合成で上位の装備に育てる。そいつが本当の相棒になるんだ。」',
                'hint_text' => '鍛冶屋では強化石を使った武器強化（+1〜+3）ができる。さらに進化合成所では素材をそろえることで上位装備への進化合成が可能。装備は買うだけでなく、育てることが大事。',
            ],
        ];

        foreach ($updates as $npcId => $fields) {
            DB::table('npc_master')
                ->where('npc_id', $npcId)
                ->update(array_merge($fields, ['updated_at' => now()]));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        // 元のテキストに戻す
        DB::table('npc_master')->where('npc_id', 12)->update([
            'talk_text' => '「素材は売るだけじゃもったいないよ。鍛冶屋や市場で、思わぬ価値に変わることもあるんだから。」',
            'hint_text' => '素材売買ヒント。今の冒険で詰まったら、探索度、危険度、職業熟練度、装備の相性を見直してみよう。',
            'updated_at' => now(),
        ]);
        DB::table('npc_master')->where('npc_id', 14)->update([
            'talk_text' => '「武器は買って終わりじゃねぇ。鍛えて、使って、また鍛える。そいつが相棒になるんだ。」',
            'hint_text' => '鍛冶屋ヒント。今の冒険で詰まったら、探索度、危険度、職業熟練度、装備の相性を見直してみよう。',
            'updated_at' => now(),
        ]);
    }
};
