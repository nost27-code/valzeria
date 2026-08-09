<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('character_job_art_slots')
            || ! Schema::hasColumn('character_job_art_slots', 'battle_context')
        ) {
            return;
        }

        $hasActivationPolicy = Schema::hasColumn('character_job_art_slots', 'activation_policy');
        $bossSlots = DB::table('character_job_art_slots')
            ->where('battle_context', 'boss')
            ->orderBy('id')
            ->get();

        foreach ($bossSlots as $slot) {
            $pvpSlots = DB::table('character_job_art_slots')
                ->where('character_id', $slot->character_id)
                ->where('battle_context', 'pvp');

            $conflictsWithExistingPvpSlot = (clone $pvpSlots)
                ->where('slot_no', $slot->slot_no)
                ->exists();
            $conflictsWithExistingPvpSkill = (clone $pvpSlots)
                ->where('skill_id', $slot->skill_id)
                ->exists();

            if ($conflictsWithExistingPvpSlot || $conflictsWithExistingPvpSkill) {
                continue;
            }

            $payload = [
                'character_id' => $slot->character_id,
                'battle_context' => 'pvp',
                'slot_no' => $slot->slot_no,
                'skill_id' => $slot->skill_id,
                'created_at' => $slot->created_at,
                'updated_at' => $slot->updated_at,
            ];

            if ($hasActivationPolicy) {
                $payload['activation_policy'] = $slot->activation_policy;
            }

            DB::table('character_job_art_slots')->insert($payload);
        }
    }

    public function down(): void
    {
        // Player-owned PvP loadouts are intentionally retained on rollback.
    }
};
