<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->boolean('is_hidden')->default(false)->after('status');
            $table->index('is_hidden', 'nations_is_hidden_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('nations')->where('is_hidden', true)->exists()) {
            throw new RuntimeException('非表示国家が存在するため is_hidden カラムを削除できません。先に対象データを安全に処理してください。');
        }

        Schema::table('nations', function (Blueprint $table): void {
            $table->dropIndex('nations_is_hidden_idx');
            $table->dropColumn('is_hidden');
        });
    }
};
