<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('top_page_visits')) {
            Schema::create('top_page_visits', function (Blueprint $table) {
                $table->id();
                $table->uuid('visit_uuid')->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('visited_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->string('referer', 1000)->nullable();
                $table->string('referer_host', 255)->nullable();
                $table->string('landing_url', 1000)->nullable();
                $table->string('utm_source', 120)->nullable();
                $table->string('utm_medium', 120)->nullable();
                $table->string('utm_campaign', 180)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('device_type', 30)->default('unknown');
                $table->string('ip_hash', 64)->nullable();
                $table->timestamps();

                $table->index(['visited_at']);
                $table->index(['referer_host', 'visited_at']);
                $table->index(['device_type', 'visited_at']);
            });
        }

        if (!Schema::hasTable('top_page_events')) {
            Schema::create('top_page_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('visit_uuid')->nullable();
                $table->foreignId('top_page_visit_id')->nullable()->constrained('top_page_visits')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_name', 80);
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index(['event_name', 'occurred_at']);
                $table->index(['visit_uuid', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('top_page_events');
        Schema::dropIfExists('top_page_visits');
    }
};
