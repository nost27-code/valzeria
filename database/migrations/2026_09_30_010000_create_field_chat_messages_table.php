<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_chat_messages')) {
            return;
        }

        Schema::create('field_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('plane', 16);
            $table->unsignedInteger('x');
            $table->unsignedInteger('y');
            $table->string('body', 120);
            $table->string('zone', 40)->nullable();
            $table->timestamps();

            $table->index(['plane', 'created_at']);
            $table->index(['zone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_chat_messages');
    }
};
