<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stripe_orders')) {
            Schema::create('stripe_orders', function (Blueprint $table) {
                $table->id();
                $table->string('session_id')->unique();
                $table->unsignedBigInteger('character_id');
                $table->string('pack_key');
                $table->unsignedInteger('kiseki_amount');
                $table->unsignedInteger('price_jpy');
                $table->string('status')->default('pending'); // pending / fulfilled / failed
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamps();

                $table->index('character_id');
            });
        }

        // kiseki_transactions は既存テーブルを流用
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_orders');
    }
};
