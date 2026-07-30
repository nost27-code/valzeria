<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_icon_entitlements', function (Blueprint $table): void {
            $table->string('arena_showcase_scene', 16)
                ->default('normal')
                ->after('icon_set_key');
        });
    }

    public function down(): void
    {
        Schema::table('character_icon_entitlements', function (Blueprint $table): void {
            $table->dropColumn('arena_showcase_scene');
        });
    }
};
