<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_message_replies')) {
            return;
        }

        Schema::create('contact_message_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_message_id')->constrained('contact_messages')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_email', 255);
            $table->string('to_email', 255);
            $table->string('subject', 160);
            $table->text('body');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['contact_message_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_message_replies');
    }
};
