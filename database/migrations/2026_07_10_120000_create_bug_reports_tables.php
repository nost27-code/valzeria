<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('new');
            $table->text('reported_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('bug_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bug_report_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['bug_report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_report_attachments');
        Schema::dropIfExists('bug_reports');
    }
};
