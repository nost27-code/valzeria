<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stripe_payment_audits')) {
            return;
        }

        Schema::create('stripe_payment_audits', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->nullable()->unique();
            $table->string('event_type', 80);
            $table->string('stripe_session_id')->nullable()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_charge_id')->nullable()->index();
            $table->foreignId('stripe_order_id')->nullable()->constrained('stripe_orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack_key')->nullable();
            $table->string('product_name')->nullable();
            $table->unsignedInteger('price_jpy')->nullable();
            $table->unsignedInteger('kiseki_amount')->nullable();
            $table->string('status', 30)->default('received');
            $table->string('idempotency_key')->nullable()->index();
            $table->timestamp('webhook_received_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['character_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payment_audits');
    }
};
