<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_profile_frames')) {
            Schema::create('character_profile_frames', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->string('frame_theme', 40);
                $table->string('source')->default('regional_material_exchange');
                $table->timestamp('unlocked_at')->nullable();
                $table->timestamps();

                $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
                $table->unique(['character_id', 'frame_theme'], 'character_profile_frames_unique');
            });
        }

        if (!Schema::hasTable('character_profile_frame_fragments')) {
            Schema::create('character_profile_frame_fragments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->string('frame_theme', 40);
                $table->integer('quantity')->default(0);
                $table->timestamps();

                $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');
                $table->unique(['character_id', 'frame_theme'], 'character_profile_frame_fragments_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_profile_frame_fragments');
        Schema::dropIfExists('character_profile_frames');
    }
};
