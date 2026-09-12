<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->string('kind', 20)->default('bug');
            $table->index(['kind', 'status', 'created_at'], 'bug_reports_kind_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table): void {
            $table->dropIndex('bug_reports_kind_status_created_index');
            $table->dropColumn('kind');
        });
    }
};
