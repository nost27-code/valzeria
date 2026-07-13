<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equipment_market_listings')) {
            return;
        }

        Schema::table('equipment_market_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('body_appraisal_price')->nullable()->after('appraisal_price');
            $table->unsignedBigInteger('trait_appraisal_price')->nullable()->after('body_appraisal_price');
            $table->unsignedTinyInteger('appraisal_version')->default(1)->after('maximum_price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equipment_market_listings')) {
            return;
        }

        Schema::table('equipment_market_listings', function (Blueprint $table) {
            $table->dropColumn(['body_appraisal_price', 'trait_appraisal_price', 'appraisal_version']);
        });
    }
};
