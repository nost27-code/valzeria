<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $columns = [
        'hp_bonus',
        'mp_bonus',
        'str_bonus',
        'def_bonus',
        'agi_bonus',
        'mag_bonus',
        'spr_bonus',
        'luk_bonus',
    ];

    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        $updates = [];
        foreach ($this->columns as $column) {
            // SQLiteにはCEILがないため整数演算で切り上げ（x*11を10で整数除算切り上げ）
            $ceil = $isSqlite
                ? "({$column} * 11 + 9) / 10"
                : "CEIL({$column} * 1.1)";
            $updates[$column] = DB::raw("CASE WHEN {$column} > 0 THEN {$ceil} ELSE {$column} END");
        }
        $updates['updated_at'] = now();

        DB::table('items')
            ->whereIn('type', ['weapon', 'armor', 'accessory'])
            ->update($updates);
    }

    public function down(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        $updates = [];
        foreach ($this->columns as $column) {
            // SQLiteにはFLOORがないため整数演算で切り捨て（x*10を11で整数除算）
            $floor = $isSqlite
                ? "({$column} * 10) / 11"
                : "FLOOR({$column} / 1.1)";
            $updates[$column] = DB::raw("CASE WHEN {$column} > 0 THEN {$floor} ELSE {$column} END");
        }
        $updates['updated_at'] = now();

        DB::table('items')
            ->whereIn('type', ['weapon', 'armor', 'accessory'])
            ->update($updates);
    }
};
