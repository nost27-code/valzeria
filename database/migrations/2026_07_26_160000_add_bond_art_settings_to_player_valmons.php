<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('player_valmons')) {
            return;
        }

        Schema::table('player_valmons', function (Blueprint $table) {
            if (! Schema::hasColumn('player_valmons', 'bond_style')) {
                $table->string('bond_style', 20)->default('balanced');
            }
            if (! Schema::hasColumn('player_valmons', 'bond_phrase_style')) {
                $table->string('bond_phrase_style', 20)->default('trust');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('player_valmons')) {
            return;
        }

        Schema::table('player_valmons', function (Blueprint $table) {
            if (Schema::hasColumn('player_valmons', 'bond_phrase_style')) {
                $table->dropColumn('bond_phrase_style');
            }
            if (Schema::hasColumn('player_valmons', 'bond_style')) {
                $table->dropColumn('bond_style');
            }
        });
    }
};
