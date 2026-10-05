<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLiteでは外側のtransaction開始前に外部キー制約を一時停止する必要がある。
    public $withinTransaction = false;

    public function up(): void
    {
        // 種類のみ拡張し、所有個体・育成・遺物・発見記録は保持する。
        $this->changeKinds(['weapon', 'armor', 'accessory']);
    }

    public function down(): void
    {
        if (DB::table('player_nameless_equipments')->where('kind', 'accessory')->exists()
            || DB::table('nameless_equipment_discoveries')->where('kind', 'accessory')->exists()) {
            throw new RuntimeException('装飾品の所有・発見記録があります。データを保持する移行が必要です。');
        }
        $this->changeKinds(['weapon', 'armor']);
    }

    private function changeKinds(array $kinds): void
    {
        $change = fn () => Schema::table('player_nameless_equipments', function (Blueprint $table) use ($kinds) {
            $table->enum('kind', $kinds)->change();
        });
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $change();
            return;
        }

        // テーブル再作成時のON DELETE SET NULLで、遺物の装着先が外れないよう保全する。
        $foreignKeys = (bool) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        try {
            Schema::disableForeignKeyConstraints();
            if (DB::selectOne('PRAGMA foreign_keys')->foreign_keys) {
                throw new RuntimeException('装着状態を保全するため、このSQLite migrationはtransaction外で実行してください。');
            }
            DB::transaction($change);
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
