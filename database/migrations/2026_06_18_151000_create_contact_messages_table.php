<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('recipient_email', 255)->default('info@valzeria.com');
            $table->string('sender_name', 100)->nullable();
            $table->string('sender_email', 255);
            $table->string('category', 50)->default('general');
            $table->string('subject', 160);
            $table->text('body');
            $table->string('status', 30)->default('new');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('sender_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
