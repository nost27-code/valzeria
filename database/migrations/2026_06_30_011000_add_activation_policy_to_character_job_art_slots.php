<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('character_job_art_slots')) {
            return;
        }

        if (!Schema::hasColumn('character_job_art_slots', 'activation_policy')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->string('activation_policy', 20)
                    ->default('normal')
                    ->after('skill_id')
                    ->comment('奥義スロット別発動方針: aggressive / normal / conserve');
            });
        }

        if (Schema::hasTable('characters') && Schema::hasColumn('characters', 'job_art_activation_policy')) {
            $slots = DB::table('character_job_art_slots')
                ->join('characters', 'character_job_art_slots.character_id', '=', 'characters.id')
                ->select('character_job_art_slots.id', 'characters.job_art_activation_policy')
                ->get();

            foreach ($slots as $slot) {
                $policy = in_array((string) $slot->job_art_activation_policy, ['aggressive', 'normal', 'conserve'], true)
                    ? (string) $slot->job_art_activation_policy
                    : 'normal';

                DB::table('character_job_art_slots')
                    ->where('id', $slot->id)
                    ->update(['activation_policy' => $policy]);
            }
        }

        DB::table('character_job_art_slots')
            ->whereNull('activation_policy')
            ->orWhereNotIn('activation_policy', ['aggressive', 'normal', 'conserve'])
            ->update(['activation_policy' => 'normal']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('character_job_art_slots')) {
            return;
        }

        if (Schema::hasColumn('character_job_art_slots', 'activation_policy')) {
            Schema::table('character_job_art_slots', function (Blueprint $table) {
                $table->dropColumn('activation_policy');
            });
        }
    }
};
