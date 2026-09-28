<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_monster_marks', function (Blueprint $table): void {
            $table->unsignedInteger('spent_quantity')->default(0)->after('quantity');
        });

        Schema::create('monster_mark_refinements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
            $table->uuid('request_token');
            $table->string('stat', 16);
            $table->unsignedTinyInteger('points')->default(1);
            $table->unsignedInteger('mark_cost');
            $table->json('consumed_marks');
            $table->timestamps();

            $table->unique(['character_id', 'request_token'], 'monster_mark_refinements_character_token_unique');
            $table->index(['character_id', 'stat'], 'monster_mark_refinements_character_stat_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monster_mark_refinements');

        Schema::table('character_monster_marks', function (Blueprint $table): void {
            $table->dropColumn('spent_quantity');
        });
    }
};
