<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tower_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tower_runs', 'metadata')) {
                $table->json('metadata')->nullable()->after('last_event_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tower_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('tower_runs', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
