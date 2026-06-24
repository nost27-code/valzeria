<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('champ_states')) {
            return;
        }

        Schema::table('champ_states', function (Blueprint $table) {
            if (!Schema::hasColumn('champ_states', 'current_mp')) {
                $table->bigInteger('current_mp')->default(0)->after('max_hp');
            }
            if (!Schema::hasColumn('champ_states', 'max_mp')) {
                $table->bigInteger('max_mp')->default(0)->after('current_mp');
            }
        });

        $champ = DB::table('champ_states')->first();
        if (!$champ || !$champ->character_id) {
            return;
        }

        $character = \App\Models\Character::find($champ->character_id);
        if (!$character) {
            return;
        }

        $stats = app(\App\Services\CharacterStatusService::class)->getFinalStats($character);
        DB::table('champ_states')->where('id', $champ->id)->update([
            'current_mp' => (int) ($stats['max_mp'] ?? 0),
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('champ_states')) {
            return;
        }

        Schema::table('champ_states', function (Blueprint $table) {
            if (Schema::hasColumn('champ_states', 'max_mp')) {
                $table->dropColumn('max_mp');
            }
            if (Schema::hasColumn('champ_states', 'current_mp')) {
                $table->dropColumn('current_mp');
            }
        });
    }
};
