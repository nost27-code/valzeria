<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_relics', function (Blueprint $table) {
            if (! Schema::hasColumn('player_relics', 'growth_progress')) {
                $table->unsignedTinyInteger('growth_progress')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('player_relics')->where('growth_progress', '>', 0)->exists()
            || DB::table('nameless_workshop_operations')->where('action', 'relic-grow')->exists()) {
            throw new RuntimeException('育成済みの遺物・消費記録があります。進捗を保持する移行が必要です。');
        }
        Schema::table('player_relics', function (Blueprint $table) {
            $table->dropColumn('growth_progress');
        });
    }
};
