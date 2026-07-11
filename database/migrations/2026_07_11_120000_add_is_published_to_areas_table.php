<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('areas', 'is_published')) {
            return;
        }

        Schema::table('areas', function (Blueprint $table): void {
            $table->boolean('is_published')->default(true)->after('is_route_area');
            $table->index('is_published', 'areas_is_published_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('areas', 'is_published')) {
            return;
        }

        Schema::table('areas', function (Blueprint $table): void {
            $table->dropIndex('areas_is_published_idx');
            $table->dropColumn('is_published');
        });
    }
};
