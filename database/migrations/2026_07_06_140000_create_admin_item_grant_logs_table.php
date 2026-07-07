<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_item_grant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('grant_type', 40);
            $table->string('target_type', 40);
            $table->string('target_id', 80);
            $table->string('target_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('enhance_level')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['character_id', 'created_at'], 'admin_item_grant_logs_character_created_idx');
            $table->index(['admin_user_id', 'created_at'], 'admin_item_grant_logs_admin_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_item_grant_logs');
    }
};
