<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIfMissing('nations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 40)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('treasury_points')->default(0);
            $table->unsignedInteger('prestige')->default(0);
            $table->unsignedInteger('war_wins')->default(0);
            $table->unsignedInteger('war_losses')->default(0);
            $table->unsignedInteger('war_draws')->default(0);
            $table->dateTime('founded_at');
            $table->dateTime('loss_protected_until')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('nation_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('citizen');
            $table->dateTime('joined_at');
            $table->timestamps();
            $table->index(['nation_id', 'role']);
        });

        $this->createIfMissing('nation_facilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('facility_type', 32);
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedSmallInteger('condition_bps')->default(10000);
            $table->timestamps();
            $table->unique(['nation_id', 'facility_type']);
        });

        $this->createIfMissing('nation_material_conversion_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points_per_unit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->createIfMissing('nation_wars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('declaring_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('defending_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->string('status', 24)->default('reserved');
            $table->dateTime('declared_at');
            $table->dateTime('preparation_starts_at');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('winner_nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->string('resolution_type', 24)->nullable();
            $table->json('resolution_snapshot')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['declaring_nation_id', 'status']);
            $table->index(['defending_nation_id', 'status']);
        });

        $this->createIfMissing('nation_resource_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nation_war_id')->nullable()->constrained('nation_wars')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type', 40);
            $table->unsignedBigInteger('quantity')->default(0);
            $table->bigInteger('points_delta');
            $table->unsignedBigInteger('balance_after');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['nation_id', 'created_at']);
        });

        $this->createIfMissing('nation_war_sides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('side', 16);
            $table->unsignedSmallInteger('active_member_count')->default(0);
            $table->unsignedBigInteger('resource_pool_points')->default(0);
            $table->unsignedBigInteger('resource_spent_points')->default(0);
            $table->boolean('pool_refunded')->default(false);
            $table->timestamps();
            $table->unique(['nation_war_id', 'nation_id']);
        });

        $this->createIfMissing('nation_war_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->dateTime('frozen_at');
            $table->timestamps();
            $table->unique(['nation_war_id', 'character_id']);
        });

        $this->createIfMissing('nation_war_facilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('facility_type', 32);
            $table->unsignedTinyInteger('level');
            $table->unsignedBigInteger('opening_max_hp');
            $table->unsignedBigInteger('max_hp');
            $table->unsignedBigInteger('current_hp');
            $table->unsignedBigInteger('min_hp');
            $table->string('status', 24)->default('active');
            $table->dateTime('destroyed_at')->nullable();
            $table->dateTime('rebuild_completes_at')->nullable();
            $table->unsignedTinyInteger('rebuild_count')->default(0);
            $table->timestamps();
            $table->unique(['nation_war_id', 'nation_id', 'facility_type'], 'nation_war_facilities_unique');
        });

        $this->createIfMissing('nation_war_daily_sorties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->date('sortie_date');
            $table->unsignedTinyInteger('sortie_count')->default(0);
            $table->unsignedTinyInteger('death_count')->default(0);
            $table->timestamps();
            $table->unique(['nation_war_id', 'character_id', 'sortie_date'], 'nation_war_daily_sorties_unique');
        });

        $this->createIfMissing('nation_war_sortie_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('attacking_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('defending_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_facility_type', 32);
            $table->unsignedBigInteger('damage_applied')->default(0);
            $table->unsignedTinyInteger('turn_count')->default(0);
            $table->unsignedTinyInteger('cannon_hit_count')->default(0);
            $table->boolean('cannon_direct_hit')->default(false);
            $table->boolean('died')->default(false);
            $table->unsignedTinyInteger('retreat_line')->default(20);
            $table->unsignedBigInteger('target_hp_before');
            $table->unsignedBigInteger('target_hp_after');
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->index(['nation_war_id', 'created_at']);
        });

        $this->createIfMissing('nation_war_auto_repair_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('facility_type', 32);
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('trigger_bps')->default(5000);
            $table->unsignedSmallInteger('target_bps')->default(8000);
            $table->timestamps();
            $table->unique(['nation_war_id', 'nation_id', 'facility_type'], 'nation_war_auto_repair_unique');
        });

        $this->createIfMissing('nation_war_auto_rebuild_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained()->cascadeOnDelete();
            $table->string('facility_type', 32);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['nation_war_id', 'nation_id', 'facility_type'], 'nation_war_auto_rebuild_unique');
        });

        $this->createIfMissing('nation_war_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_war_id')->unique()->constrained('nation_wars')->cascadeOnDelete();
            $table->foreignId('declaring_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('defending_nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('winner_nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->string('resolution_type', 24);
            $table->json('summary');
            $table->dateTime('resolved_at');
            $table->timestamps();
        });

        $this->insertSettings();
        $this->insertExistingMaterialRates();
    }

    public function down(): void
    {
        DB::table('game_settings')->whereIn('setting_key', array_column($this->settings(), 'setting_key'))->delete();
        foreach (['nation_war_histories', 'nation_war_auto_rebuild_settings', 'nation_war_auto_repair_settings',
            'nation_war_sortie_logs', 'nation_war_daily_sorties', 'nation_war_facilities', 'nation_war_participants',
            'nation_war_sides', 'nation_resource_transactions', 'nation_wars', 'nation_material_conversion_rates',
            'nation_facilities', 'nation_memberships', 'nations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function insertSettings(): void
    {
        if (! Schema::hasTable('game_settings')) {
            return;
        }

        foreach ($this->settings() as $setting) {
            DB::table('game_settings')->updateOrInsert(
                ['setting_key' => $setting['setting_key']],
                [...$setting, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function createIfMissing(string $table, \Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    /** @return list<array{setting_key:string,label:string,description:string,value:string,value_type:string}> */
    private function settings(): array
    {
        $settings = [
            ['setting_key' => 'nation.facility_upgrades_enabled', 'label' => '国家施設レベルアップ', 'description' => '0の間は国家施設のレベルアップを停止します。', 'value' => '0', 'value_type' => 'boolean'],
            ['setting_key' => 'nation.max_members', 'label' => '国家 最大人数', 'description' => '1国家へ所属できる最大人数。', 'value' => '100', 'value_type' => 'integer'],
            ['setting_key' => 'nation.founded_protection_days', 'label' => '建国保護日数', 'description' => '建国直後に国家戦から保護する日数。', 'value' => '7', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.declaration_enabled', 'label' => '国家戦 宣戦布告', 'description' => '0の間は宣戦布告と次戦予約を停止します。', 'value' => '0', 'value_type' => 'boolean'],
            ['setting_key' => 'nation_war.reference_damage', 'label' => '国家戦 基準D', 'description' => '基準キャラクターがDEF0施設へ30ターンで与える総ダメージ。0は未校正です。', 'value' => '0', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.active_days', 'label' => '国家戦 アクティブ日数', 'description' => '参加者確定に用いる実プレイ履歴の対象日数。', 'value' => '7', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.preparation_days', 'label' => '国家戦 準備日数', 'description' => '宣戦布告から開戦までの日数。', 'value' => '3', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.duration_days', 'label' => '国家戦 開戦日数', 'description' => '国家戦の継続日数。', 'value' => '5', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.sorties_per_day', 'label' => '国家戦 1日出撃回数', 'description' => '1キャラクターが1日に出撃できる回数。', 'value' => '10', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.sortie_stamina_cost', 'label' => '国家戦 探索力消費', 'description' => '出撃開始時に消費する探索力。', 'value' => '15', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.max_turns', 'label' => '国家戦 最大ターン', 'description' => '1出撃の最大ターン数。', 'value' => '30', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.death_extra_sorties', 'label' => '国家戦 戦死追加消費', 'description' => '戦死時に追加消費する出撃回数。', 'value' => '1', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.loss_protection_days', 'label' => '国家戦 敗戦保護日数', 'description' => '敗戦後に国家戦から保護する日数。', 'value' => '3', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.repair_points_per_d', 'label' => '国家戦 修復コスト', 'description' => '1D分のHP修復に必要な国家資材pt。', 'value' => '140', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.logistics_self_repair_multiplier', 'label' => '兵站所 自己修復倍率', 'description' => '兵站所自身を修復する際のコスト倍率。', 'value' => '2', 'value_type' => 'float'],
            ['setting_key' => 'nation_war.rebuild_hp_bps', 'label' => '国家戦 再建HP率', 'description' => '再建完了時HPを万分率で指定。', 'value' => '5000', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.rebuild_minutes', 'label' => '国家戦 再建時間', 'description' => '再建開始から完了までの分数。', 'value' => '60', 'value_type' => 'integer'],
            ['setting_key' => 'nation_war.rebuild_escalation_multiplier', 'label' => '国家戦 5回目以降再建倍率', 'description' => '5回目以降の再建コストへ前回比で掛ける倍率。', 'value' => '1.5', 'value_type' => 'float'],
            ['setting_key' => 'nation_war.cannon_direct_hit_rate', 'label' => '魔導砲 直撃率', 'description' => '直撃の百分率。', 'value' => '10', 'value_type' => 'float'],
            ['setting_key' => 'nation_war.cannon_direct_hit_multiplier', 'label' => '魔導砲 直撃倍率', 'description' => '通常砲撃へ掛ける直撃倍率。', 'value' => '2.5', 'value_type' => 'float'],
        ];
        foreach ([1 => .8, 2 => 1.2, 3 => 1.8, 4 => 2.7] as $count => $value) {
            $settings[] = ['setting_key' => "nation_war.rebuild_multiplier.{$count}", 'label' => "国家戦 {$count}回目再建倍率", 'description' => '施設の再建コスト倍率。', 'value' => (string) $value, 'value_type' => 'float'];
        }
        foreach (['wall' => 300, 'magic_cannon' => 90, 'logistics' => 120, 'arsenal' => 150, 'headquarters' => 500] as $type => $value) {
            $settings[] = ['setting_key' => "nation_war.facility_base_d.{$type}", 'label' => "国家戦 {$type} 基礎D", 'description' => '施設最大HP計算のD倍率。', 'value' => (string) $value, 'value_type' => 'integer'];
        }
        foreach ([1 => 2000,2 => 2800,3 => 3700,4 => 4700,5 => 5800,6 => 6900,7 => 7900,8 => 8800,9 => 9500,10 => 10000] as $level => $value) {
            $settings[] = ['setting_key' => "nation_war.facility_level_ratio_bps.{$level}", 'label' => "国家戦 施設Lv{$level} HP率", 'description' => '施設LvごとのHP倍率を万分率で指定。', 'value' => (string) $value, 'value_type' => 'integer'];
        }
        $cannon = \App\Services\Nation\NationWarSettingsService::CANNON;
        foreach ($cannon as $level => $spec) {
            $settings[] = ['setting_key' => "nation_war.cannon_damage_ratio_bps.{$level}", 'label' => "魔導砲Lv{$level} ダメージ率", 'description' => '対象最大HPに対する通常ダメージを万分率で指定。', 'value' => (string) round($spec['ratio'] * 10000), 'value_type' => 'integer'];
            $settings[] = ['setting_key' => "nation_war.cannon_target_e.{$level}", 'label' => "魔導砲Lv{$level} 目標E", 'description' => '校正確認用の目標E。実戦計算には使いません。', 'value' => (string) $spec['e'], 'value_type' => 'float'];
            $settings[] = ['setting_key' => "nation_war.cannon_fire_turns.{$level}", 'label' => "魔導砲Lv{$level} 発射ターン", 'description' => 'カンマ区切りの発射ターン。', 'value' => implode(',', $spec['turns']), 'value_type' => 'string'];
        }
        return $settings;
    }

    private function insertExistingMaterialRates(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        $low = ['WEV0023', 'WEV0024', 'WEV0025', 'WEV0026', 'WEV0027', 'WEV0028', 'MAT_REGION_MAGIC_CRYSTAL', 'WEV0030', 'WEV0031', 'WEV0032',
            '5025', '5027', '5029', '5031', '5033', '5035', '5037', '5039', '5041', '5043'];
        $high = ['WEV0033', 'WEV0035', 'WEV0037', 'WEV0039', 'WEV0041', 'WEV0043', 'WEV0045', 'WEV0047', 'WEV0049', 'WEV0051',
            '5026', '5028', '5030', '5032', '5034', '5036', '5038', '5040', '5042', '5044'];
        $now = now();
        foreach ([1 => $low, 3 => $high] as $points => $codes) {
            foreach (DB::table('materials')->whereIn('material_code', $codes)->pluck('id') as $materialId) {
                DB::table('nation_material_conversion_rates')->updateOrInsert(
                    ['material_id' => $materialId],
                    ['points_per_unit' => $points, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
