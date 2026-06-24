<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            if (!Schema::hasColumn('skills', 'activation_rate')) {
                $table->integer('activation_rate')->default(15)->after('power');
            }
            if (!Schema::hasColumn('skills', 'action_class')) {
                $table->string('action_class')->nullable()->after('activation_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['activation_rate', 'action_class']);
        });
    }
};
