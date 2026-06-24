<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('valmon_masters') && !Schema::hasColumn('valmon_masters', 'image_path')) {
            Schema::table('valmon_masters', function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('valmon_masters') && Schema::hasColumn('valmon_masters', 'image_path')) {
            Schema::table('valmon_masters', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
