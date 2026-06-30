<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('external_feed_items')) {
            return;
        }

        Schema::create('external_feed_items', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->char('guid_hash', 64);
            $table->text('guid');
            $table->string('title');
            $table->string('url');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['source', 'guid_hash'], 'external_feed_items_source_guid_unique');
            $table->index(['source', 'published_at'], 'external_feed_items_source_published_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_feed_items');
    }
};
