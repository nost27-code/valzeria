<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('characters')) {
            return;
        }

        $afterColumn = Schema::hasColumn('characters', 'luck_fraction') ? 'luck_fraction' : 'luck_base';

        Schema::table('characters', function (Blueprint $table) use ($afterColumn) {
            if (!Schema::hasColumn('characters', 'bonus_points')) {
                $table->unsignedInteger('bonus_points')->default(0)->after($afterColumn);
            }
        });

        DB::table('characters')
            ->chunkById(100, function ($characters) {
                foreach ($characters as $character) {
                    $levelBonusPoints = max(0, (int) $character->level - 1);

                    DB::table('characters')
                        ->where('id', $character->id)
                        ->update([
                            'hp_base' => max(1, (int) round(((int) $character->hp_base) * 1.12)),
                            'mp_base' => max(0, (int) round(((int) ($character->mp_base ?? 0)) * 1.12)),
                            'attack_base' => max(1, (int) round(((int) $character->attack_base) * 1.12)),
                            'defense_base' => max(1, (int) round(((int) $character->defense_base) * 1.12)),
                            'speed_base' => max(1, (int) round(((int) $character->speed_base) * 1.12)),
                            'magic_base' => max(1, (int) round(((int) $character->magic_base) * 1.12)),
                            'spirit_base' => max(1, (int) round(((int) ($character->spirit_base ?? 0)) * 1.12)),
                            'luck_base' => max(1, (int) round(((int) $character->luck_base) * 1.12)),
                            'bonus_points' => ((int) ($character->bonus_points ?? 0)) + $levelBonusPoints,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('characters') || !Schema::hasColumn('characters', 'bonus_points')) {
            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('bonus_points');
        });
    }
};
