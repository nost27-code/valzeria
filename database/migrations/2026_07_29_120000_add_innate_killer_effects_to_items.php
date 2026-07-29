<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->string('innate_killer_species_key', 32)
                ->nullable()
                ->after('affix_enabled');
            $table->decimal('innate_killer_damage_rate', 5, 4)
                ->default(0)
                ->after('innate_killer_species_key');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn([
                'innate_killer_species_key',
                'innate_killer_damage_rate',
            ]);
        });
    }
};
