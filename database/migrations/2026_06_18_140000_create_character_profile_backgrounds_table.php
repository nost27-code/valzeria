<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_profile_backgrounds')) {
            Schema::create('character_profile_backgrounds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_id')->constrained()->cascadeOnDelete();
                $table->string('background_path', 120);
                $table->string('source', 40)->default('default');
                $table->timestamp('obtained_at')->nullable();
                $table->timestamps();

                $table->unique(['character_id', 'background_path'], 'character_profile_background_unique');
            });
        }

        if (Schema::hasTable('characters')) {
            $now = now();
            $rows = DB::table('characters')
                ->select('id')
                ->get()
                ->map(fn ($character) => [
                    'character_id' => $character->id,
                    'background_path' => 'images/valmon/ranch_bg.webp',
                    'source' => 'default',
                    'obtained_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_profile_backgrounds')->upsert(
                    $chunk,
                    ['character_id', 'background_path'],
                    ['updated_at']
                );
            }

            DB::table('characters')
                ->where(function ($query) {
                    $query->whereNull('profile_ranch_background')
                        ->orWhere('profile_ranch_background', '')
                        ->orWhere('profile_ranch_background', '!=', 'images/valmon/ranch_bg.webp');
                })
                ->update(['profile_ranch_background' => 'images/valmon/ranch_bg.webp']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_profile_backgrounds');
    }
};
