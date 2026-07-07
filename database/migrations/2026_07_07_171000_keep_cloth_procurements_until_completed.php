<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLES = [
        '旅装ギルド設立準備',
        '遠征外套の試作',
        '祭礼布と護符の修繕',
        '深層調査隊の防護布',
        '空織り調査隊の装備準備',
    ];

    private const PERSISTENT_DURATION_HOURS = 87600;

    public function up(): void
    {
        if (Schema::hasTable('npc_procurement_request_templates')) {
            DB::table('npc_procurement_request_templates')
                ->whereIn('title', self::TITLES)
                ->update([
                    'duration_hours' => self::PERSISTENT_DURATION_HOURS,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('npc_procurement_requests')) {
            DB::table('npc_procurement_requests')
                ->whereIn('title', self::TITLES)
                ->where('status', 'active')
                ->update([
                    'expires_at' => now()->addHours(self::PERSISTENT_DURATION_HOURS),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('npc_procurement_request_templates')) {
            foreach ([
                '旅装ギルド設立準備' => 72,
                '遠征外套の試作' => 72,
                '祭礼布と護符の修繕' => 72,
                '深層調査隊の防護布' => 96,
                '空織り調査隊の装備準備' => 120,
            ] as $title => $durationHours) {
                DB::table('npc_procurement_request_templates')
                    ->where('title', $title)
                    ->update([
                        'duration_hours' => $durationHours,
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
