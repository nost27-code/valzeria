<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_items', function (Blueprint $table) {
            if (!Schema::hasColumn('character_items', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('is_stored');
            }
            if (!Schema::hasColumn('character_items', 'enhance_level')) {
                $table->unsignedSmallInteger('enhance_level')->default(0)->after('is_locked');
            }
        });

        if (!Schema::hasTable('equipment_evolution_logs')) {
            Schema::create('equipment_evolution_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('recipe_type');
                $table->string('recipe_id');
                $table->foreignId('before_equipment_id')->constrained('items')->cascadeOnDelete();
                $table->foreignId('after_equipment_id')->constrained('items')->cascadeOnDelete();
                $table->unsignedInteger('consumed_equipment_count')->default(0);
                $table->json('consumed_materials')->nullable();
                $table->foreignId('created_equipment_instance_id')->nullable()->constrained('character_items')->nullOnDelete();
                $table->timestamps();

                $table->index(['character_id', 'recipe_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_evolution_logs');

        Schema::table('character_items', function (Blueprint $table) {
            if (Schema::hasColumn('character_items', 'enhance_level')) {
                $table->dropColumn('enhance_level');
            }
            if (Schema::hasColumn('character_items', 'is_locked')) {
                $table->dropColumn('is_locked');
            }
        });
    }
};
