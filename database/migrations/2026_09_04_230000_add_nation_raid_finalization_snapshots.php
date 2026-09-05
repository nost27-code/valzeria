<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_raid_events', function (Blueprint $table): void {
            $table->json('reward_policy_snapshot')->nullable();
            $table->char('reward_policy_hash', 64)->nullable();
            $table->json('final_standings_snapshot')->nullable();
            $table->char('final_standings_hash', 64)->nullable();
        });
        // FKがnullになる場合も開始時の帰属を保存する。既存履歴を推測でbackfillしない。
        Schema::table('nation_raid_participations', function (Blueprint $table): void {
            $table->unsignedBigInteger('character_id_snapshot')->nullable();
            $table->unsignedBigInteger('nation_id_snapshot')->nullable();
        });
        Schema::table('nation_raid_personal_rewards', fn (Blueprint $table) => $table->index(
            ['character_id_snapshot', 'status', 'claimed_at'], 'raid_personal_honor_idx'));
    }

    public function down(): void
    {
        throw_if(DB::table('nation_raid_events')->whereNotNull('reward_policy_snapshot')->exists(),
            RuntimeException::class, 'レイド報酬履歴は削除せず、公開OFFのままforward migrationで復旧してください。');
        Schema::table('nation_raid_personal_rewards', fn (Blueprint $table) => $table->dropIndex('raid_personal_honor_idx'));
        Schema::table('nation_raid_events', fn (Blueprint $table) => $table->dropColumn([
            'reward_policy_snapshot', 'reward_policy_hash', 'final_standings_snapshot', 'final_standings_hash',
        ]));
        Schema::table('nation_raid_participations', fn (Blueprint $table) => $table->dropColumn(['character_id_snapshot', 'nation_id_snapshot']));
    }
};
