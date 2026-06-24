<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_notifications')) {
            return;
        }

        Schema::create('character_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('system');
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['character_id', 'read_at', 'created_at'], 'character_notifications_unread_idx');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_notifications');
    }
};
