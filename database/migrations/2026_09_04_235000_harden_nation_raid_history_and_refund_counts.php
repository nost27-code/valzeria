<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lossless widening. No reset/backfill of counters, rewards or player assets.
        Schema::table('nation_raid_daily_usages', fn (Blueprint $table) =>
            $table->unsignedInteger('refunded_count')->default(0)->change());
        Schema::table('nation_raid_participations', function (Blueprint $table): void {
            $table->json('final_result_snapshot')->nullable();
            $table->char('final_result_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        throw_if(DB::table('nation_raid_participations')->whereNotNull('final_result_snapshot')->exists()
            || DB::table('nation_raid_daily_usages')->where('refunded_count', '>', 255)->exists(),
            RuntimeException::class, '確定履歴・返却回数を失わないよう、公開OFFのままforward migrationで復旧してください。');
        Schema::table('nation_raid_participations', fn (Blueprint $table) =>
            $table->dropColumn(['final_result_snapshot', 'final_result_hash']));
        Schema::table('nation_raid_daily_usages', fn (Blueprint $table) =>
            $table->unsignedTinyInteger('refunded_count')->default(0)->change());
    }
};
