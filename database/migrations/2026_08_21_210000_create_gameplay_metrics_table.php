<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gameplay_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type', 40);
            $table->string('context', 40);
            $table->string('result', 40)->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['metric_type', 'created_at']);
            $table->index(['metric_type', 'context', 'created_at']);
            $table->index(['character_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameplay_metrics');
    }
};
