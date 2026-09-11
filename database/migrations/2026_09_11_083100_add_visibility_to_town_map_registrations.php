<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('town_map_registrations', function (Blueprint $table): void {
            $table->string('visibility_scope', 16)->default('all')->after('entry_fee_changed_at');
            $table->foreignId('nation_id_snapshot')
                ->nullable()
                ->after('visibility_scope')
                ->constrained('nations')
                ->nullOnDelete();
            $table->index(['visibility_scope', 'nation_id_snapshot'], 'map_registration_visibility_idx');
        });
    }

    public function down(): void
    {
        Schema::table('town_map_registrations', function (Blueprint $table): void {
            $table->dropIndex('map_registration_visibility_idx');
            $table->dropConstrainedForeignId('nation_id_snapshot');
            $table->dropColumn('visibility_scope');
        });
    }
};
