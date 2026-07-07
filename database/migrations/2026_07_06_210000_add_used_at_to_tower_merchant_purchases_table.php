<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tower_merchant_purchases', function (Blueprint $table): void {
            $table->timestamp('used_at')->nullable()->after('effect_value');
            $table->index('used_at', 'tower_merchant_used_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tower_merchant_purchases', function (Blueprint $table): void {
            $table->dropIndex('tower_merchant_used_at_idx');
            $table->dropColumn('used_at');
        });
    }
};
