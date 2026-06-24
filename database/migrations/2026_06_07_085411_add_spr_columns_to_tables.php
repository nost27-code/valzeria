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
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('spirit_base')->default(10)->after('magic_base');
        });

        Schema::table('enemies', function (Blueprint $table) {
            $table->unsignedInteger('spr')->default(10)->after('mag');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->integer('spr_bonus')->default(0)->after('mag_bonus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('spirit_base');
        });

        Schema::table('enemies', function (Blueprint $table) {
            $table->dropColumn('spr');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('spr_bonus');
        });
    }
};
