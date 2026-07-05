<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'support_pass_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dateTime('support_pass_expires_at')->nullable()->after('role');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'selected_card_skin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('selected_card_skin', 50)->default('default')->after('support_pass_expires_at');
            });
        }

        if (!Schema::hasTable('pass_purchase_logs')) {
            Schema::create('pass_purchase_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
                $table->string('pass_type', 50);
                $table->string('price_currency', 20);
                $table->unsignedInteger('price_amount');
                $table->dateTime('purchased_at');
                $table->dateTime('previous_expires_at')->nullable();
                $table->dateTime('new_expires_at');
                $table->timestamps();

                $table->index(['user_id', 'pass_type']);
                $table->index('purchased_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_purchase_logs');

        if (!Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'selected_card_skin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('selected_card_skin');
            });
        }

        if (Schema::hasColumn('users', 'support_pass_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('support_pass_expires_at');
            });
        }
    }
};
