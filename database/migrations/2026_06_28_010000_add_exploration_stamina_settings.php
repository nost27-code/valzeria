<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('characters')) {
            Schema::table('characters', function (Blueprint $table) {
                if (!Schema::hasColumn('characters', 'explore_stamina')) {
                    $table->unsignedInteger('explore_stamina')->default(250)->after('exploration_cooldown_until');
                }
                if (!Schema::hasColumn('characters', 'explore_stamina_max')) {
                    $table->unsignedInteger('explore_stamina_max')->default(250)->after('explore_stamina');
                }
                if (!Schema::hasColumn('characters', 'explore_stamina_updated_at')) {
                    $table->timestamp('explore_stamina_updated_at')->nullable()->after('explore_stamina_max');
                }
            });

            DB::table('characters')
                ->whereNull('explore_stamina_updated_at')
                ->orderBy('id')
                ->chunkById(500, function ($characters): void {
                    $now = now();
                    foreach ($characters as $character) {
                        $max = $this->maxForWins((int) ($character->wins ?? 0));
                        DB::table('characters')
                            ->where('id', $character->id)
                            ->update([
                                'explore_stamina' => $max,
                                'explore_stamina_max' => $max,
                                'explore_stamina_updated_at' => $now,
                                'updated_at' => $now,
                            ]);
                    }
                });
        }

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
        if (Schema::hasTable('game_settings')) {
            DB::table('game_settings')
                ->whereIn('setting_key', array_column($this->settings(), 'setting_key'))
                ->delete();
        }

        if (!Schema::hasTable('characters')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'explore_stamina_updated_at')) {
                $table->dropColumn('explore_stamina_updated_at');
            }
            if (Schema::hasColumn('characters', 'explore_stamina_max')) {
                $table->dropColumn('explore_stamina_max');
            }
            if (Schema::hasColumn('characters', 'explore_stamina')) {
                $table->dropColumn('explore_stamina');
            }
        });
    }

    private function settings(): array
    {
        return [
            [
                'setting_key' => 'exploration.mode',
                'label' => '探索方式',
                'description' => '通常探索の開始方式です。cooldownで従来の連戦待機、staminaで探索力を消費します。',
                'value' => 'cooldown',
                'value_type' => 'string',
            ],
            [
                'setting_key' => 'exploration.stamina_max',
                'label' => '探索力 最大値',
                'description' => 'スタミナ制の探索力最大値です。',
                'value' => '500',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'exploration.stamina_recovery_seconds',
                'label' => '探索力 1回復秒数',
                'description' => '探索力が1回復するまでの秒数です。',
                'value' => '60',
                'value_type' => 'integer',
            ],
            [
                'setting_key' => 'exploration.stamina_cost',
                'label' => '探索力 消費量',
                'description' => '通常探索/ボス挑戦1回に消費する探索力です。',
                'value' => '1',
                'value_type' => 'integer',
            ],
        ];
    }

    private function maxForWins(int $wins): int
    {
        $max = 250;
        $max += intdiv(min($wins, 2000), 10);
        $max += intdiv(min(max($wins - 2000, 0), 1000), 20);

        return min(500, $max);
    }
};
