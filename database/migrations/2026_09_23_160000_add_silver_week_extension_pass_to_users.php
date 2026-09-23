<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'silver_week_extension_pass_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('silver_week_extension_pass_expires_at')->nullable()->after('support_pass_expires_at');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'silver_week_extension_pass_started_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('silver_week_extension_pass_started_at')->nullable()->after('support_pass_expires_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'silver_week_extension_pass_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('silver_week_extension_pass_expires_at');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'silver_week_extension_pass_started_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('silver_week_extension_pass_started_at');
            });
        }
    }
};
