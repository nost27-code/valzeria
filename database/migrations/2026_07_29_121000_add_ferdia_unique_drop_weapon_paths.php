<?php

use Database\Seeders\DropEquipmentAdditionsSeeder;
use Database\Seeders\DropWeaponEvolutionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DropEquipmentAdditionsSeeder())->run();
        (new DropWeaponEvolutionSeeder())->run();
    }

    public function down(): void
    {
        // 入手済み・進化済みのプレイヤー装備を自動削除しない。
        // 公開後に取り消す場合は、事前バックアップからマスタを復旧する。
    }
};
