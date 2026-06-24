<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('battle_logs') || Schema::hasColumn('battle_logs', 'gold_lost')) {
            return;
        }

        Schema::table('battle_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('gold_lost')->default(0)->after('gold_gained');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('battle_logs') || !Schema::hasColumn('battle_logs', 'gold_lost')) {
            return;
        }

        Schema::table('battle_logs', function (Blueprint $table) {
            $table->dropColumn('gold_lost');
        });
    }
};
