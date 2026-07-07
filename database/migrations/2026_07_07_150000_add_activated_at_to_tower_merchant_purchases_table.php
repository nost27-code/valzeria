<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tower_merchant_purchases')) {
            return;
        }

        Schema::table('tower_merchant_purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('tower_merchant_purchases', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->after('effect_value');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tower_merchant_purchases')) {
            return;
        }

        Schema::table('tower_merchant_purchases', function (Blueprint $table): void {
            if (Schema::hasColumn('tower_merchant_purchases', 'activated_at')) {
                $table->dropColumn('activated_at');
            }
        });
    }
};
