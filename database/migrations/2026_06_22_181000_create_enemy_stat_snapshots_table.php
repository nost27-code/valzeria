<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enemy_stat_snapshots')) {
            return;
        }

        Schema::create('enemy_stat_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enemy_id');
            $table->string('snapshot_key', 80);
            $table->string('enemy_name', 120);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('enemy_level')->nullable();
            $table->unsignedInteger('max_hp')->default(1);
            $table->unsignedInteger('str')->default(1);
            $table->unsignedInteger('def')->default(1);
            $table->unsignedInteger('agi')->default(1);
            $table->unsignedInteger('mag')->default(1);
            $table->unsignedInteger('spr')->default(1);
            $table->unsignedInteger('luk')->default(1);
            $table->string('stat_generation_version', 30)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['enemy_id', 'snapshot_key'], 'enemy_stat_snapshots_enemy_key_unique');
            $table->index('snapshot_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enemy_stat_snapshots');
    }
};
