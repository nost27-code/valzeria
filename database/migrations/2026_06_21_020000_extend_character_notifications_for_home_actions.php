<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_notifications')) {
            return;
        }

        Schema::table('character_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('character_notifications', 'category')) {
                $table->string('category', 50)->default('general')->after('character_id');
            }
            if (! Schema::hasColumn('character_notifications', 'action_label')) {
                $table->string('action_label', 50)->nullable()->after('url');
            }
            if (! Schema::hasColumn('character_notifications', 'priority')) {
                $table->integer('priority')->default(0)->after('data');
            }
            if (! Schema::hasColumn('character_notifications', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('read_at');
            }
        });

        Schema::table('character_notifications', function (Blueprint $table) {
            $table->index(['character_id', 'category', 'read_at'], 'character_notifications_category_read_idx');
            $table->index('expires_at', 'character_notifications_expires_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('character_notifications')) {
            return;
        }

        Schema::table('character_notifications', function (Blueprint $table) {
            $table->dropIndex('character_notifications_category_read_idx');
            $table->dropIndex('character_notifications_expires_idx');
        });

        Schema::table('character_notifications', function (Blueprint $table) {
            foreach (['category', 'action_label', 'priority', 'expires_at'] as $column) {
                if (Schema::hasColumn('character_notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
