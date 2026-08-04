<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRIAL_AREA_ID = 84;

    public function up(): void
    {
        if (! Schema::hasTable('areas')) {
            return;
        }

        DB::table('areas')
            ->where('id', self::TRIAL_AREA_ID)
            ->update([
                'description' => '【試練場】 職業を問わず挑める、剣相と術相の二形態連戦。',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('areas')) {
            return;
        }

        DB::table('areas')
            ->where('id', self::TRIAL_AREA_ID)
            ->update([
                'description' => '【試練場】 剣冠騎士だけが挑める、剣相と術相の二形態連戦。',
                'updated_at' => now(),
            ]);
    }
};
