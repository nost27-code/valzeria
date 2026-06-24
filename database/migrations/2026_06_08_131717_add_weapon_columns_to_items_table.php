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
        Schema::table('items', function (Blueprint $table) {
            $table->string('sub_type')->nullable()->after('type');
            $table->string('element')->nullable()->after('description');
            $table->integer('sell_price')->default(0)->after('price');
            $table->integer('mp_bonus')->default(0)->after('hp_bonus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sub_type', 'element', 'sell_price', 'mp_bonus']);
        });
    }
};
