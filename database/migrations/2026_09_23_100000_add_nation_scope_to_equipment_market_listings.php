<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_market_listings', function (Blueprint $table): void {
            $table->unsignedBigInteger('nation_id_snapshot')->nullable()->after('recipient_character_id');
            $table->index(['nation_id_snapshot', 'status', 'expires_at'], 'equipment_market_nation_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_market_listings', function (Blueprint $table): void {
            $table->dropIndex('equipment_market_nation_status_expiry_idx');
            $table->dropColumn('nation_id_snapshot');
        });
    }
};
