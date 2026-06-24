<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        $now = now();
        foreach ($this->settings() as $setting) {
            DB::table('game_settings')->updateOrInsert(
                ['setting_key' => $setting['setting_key']],
                array_merge($setting, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('game_settings')) {
            return;
        }

        DB::table('game_settings')
            ->whereIn('setting_key', array_column($this->settings(), 'setting_key'))
            ->delete();
    }

    private function settings(): array
    {
        return [
            [
                'setting_key' => 'cooldown.exploration_battle_seconds',
                'label' => '通常探索 連戦待機秒数',
                'description' => '通常探索・サブエリア探索で、次の戦闘を開始できるまでの待機秒数です。0で待機なし。',
                'value' => '20',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'cooldown.inn_seconds',
                'label' => '宿屋後 探索待機秒数',
                'description' => '宿屋でHP/SPを全回復した後、次の探索まで待機する秒数です。0で待機なし。',
                'value' => '40',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'cooldown.arena_rank_battle_seconds',
                'label' => '闘技場ランク戦 待機秒数',
                'description' => '闘技場のランク戦に連続挑戦できない待機秒数です。0で待機なし。',
                'value' => '300',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'cooldown.champ_battle_seconds',
                'label' => 'チャンプバトル 再挑戦待機秒数',
                'description' => 'チャンプバトル挑戦後、再挑戦できるまでの待機秒数です。0で待機なし。',
                'value' => '600',
                'value_type' => 'integer',
            ],
        ];
    }
};
