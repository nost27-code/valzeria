<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_BACKGROUND = 'nation_header_bg_001';

    public function up(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->string('header_background_key', 32)
                ->default(self::DEFAULT_BACKGROUND)
                ->after('emblem_key');
        });
    }

    public function down(): void
    {
        Schema::table('nations', function (Blueprint $table): void {
            $table->dropColumn('header_background_key');
        });
    }
};
