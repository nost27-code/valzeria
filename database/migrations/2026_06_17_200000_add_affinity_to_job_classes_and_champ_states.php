<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            $table->float('affinity_physical')->default(0.33)->after('is_active');
            $table->float('affinity_speed')->default(0.33)->after('affinity_physical');
            $table->float('affinity_magical')->default(0.34)->after('affinity_speed');
        });

        Schema::table('champ_states', function (Blueprint $table) {
            $table->float('affinity_physical')->default(0.33)->after('luk');
            $table->float('affinity_speed')->default(0.33)->after('affinity_physical');
            $table->float('affinity_magical')->default(0.34)->after('affinity_speed');
        });
    }

    public function down(): void
    {
        Schema::table('job_classes', function (Blueprint $table) {
            $table->dropColumn(['affinity_physical', 'affinity_speed', 'affinity_magical']);
        });
        Schema::table('champ_states', function (Blueprint $table) {
            $table->dropColumn(['affinity_physical', 'affinity_speed', 'affinity_magical']);
        });
    }
};
