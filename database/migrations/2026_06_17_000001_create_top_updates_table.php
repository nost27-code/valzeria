<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('top_updates')) {
            Schema::create('top_updates', function (Blueprint $table) {
                $table->id();
                $table->date('published_on');
                $table->string('body', 255);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'published_on', 'sort_order']);
            });
        }

        if (DB::table('top_updates')->count() === 0) {
            $now = now();
            DB::table('top_updates')->insert([
                [
                    'published_on' => '2026-06-16',
                    'body' => '補給商会（冒険支援アイテムショップ）を追加しました',
                    'sort_order' => 10,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'published_on' => '2026-06-15',
                    'body' => 'ヴァルモン牧場を更新しました',
                    'sort_order' => 20,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'published_on' => '2026-06-14',
                    'body' => '輝石ショップを更新しました',
                    'sort_order' => 30,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'published_on' => '2026-06-10',
                    'body' => '新ダンジョン・エリアを追加しました',
                    'sort_order' => 40,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('top_updates');
    }
};
