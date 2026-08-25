<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->char('idempotency_key', 36);
            $table->string('message', 100);
            $table->timestamps();

            $table->unique(['nation_id', 'idempotency_key'], 'nation_chat_messages_nation_request_uq');
            $table->index(['nation_id', 'id'], 'nation_chat_messages_nation_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_chat_messages');
    }
};
