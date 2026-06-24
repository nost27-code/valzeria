<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('characters') || Schema::hasColumn('characters', 'keiseki')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('keiseki')
                ->default(0)
                ->after('money')
                ->comment('課金通貨: 輝石');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'keiseki')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('keiseki');
        });
    }
};
