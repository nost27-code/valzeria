<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('characters') || Schema::hasColumn('characters', 'experience_talisman_wins_remaining')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedInteger('experience_talisman_wins_remaining')
                ->default(0)
                ->after('explore_stamina_updated_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('characters') || ! Schema::hasColumn('characters', 'experience_talisman_wins_remaining')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('experience_talisman_wins_remaining');
        });
    }
};
