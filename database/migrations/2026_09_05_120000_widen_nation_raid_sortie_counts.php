<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'nation_raid_daily_usages' => ['used_count' => 0, 'resolved_count' => 0],
        'nation_raid_participations' => ['resolved_sorties' => 0],
        'nation_raid_battle_results' => ['day_sortie_no' => null, 'event_sortie_no' => null],
        'nation_raid_battle_telemetry' => ['day_sortie_no' => 1, 'event_sortie_no' => 1],
    ];

    public function up(): void
    {
        // Lossless widening for unlimited sorties. Preserve defaults, indexes and all rows.
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $name => $default) {
                    $column = $blueprint->unsignedInteger($name);
                    if ($default !== null) {
                        $column->default($default);
                    }
                    $column->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Check every table before any DDL; never truncate an already accumulated count.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $name => $default) {
                throw_if(DB::table($table)->where($name, '>', 255)->exists(), RuntimeException::class,
                    '出撃回数を失わないよう、forward migrationで復旧してください。');
            }
        }
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $name => $default) {
                    $column = $blueprint->unsignedTinyInteger($name);
                    if ($default !== null) {
                        $column->default($default);
                    }
                    $column->change();
                }
            });
        }
    }
};
