<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_VALMON_CASE = 'images/profile/valmon_case01.webp';
    private const VALMON_CASES = [
        'images/profile/valmon_case01.webp',
        'images/profile/valmon_case02.webp',
        'images/profile/valmon_case03.webp',
        'images/profile/valmon_case04.webp',
        'images/profile/valmon_case05.webp',
        'images/profile/valmon_case06.webp',
        'images/profile/valmon_case07.webp',
    ];

    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'profile_valmon_case')) {
                $table->string('profile_valmon_case')->nullable()->after('profile_avatar_frame');
            }
        });

        DB::table('characters')
            ->whereNull('profile_valmon_case')
            ->update(['profile_valmon_case' => self::DEFAULT_VALMON_CASE]);

        if (Schema::hasTable('character_adventurer_card_assets')) {
            $now = now();
            $characterIds = DB::table('characters')->pluck('id');

            foreach ($characterIds as $characterId) {
                foreach (self::VALMON_CASES as $casePath) {
                    DB::table('character_adventurer_card_assets')->updateOrInsert(
                        [
                            'character_id' => $characterId,
                            'asset_type' => 'valmon_case',
                            'asset_path' => $casePath,
                        ],
                        [
                            'source' => 'default',
                            'obtained_at' => $now,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('character_adventurer_card_assets')) {
            DB::table('character_adventurer_card_assets')
                ->where('asset_type', 'valmon_case')
                ->delete();
        }

        Schema::table('characters', function (Blueprint $table) {
            if (Schema::hasColumn('characters', 'profile_valmon_case')) {
                $table->dropColumn('profile_valmon_case');
            }
        });
    }
};
