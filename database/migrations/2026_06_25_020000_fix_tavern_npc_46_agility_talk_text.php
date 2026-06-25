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

        DB::table('npc_master')
            ->where('npc_id', 46)
            ->update([
                'talk_text' => '「三つの職業を極めた者か。俺が辿り着いた境地は、敏捷を突き詰めた先にあった。速さは先手だけじゃない。攻撃を当てる力にも、敵の攻撃をかわす力にも関わってくる。速さに価値を見出した者だけが、次の扉を開ける。」',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('npc_master')) {
            return;
        }

        DB::table('npc_master')
            ->where('npc_id', 46)
            ->update([
                'talk_text' => '「三つの職業を極めた者か。俺が辿り着いた境地は、敏捷を突き詰めた先にあった。速さは先手だけじゃない。回避率にも影響するし、スキルの発動にも関わってくる。速さに価値を見出した者だけが、次の扉を開ける。」',
                'updated_at' => now(),
            ]);
    }
};
