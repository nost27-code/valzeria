<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->string('join_policy', 100)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn('join_policy');
        });
    }
};
