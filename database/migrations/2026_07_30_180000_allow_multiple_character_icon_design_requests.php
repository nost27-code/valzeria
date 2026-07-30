<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_icon_design_requests', function (Blueprint $table): void {
            $table->index('character_id', 'icon_design_requests_character_idx');
        });

        Schema::table('character_icon_design_requests', function (Blueprint $table): void {
            $table->dropUnique('character_icon_design_requests_character_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('character_icon_design_requests', function (Blueprint $table): void {
            $table->unique('character_id', 'character_icon_design_requests_character_id_unique');
        });

        Schema::table('character_icon_design_requests', function (Blueprint $table): void {
            $table->dropIndex('icon_design_requests_character_idx');
        });
    }
};
