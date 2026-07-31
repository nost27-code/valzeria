<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('top_updates', function (Blueprint $table): void {
            $table->text('detail')->nullable()->after('body');
            $table->string('source_key', 191)->nullable()->unique()->after('detail');
            $table->string('source_category', 20)->nullable()->after('source_key');
            $table->boolean('is_dismissed')->default(false)->after('is_active');
        });

        // 街の更新履歴は管理画面で内容を確認してから公開する。
        DB::table('top_updates')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('top_updates', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn([
                'detail',
                'source_key',
                'source_category',
                'is_dismissed',
            ]);
        });
    }
};
