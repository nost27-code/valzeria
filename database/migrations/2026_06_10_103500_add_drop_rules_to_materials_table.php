<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (!Schema::hasColumn('materials', 'drop_rate')) {
                $table->decimal('drop_rate', 5, 2)->default(0)->after('source_enemy_id');
            }
            if (!Schema::hasColumn('materials', 'drop_first_clear_only')) {
                $table->boolean('drop_first_clear_only')->default(false)->after('drop_rate');
            }
            if (!Schema::hasColumn('materials', 'drop_timing')) {
                $table->string('drop_timing')->nullable()->after('drop_first_clear_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (Schema::hasColumn('materials', 'drop_timing')) {
                $table->dropColumn('drop_timing');
            }
            if (Schema::hasColumn('materials', 'drop_first_clear_only')) {
                $table->dropColumn('drop_first_clear_only');
            }
            if (Schema::hasColumn('materials', 'drop_rate')) {
                $table->dropColumn('drop_rate');
            }
        });
    }
};
