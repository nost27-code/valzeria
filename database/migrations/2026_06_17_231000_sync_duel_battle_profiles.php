<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_classes') && $this->hasBattleProfileColumns('job_classes')) {
            foreach ($this->profiles() as $key => $profile) {
                DB::table('job_classes')
                    ->where('key', $key)
                    ->update([
                        'affinity_physical' => $profile['physical'],
                        'affinity_speed' => $profile['speed'],
                        'affinity_magical' => $profile['magical'],
                        'normal_attack_type' => $profile['normal'],
                    ]);
            }
        }

        if (Schema::hasTable('champ_states') && $this->hasBattleProfileColumns('champ_states')) {
            foreach ($this->profiles() as $profile) {
                DB::table('champ_states')
                    ->where('job_name', $profile['name'])
                    ->update([
                        'affinity_physical' => $profile['physical'],
                        'affinity_speed' => $profile['speed'],
                        'affinity_magical' => $profile['magical'],
                        'normal_attack_type' => $profile['normal'],
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Master balance sync only. No destructive rollback.
    }

    private function hasBattleProfileColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'affinity_physical')
            && Schema::hasColumn($table, 'affinity_speed')
            && Schema::hasColumn($table, 'affinity_magical')
            && Schema::hasColumn($table, 'normal_attack_type');
    }

    private function profiles(): array
    {
        return [
            'swordsman' => ['name' => '剣士', 'physical' => 0.80, 'speed' => 0.20, 'magical' => 0.00, 'normal' => 'physical'],
            'warrior' => ['name' => '戦士', 'physical' => 1.00, 'speed' => 0.00, 'magical' => 0.00, 'normal' => 'physical'],
            'thief' => ['name' => '盗賊', 'physical' => 0.20, 'speed' => 0.80, 'magical' => 0.00, 'normal' => 'physical'],
            'archer' => ['name' => '弓使い', 'physical' => 0.50, 'speed' => 0.50, 'magical' => 0.00, 'normal' => 'physical'],
            'fighter' => ['name' => '格闘家', 'physical' => 0.40, 'speed' => 0.60, 'magical' => 0.00, 'normal' => 'physical'],
            'mage' => ['name' => '魔法使い', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'priest' => ['name' => '僧侶', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'merchant' => ['name' => '商人', 'physical' => 1.00, 'speed' => 0.00, 'magical' => 0.00, 'normal' => 'physical'],
            'magic_swordsman' => ['name' => '魔法剣士', 'physical' => 0.50, 'speed' => 0.00, 'magical' => 0.50, 'normal' => 'magical'],
            'paladin' => ['name' => '聖騎士', 'physical' => 0.80, 'speed' => 0.00, 'magical' => 0.20, 'normal' => 'physical'],
            'samurai' => ['name' => '侍', 'physical' => 0.50, 'speed' => 0.50, 'magical' => 0.00, 'normal' => 'physical'],
            'tactician' => ['name' => '軍師', 'physical' => 0.70, 'speed' => 0.00, 'magical' => 0.30, 'normal' => 'physical'],
            'gladiator' => ['name' => '剣闘士', 'physical' => 0.80, 'speed' => 0.20, 'magical' => 0.00, 'normal' => 'physical'],
            'berserker' => ['name' => '狂戦士', 'physical' => 0.85, 'speed' => 0.15, 'magical' => 0.00, 'normal' => 'physical'],
            'guardian_knight' => ['name' => '守護騎士', 'physical' => 1.00, 'speed' => 0.00, 'magical' => 0.00, 'normal' => 'physical'],
            'mercenary' => ['name' => '傭兵', 'physical' => 1.00, 'speed' => 0.00, 'magical' => 0.00, 'normal' => 'physical'],
            'ninja' => ['name' => '忍者', 'physical' => 0.00, 'speed' => 1.00, 'magical' => 0.00, 'normal' => 'physical'],
            'sniper' => ['name' => '狙撃手', 'physical' => 0.40, 'speed' => 0.60, 'magical' => 0.00, 'normal' => 'physical'],
            'magic_thief' => ['name' => '魔盗士', 'physical' => 0.00, 'speed' => 0.50, 'magical' => 0.50, 'normal' => 'magical'],
            'traveling_merchant' => ['name' => '旅商人', 'physical' => 0.80, 'speed' => 0.00, 'magical' => 0.20, 'normal' => 'physical'],
            'monk' => ['name' => 'モンク', 'physical' => 0.50, 'speed' => 0.40, 'magical' => 0.10, 'normal' => 'physical'],
            'magic_archer' => ['name' => '魔弓士', 'physical' => 0.20, 'speed' => 0.40, 'magical' => 0.40, 'normal' => 'magical'],
            'bard' => ['name' => '吟遊詩人', 'physical' => 0.00, 'speed' => 0.40, 'magical' => 0.60, 'normal' => 'magical'],
            'bishop' => ['name' => '司祭', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'apothecary' => ['name' => '薬師', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'alchemist' => ['name' => '錬金術師', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'hero' => ['name' => '勇者', 'physical' => 0.40, 'speed' => 0.20, 'magical' => 0.40, 'normal' => 'physical'],
            'sword_master' => ['name' => '剣聖', 'physical' => 0.50, 'speed' => 0.50, 'magical' => 0.00, 'normal' => 'physical'],
            'grand_sage' => ['name' => '大賢者', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'dark_knight' => ['name' => '暗黒騎士', 'physical' => 0.80, 'speed' => 0.00, 'magical' => 0.20, 'normal' => 'physical'],
            'golden_merchant' => ['name' => '黄金商人', 'physical' => 0.40, 'speed' => 0.20, 'magical' => 0.40, 'normal' => 'physical'],
            'dragoon' => ['name' => '竜騎士', 'physical' => 0.70, 'speed' => 0.30, 'magical' => 0.00, 'normal' => 'physical'],
            'war_god' => ['name' => '武神', 'physical' => 0.50, 'speed' => 0.50, 'magical' => 0.00, 'normal' => 'physical'],
            'phantom_king' => ['name' => '幻影王', 'physical' => 0.20, 'speed' => 0.60, 'magical' => 0.20, 'normal' => 'magical'],
            'machinist_king' => ['name' => '機工王', 'physical' => 0.30, 'speed' => 0.00, 'magical' => 0.70, 'normal' => 'magical'],
            'priest_warrior' => ['name' => '神官戦士', 'physical' => 0.30, 'speed' => 0.00, 'magical' => 0.70, 'normal' => 'magical'],
            'shadow_hunter' => ['name' => '影狩人', 'physical' => 0.30, 'speed' => 0.70, 'magical' => 0.00, 'normal' => 'physical'],
            'merchant_sage_king' => ['name' => '賢商王', 'physical' => 0.20, 'speed' => 0.00, 'magical' => 0.80, 'normal' => 'magical'],
            'valzeria_hero' => ['name' => 'ヴァルゼリアの英雄', 'physical' => 0.40, 'speed' => 0.20, 'magical' => 0.40, 'normal' => 'physical'],
            'abyss_walker' => ['name' => '深淵歩き', 'physical' => 0.30, 'speed' => 0.40, 'magical' => 0.30, 'normal' => 'magical'],
            'ancient_alchemist_king' => ['name' => '古代錬成王', 'physical' => 0.00, 'speed' => 0.00, 'magical' => 1.00, 'normal' => 'magical'],
            'dragon_god' => ['name' => '竜神', 'physical' => 0.70, 'speed' => 0.30, 'magical' => 0.00, 'normal' => 'physical'],
            'time_space_king' => ['name' => '時空王', 'physical' => 0.00, 'speed' => 0.50, 'magical' => 0.50, 'normal' => 'magical'],
        ];
    }
};
