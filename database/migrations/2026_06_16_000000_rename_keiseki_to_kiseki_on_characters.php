<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'keiseki') && !Schema::hasColumn('characters', 'kiseki')) {
                $table->renameColumn('keiseki', 'kiseki');
            }
            if (Schema::hasColumn('characters', 'paid_keiseki') && !Schema::hasColumn('characters', 'paid_kiseki')) {
                $table->renameColumn('paid_keiseki', 'paid_kiseki');
            }
            if (Schema::hasColumn('characters', 'free_keiseki') && !Schema::hasColumn('characters', 'free_kiseki')) {
                $table->renameColumn('free_keiseki', 'free_kiseki');
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'kiseki') && !Schema::hasColumn('characters', 'keiseki')) {
                $table->renameColumn('kiseki', 'keiseki');
            }
            if (Schema::hasColumn('characters', 'paid_kiseki') && !Schema::hasColumn('characters', 'paid_keiseki')) {
                $table->renameColumn('paid_kiseki', 'paid_keiseki');
            }
            if (Schema::hasColumn('characters', 'free_kiseki') && !Schema::hasColumn('characters', 'free_keiseki')) {
                $table->renameColumn('free_kiseki', 'free_keiseki');
            }
        });
    }
};
