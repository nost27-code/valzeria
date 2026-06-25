<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_item_daily_supplies', function (Blueprint $table) {
            $table->unsignedSmallInteger('stocked_count')->default(0)->after('supplied_count');
        });
    }

    public function down(): void
    {
        Schema::table('character_item_daily_supplies', function (Blueprint $table) {
            $table->dropColumn('stocked_count');
        });
    }
};
