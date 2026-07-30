<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_icon_design_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('permit_granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->unsignedSmallInteger('price_kiseki')->default(40);
            $table->unsignedSmallInteger('free_kiseki_spent')->default(0);
            $table->unsignedSmallInteger('paid_kiseki_spent')->default(0);
            $table->json('form_data')->nullable();
            $table->timestamp('permit_granted_at')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });

        Schema::create('character_icon_design_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_icon_design_request_id')
                ->constrained('character_icon_design_requests', indexName: 'icon_design_messages_request_fk')
                ->cascadeOnDelete();
            $table->string('sender_type', 20);
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('read_by_player_at')->nullable();
            $table->timestamp('read_by_admin_at')->nullable();
            $table->timestamps();

            $table->index(
                ['character_icon_design_request_id', 'sender_type', 'read_by_admin_at'],
                'icon_design_messages_admin_unread_idx'
            );
        });

        Schema::create('character_icon_design_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_icon_design_message_id')
                ->constrained('character_icon_design_messages', indexName: 'icon_design_attachments_message_fk')
                ->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_icon_design_message_attachments');
        Schema::dropIfExists('character_icon_design_messages');
        Schema::dropIfExists('character_icon_design_requests');
    }
};
