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

        DB::table('npc_master')->where('npc_id', 40)->update([
            'talk_text'  => "「ランク戦で勝ちたいなら、まず相性を覚えろ。剛・技・魔、この三つだ。\n剛は技を制し、技は魔を制し、魔は剛を制す。覚え方は『剛→技→魔→剛』。\n有利を取れば与えるダメージが増え、不利なら減る。装備だけ整えても、相性を無視すれば勝てない戦いがある。」",
            'hint_text'  => "職業の系統：剛（剣士・戦士・格闘家・聖騎士・侍・モンク）／技（盗賊・弓使い・商人・忍者・狙撃手・軍師・旅商人）／魔（魔法使い・僧侶・魔法剣士・司祭・魔盗士）。有利時は与ダメージ+10%、不利時は-10%。まず自分の職がどの系統か確認しよう。",
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        DB::table('npc_master')->where('npc_id', 40)->update([
            'talk_text'  => "「模擬戦で強くなりたいなら、まず自分の弱点を知ることだ。攻めるだけでは勝てない。守り方を覚えれば、おのずと攻め方も見えてくる。」",
            'hint_text'  => "模擬戦ヒント。今の冒険で詰まったら、探索度、危険度、職業熟練度、装備の相性を見直してみよう。",
            'updated_at' => now(),
        ]);
    }
};
