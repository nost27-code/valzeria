<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_settings')) {
            Schema::create('game_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_key', 100)->unique();
                $table->string('label', 100);
                $table->text('description')->nullable();
                $table->string('value', 100);
                $table->string('value_type', 20)->default('float');
                $table->timestamps();
            });
        }

        $now = now();
        foreach ($this->defaultSettings() as $setting) {
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
        Schema::dropIfExists('game_settings');
    }

    private function defaultSettings(): array
    {
        return [
            [
                'setting_key' => 'drop.material_rate_multiplier',
                'label' => '素材ドロップ倍率',
                'description' => '通常戦闘の素材枠抽選率に掛ける倍率。1.0で現状維持。',
                'value' => '1.0',
                'value_type' => 'float',
            ],
            [
                'setting_key' => 'drop.equipment_rate_multiplier',
                'label' => '装備ドロップ倍率',
                'description' => '武器・防具・装飾品の抽選率に掛ける倍率。1.0で現状維持。',
                'value' => '1.0',
                'value_type' => 'float',
            ],
            [
                'setting_key' => 'drop.strong_fragment_weight_multiplier',
                'label' => '強装備の欠片 出現重み倍率',
                'description' => 'フォールバック素材内の強装備の欠片の重みに掛ける倍率。落ちすぎる場合は0.5などに下げる。',
                'value' => '1.0',
                'value_type' => 'float',
            ],
            [
                'setting_key' => 'kiseki.free_drop_rate_per_million',
                'label' => '無償輝石ドロップ率',
                'description' => '通常戦闘勝利時の抽選率。100万分率で指定。300なら0.03%。',
                'value' => '300',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'kiseki.daily_free_drop_limit',
                'label' => '無償輝石 1日上限',
                'description' => '1キャラクターが1日に戦闘ドロップで得られる無償輝石の上限。',
                'value' => '3',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'valmon.egg_rate_multiplier',
                'label' => 'ヴァルモン卵発見倍率',
                'description' => '探索中のヴァルモン卵発見率に掛ける倍率。1.0で現状維持。',
                'value' => '1.0',
                'value_type' => 'float',
            ],
            [
                'setting_key' => 'secret_realm.gate_rate_multiplier',
                'label' => '秘境入口出現倍率',
                'description' => '探索度に応じた秘境入口出現率に掛ける倍率。1.0で現状維持。',
                'value' => '1.0',
                'value_type' => 'float',
            ],
        ];
    }
};
